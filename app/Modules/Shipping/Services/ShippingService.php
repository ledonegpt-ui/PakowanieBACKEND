<?php
declare(strict_types=1);

final class ShippingService
{
    private $repo;
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
    public function generateLabel(string $orderCode, array $currentSession, string $sizeParam = ''): array
    {
        // znajdź sesję
        $packingSession = $this->repo->findSessionByOrderCode($orderCode);
        if (!$packingSession || $packingSession['status'] !== 'open') {
            throw new RuntimeException('No open packing session for order: ' . $orderCode);
        }
        if ((int)$packingSession['user_id'] !== (int)$currentSession['user_id']) {
            throw new RuntimeException('Session does not belong to current operator');
        }

        $sessionId = (int)$packingSession['id'];

        // znajdź zamówienie
        $order = $this->repo->findOrder($orderCode);
        if (!$order) {
            throw new RuntimeException('Order not found: ' . $orderCode);
        }

        // resolve shipping
        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $mapCfg   = require BASE_PATH . '/app/Config/shipping_map.php';
        $resolver = new ShippingMethodResolver($mapCfg);
        $resolved = $resolver->resolve([
            'delivery_method' => (string)($order['delivery_method'] ?? ''),
            'carrier_code'    => (string)($order['carrier_code']    ?? ''),
            'courier_code'    => (string)($order['courier_code']    ?? ''),
        ]);

        if (empty($resolved['label_provider'])) {
            throw new RuntimeException('Cannot determine label provider for this order');
        }

        $labelProvider = (string)$resolved['label_provider'];
        $requiresSize  = (bool)($resolved['requires_size'] ?? false);

        // paczkomat — wymaga rozmiaru
        if ($requiresSize && $sizeParam === '') {
            return [
                'requires_size' => true,
                'size_options'  => ['A', 'B', 'C'],
            ];
        }

        if ($sizeParam !== '') {
            $resolved['package_size'] = strtoupper($sizeParam);
        }

        // znajdź lub utwórz package
        $package = $this->repo->findPackageBySession($sessionId);
        if (!$package) {
            $provider  = $this->repo->findProviderByCode($this->resolveProviderCode($labelProvider));
            $packageId = $this->repo->createPackage(
                $sessionId,
                1,
                $provider ? (int)$provider['id'] : null,
                (string)($resolved['service_code'] ?? $labelProvider)
            );
            $package = $this->repo->findPackageBySession($sessionId);
        }

        // etykieta już istnieje — zwróć cache
        $existingLabel = $this->repo->findLabelByPackage((int)$package['id']);
        if ($existingLabel) {
            return [
                'order_code'      => $orderCode,
                'tracking_number' => $package['tracking_number'],
                'label_format'    => $existingLabel['label_format'],
                'label_status'    => $existingLabel['label_status'],
                'file_token'      => $existingLabel['file_token'],
                'file_path'       => $existingLabel['file_path'],
                'source'          => 'cached',
            ];
        }

        // pobierz config providera
        $provider    = $this->repo->findProviderByCode($this->resolveProviderCode($labelProvider));
        $providerCfg = $provider ? json_decode((string)($provider['config_json'] ?? '{}'), true) : [];

        // wywołaj adapter
        require_once BASE_PATH . '/app/Modules/Shipping/ShippingAdapterFactory.php';
        $adapter = ShippingAdapterFactory::make($labelProvider, $this->cfg);
        $result  = $adapter->generateLabel($order, $package, $resolved, $providerCfg ?: []);

        // zapisz wynik
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
            $result['file_path']   ?? null,
            $result['file_token']  ?? null,
            json_encode($result['raw_response'] ?? [], JSON_UNESCAPED_UNICODE)
        );

        // drukuj na Zebrze
        require_once BASE_PATH . '/app/Support/ZebraPrinter.php';
        $stationCode = (string)($currentSession['station_code'] ?? '');
        if ($stationCode !== '' && !empty($result['file_path'])) {
            $fullPath = BASE_PATH . '/storage/labels/' . $result['file_path'];
            if (file_exists($fullPath)) {
                try {
                    ZebraPrinter::print($stationCode, $fullPath);
                } catch (Throwable $printErr) {
                    error_log('ZebraPrinter error: ' . $printErr->getMessage());
                }
            }
        }

        $this->repo->logEvent(
            $sessionId, 'label_generated',
            'Label generated via ' . $labelProvider,
            [
                'order_code'      => $orderCode,
                'label_provider'  => $labelProvider,
                'tracking_number' => $result['tracking_number'],
                'user_id'         => (int)$currentSession['user_id'],
            ],
            (int)$currentSession['user_id']
        );

        return [
            'order_code'      => $orderCode,
            'tracking_number' => $result['tracking_number'],
            'label_format'    => $result['label_format']  ?? 'pdf',
            'label_status'    => $result['label_status']  ?? 'ok',
            'file_token'      => $result['file_token']    ?? null,
            'file_path'       => $result['file_path']     ?? null,
            'source'          => 'generated',
        ];
    }

    private function resolveProviderCode(string $labelProvider): string
    {
        $map = [
            'dpd_api'       => 'dpd_contract',
            'dpd_contract'  => 'dpd_contract',
            'gls_api'       => 'gls',
            'inpost_shipx'  => 'inpost_shipx',
            'inpost_api'    => 'inpost_shipx',
            'allegro_api'   => 'allegro',
            'baselinker_api'=> 'baselinker',
            'baselinker'    => 'baselinker',
        ];
        return $map[$labelProvider] ?? $labelProvider;
    }
}
