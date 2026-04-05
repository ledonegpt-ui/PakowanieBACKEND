<?php
declare(strict_types=1);

final class ShippingService
{
    /** @var PackingRepository */
    private $repo;

    /** @var array */
    private $cfg;

    public function __construct(PackingRepository $repo, array $cfg)
    {
        $this->repo = $repo;
        $this->cfg  = $cfg;
    }

    /**
     * Generuje etykietę dla zamówienia.
     * Zwraca tablicę z wynikiem lub rzuca wyjątek.
     * NIE wysyła HTTP response — to robi kontroler.
     *
     * @throws RuntimeException
     */
    public function generateLabel(
        string $orderCode,
        array $currentSession,
        string $sizeParam = '',
        bool $print = true,
        bool $panelMode = false
    ): array {
        $orderCode = trim($orderCode);
        if ($orderCode === '') {
            throw new RuntimeException('Missing orderCode');
        }

        $sizeParam = strtoupper(trim($sizeParam));
        if ($sizeParam !== '' && !in_array($sizeParam, ['A', 'B', 'C'], true)) {
            throw new RuntimeException('Invalid package size. Allowed values: A, B, C');
        }

        $packingSession = $this->repo->findSessionByOrderCode($orderCode);
        if (!$packingSession) {
            if ($panelMode) {
                throw new RuntimeException('Brak sesji pakowania dla zamówienia. W tej wersji panel może wygenerować etykietę tylko dla zamówienia, które ma już sesję pakowania.');
            }
            throw new RuntimeException('No open packing session for order: ' . $orderCode);
        }

        if (!$panelMode) {
            if (($packingSession['status'] ?? '') !== 'open') {
                throw new RuntimeException('No open packing session for order: ' . $orderCode);
            }
            if ((int)($packingSession['user_id'] ?? 0) !== (int)($currentSession['user_id'] ?? 0)) {
                throw new RuntimeException('Session does not belong to current operator');
            }
        } else {
            $sessionStatus = (string)($packingSession['status'] ?? '');
            if (!in_array($sessionStatus, ['open', 'paused', 'completed'], true)) {
                throw new RuntimeException('Sesja pakowania ma status, który nie pozwala na użycie panelowego generate-label: ' . $sessionStatus);
            }
        }

        $sessionId = (int)$packingSession['id'];

        $order = $this->repo->findOrder($orderCode);
        if (!$order) {
            throw new RuntimeException('Order not found: ' . $orderCode);
        }

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $mapCfg   = require BASE_PATH . '/app/Config/shipping_map.php';
        $resolver = new ShippingMethodResolver($mapCfg);
        $resolved = $resolver->resolve([
            'delivery_method' => (string)($order['delivery_method'] ?? ''),
            'carrier_code'    => (string)($order['carrier_code'] ?? ''),
            'courier_code'    => (string)($order['courier_code'] ?? ''),
        ]);

        if (empty($resolved['label_provider'])) {
            throw new RuntimeException('Cannot determine label provider for this order');
        }

        $labelProvider = (string)$resolved['label_provider'];
        $requiresSize  = (bool)($resolved['requires_size'] ?? false);

        if ($requiresSize && $sizeParam === '') {
            return [
                'order_code'    => $orderCode,
                'status'        => 'requires_size',
                'retry_allowed' => true,
                'requires_size' => true,
                'size_options'  => ['A', 'B', 'C'],
                'message'       => 'Podaj rozmiar paczki: A / B / C',
            ];
        }

        if ($sizeParam !== '') {
            $resolved['package_size'] = $sizeParam;
        }

        $package = $this->repo->findPackageBySession($sessionId);
        if (!$package) {
            $provider = $this->repo->findProviderByCode($this->resolveProviderCode($labelProvider));
            $this->repo->createPackage(
                $sessionId,
                1,
                $provider ? (int)$provider['id'] : null,
                (string)($resolved['service_code'] ?? $labelProvider)
            );
            $package = $this->repo->findPackageBySession($sessionId);
        }

        if (!$package || empty($package['id'])) {
            throw new RuntimeException('Failed to prepare package for label generation');
        }

        if ($sizeParam !== '') {
            $this->repo->updatePackageSizeCode((int)$package['id'], $sizeParam);
            $package['package_size_code'] = $sizeParam;
        }

        $existingLabel = $this->repo->findLabelByPackage((int)$package['id']);
        if ($existingLabel) {
            return [
                'order_code'      => $orderCode,
                'tracking_number' => $package['tracking_number'] ?? null,
                'label_format'    => $existingLabel['label_format'],
                'label_status'    => $existingLabel['label_status'],
                'file_token'      => $existingLabel['file_token'],
                'file_path'       => $existingLabel['file_path'],
                'source'          => 'cached',
            ];
        }

        $debugMode = (bool)(int)($_ENV['LABEL_DEBUG_MODE'] ?? getenv('LABEL_DEBUG_MODE') ?? 0);
        if ($debugMode) {
            $fakeTracking = 'DEBUG-' . strtoupper($labelProvider) . '-' . date('ymdHis') . '-' . substr($orderCode, -6);
            $fakeToken    = 'debug_' . md5($orderCode . time());

            $this->repo->updatePackageLabel(
                (int)$package['id'],
                $fakeTracking,
                null,
                'ok'
            );

            $this->repo->createLabel(
                (int)$package['id'],
                'debug',
                'ok',
                null,
                $fakeToken,
                json_encode(['debug' => true, 'provider' => $labelProvider], JSON_UNESCAPED_UNICODE)
            );

            $this->repo->logEvent(
                $sessionId,
                'label_generated',
                '[DEBUG] Fake label for ' . $labelProvider,
                [
                    'order_code'      => $orderCode,
                    'label_provider'  => $labelProvider,
                    'tracking_number' => $fakeTracking,
                    'debug_mode'      => true,
                    'print_requested' => $print,
                    'user_id'         => (int)($currentSession['user_id'] ?? 0),
                ],
                (int)($currentSession['user_id'] ?? 0)
            );

            return [
                'order_code'      => $orderCode,
                'tracking_number' => $fakeTracking,
                'label_format'    => 'debug',
                'label_status'    => 'ok',
                'file_token'      => $fakeToken,
                'file_path'       => null,
                'source'          => 'debug',
            ];
        }

        $provider    = $this->repo->findProviderByCode($this->resolveProviderCode($labelProvider));
        $providerCfg = $provider ? json_decode((string)($provider['config_json'] ?? '{}'), true) : [];

        require_once BASE_PATH . '/app/Modules/Shipping/ShippingAdapterFactory.php';
        $adapter = ShippingAdapterFactory::make($labelProvider, $this->cfg);
        $result  = $adapter->generateLabel($order, $package, $resolved, $providerCfg ?: []);

        $this->repo->updatePackageLabel(
            (int)$package['id'],
            (string)$result['tracking_number'],
            $result['external_shipment_id'] ?? null,
            (string)($result['label_status'] ?? 'ok')
        );

        $this->repo->createLabel(
            (int)$package['id'],
            (string)($result['label_format'] ?? 'pdf'),
            (string)($result['label_status'] ?? 'ok'),
            $result['file_path'] ?? null,
            $result['file_token'] ?? null,
            json_encode($result['raw_response'] ?? [], JSON_UNESCAPED_UNICODE)
        );

        if ($print) {
            $this->printLabelIfPossible($currentSession, $result);
        }

        $this->repo->logEvent(
            $sessionId,
            'label_generated',
            'Label generated via ' . $labelProvider,
            [
                'order_code'      => $orderCode,
                'label_provider'  => $labelProvider,
                'tracking_number' => $result['tracking_number'],
                'print_requested' => $print,
                'user_id'         => (int)($currentSession['user_id'] ?? 0),
            ],
            (int)($currentSession['user_id'] ?? 0)
        );

        return [
            'order_code'      => $orderCode,
            'tracking_number' => $result['tracking_number'],
            'label_format'    => $result['label_format'] ?? 'pdf',
            'label_status'    => $result['label_status'] ?? 'ok',
            'file_token'      => $result['file_token'] ?? null,
            'file_path'       => $result['file_path'] ?? null,
            'source'          => 'generated',
        ];
    }

    private function printLabelIfPossible(array $currentSession, array $result): void
    {
        require_once BASE_PATH . '/app/Support/ZebraPrinter.php';

        $stationCode = (string)($currentSession['station_code'] ?? '');
        if ($stationCode === '' || empty($result['file_path'])) {
            return;
        }

        $fullPath = BASE_PATH . '/storage/labels/' . ltrim((string)$result['file_path'], '/');
        if (!file_exists($fullPath)) {
            return;
        }

        try {
            ZebraPrinter::print($stationCode, $fullPath);
        } catch (Throwable $printErr) {
            error_log('ZebraPrinter error: ' . $printErr->getMessage());
        }
    }

    private function resolveProviderCode(string $labelProvider): string
    {
        $map = [
            'dpd_api'        => 'dpd_contract',
            'dpd_contract'   => 'dpd_contract',
            'gls_api'        => 'gls',
            'inpost_shipx'   => 'inpost_shipx',
            'inpost_api'     => 'inpost_shipx',
            'allegro_api'    => 'allegro',
            'baselinker_api' => 'baselinker',
            'baselinker'     => 'baselinker',
        ];
        return $map[$labelProvider] ?? $labelProvider;
    }
}
