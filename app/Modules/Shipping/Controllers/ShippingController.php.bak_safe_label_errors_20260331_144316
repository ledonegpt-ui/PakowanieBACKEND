<?php
declare(strict_types=1);

final class ShippingController
{
    private function bootPacking(): PackingRepository
    {
        global $cfg;
        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Packing/Repositories/PackingRepository.php';
        return new PackingRepository(Db::mysql($cfg));
    }

    public function generateLabel(array $params = []): void
    {
        global $currentSession, $cfg;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            if ($orderCode === '') {
                ApiResponse::error('Missing orderCode', 400);
                return;
            }

            $body        = Request::jsonBody();
            $sizeParam   = (string)($body['size'] ?? '');   // A / B / C lub puste

            $repo = $this->bootPacking();

            // znajdź sesję
            $packingSession = $repo->findSessionByOrderCode($orderCode);
            if (!$packingSession || $packingSession['status'] !== 'open') {
                ApiResponse::error('No open packing session for order: ' . $orderCode, 400);
                return;
            }
            if ((int)$packingSession['user_id'] !== (int)$currentSession['user_id']) {
                ApiResponse::error('Session does not belong to current operator', 403);
                return;
            }

            $sessionId = (int)$packingSession['id'];

            // znajdź zamówienie
            $order = $repo->findOrder($orderCode);
            if (!$order) {
                ApiResponse::error('Order not found: ' . $orderCode, 400);
                return;
            }

            // resolve shipping
            require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
            $mapCfg   = require BASE_PATH . '/app/Config/shipping_map.php';
            $resolver = new ShippingMethodResolver($mapCfg);
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($order['delivery_method'] ?? ''),
                'carrier_code'    => (string)($order['carrier_code'] ?? ''),
                'courier_code'    => (string)($order['courier_code'] ?? ''),
            ]);

            if (empty($resolved['label_provider'])) {
                ApiResponse::error('Cannot determine label provider for this order', 400);
                return;
            }

            $labelProvider  = (string)$resolved['label_provider'];
            $requiresSize   = (bool)($resolved['requires_size'] ?? false);

            // paczkomat InPost wymaga rozmiaru — jeśli nie podano, zwróć żądanie
            if ($requiresSize && $sizeParam === '') {
                ApiResponse::ok([
                    'shipping' => [
                        'order_code'    => $orderCode,
                        'requires_size' => true,
                        'size_options'  => ['A', 'B', 'C'],
                        'message'       => 'Podaj rozmiar paczki: A / B / C',
                    ]
                ]);
                return;
            }

            // przekaż rozmiar do resolved żeby adapter go użył
            if ($sizeParam !== '') {
                $resolved['package_size'] = strtoupper($sizeParam);
            }

            // znajdź lub utwórz package
            $package = $repo->findPackageBySession($sessionId);
            if (!$package) {
                $provider = $repo->findProviderByCode($this->resolveProviderCode($labelProvider));
                $packageId = $repo->createPackage(
                    $sessionId,
                    1,
                    $provider ? (int)$provider['id'] : null,
                    (string)($resolved['service_code'] ?? $labelProvider)
                );
                $package = $repo->findPackageBySession($sessionId);
            }

            // sprawdź czy etykieta już istnieje
            $existingLabel = $repo->findLabelByPackage((int)$package['id']);
            if ($existingLabel) {
                ApiResponse::ok([
                    'shipping' => [
                        'order_code'      => $orderCode,
                        'tracking_number' => $package['tracking_number'],
                        'label_format'    => $existingLabel['label_format'],
                        'label_status'    => $existingLabel['label_status'],
                        'file_token'      => $existingLabel['file_token'],
                        'source'          => 'cached',
                    ]
                ]);
                return;
            }

            // ----------------------------------------------------------------
            // TRYB DEBUG — ustawić LABEL_DEBUG_MODE=1 w .env
            // Nie wywołuje żadnego adaptera ani drukarki.
            // Zapisuje do bazy fałszywą etykietę i zwraca sukces.
            // ----------------------------------------------------------------
            $debugMode = (bool)(int)($_ENV['LABEL_DEBUG_MODE'] ?? getenv('LABEL_DEBUG_MODE') ?? 0);

            if ($debugMode) {
                $fakeTracking = 'DEBUG-' . strtoupper($labelProvider) . '-' . date('ymdHis') . '-' . substr($orderCode, -6);
                $fakeToken    = 'debug_' . md5($orderCode . time());

                $repo->updatePackageLabel(
                    (int)$package['id'],
                    $fakeTracking,
                    null,
                    'ok'
                );

                $repo->createLabel(
                    (int)$package['id'],
                    'debug',
                    'ok',
                    null,
                    $fakeToken,
                    json_encode(['debug' => true, 'provider' => $labelProvider], JSON_UNESCAPED_UNICODE)
                );

                $repo->logEvent(
                    $sessionId, 'label_generated',
                    '[DEBUG] Fake label for ' . $labelProvider,
                    [
                        'order_code'      => $orderCode,
                        'label_provider'  => $labelProvider,
                        'tracking_number' => $fakeTracking,
                        'debug_mode'      => true,
                        'user_id'         => (int)$currentSession['user_id'],
                    ],
                    (int)$currentSession['user_id']
                );

                ApiResponse::ok([
                    'shipping' => [
                        'order_code'      => $orderCode,
                        'tracking_number' => $fakeTracking,
                        'label_format'    => 'debug',
                        'label_status'    => 'ok',
                        'file_token'      => $fakeToken,
                        'source'          => 'debug',
                    ]
                ]);
                return;
            }

            // pobierz config providera
            $provider    = $repo->findProviderByCode($this->resolveProviderCode($labelProvider));
            $providerCfg = $provider ? json_decode((string)($provider['config_json'] ?? '{}'), true) : [];

            // wywołaj adapter
            require_once BASE_PATH . '/app/Modules/Shipping/ShippingAdapterFactory.php';
            $adapter = ShippingAdapterFactory::make($labelProvider, $cfg);
            $result  = $adapter->generateLabel($order, $package, $resolved, $providerCfg ?: []);

            // zapisz wynik
            $repo->updatePackageLabel(
                (int)$package['id'],
                (string)$result['tracking_number'],
                $result['external_shipment_id'] ?? null,
                (string)($result['label_status'] ?? 'ok')
            );

            $repo->createLabel(
                (int)$package['id'],
                (string)($result['label_format'] ?? 'pdf'),
                (string)($result['label_status'] ?? 'ok'),
                $result['file_path'] ?? null,
                $result['file_token'] ?? null,
                json_encode($result['raw_response'] ?? [], JSON_UNESCAPED_UNICODE)
            );

            // drukuj na Zebrze przypisanej do stacji operatora
            require_once BASE_PATH . '/app/Support/ZebraPrinter.php';
            $stationCode = (string)($currentSession['station_code'] ?? '');
            if ($stationCode !== '' && isset($result['file_path']) && $result['file_path'] !== '') {
                $fullPath = BASE_PATH . '/storage/labels/' . $result['file_path'];
                if (file_exists($fullPath)) {
                    try {
                        ZebraPrinter::print($stationCode, $fullPath);
                    } catch (Throwable $printErr) {
                        // log błędu druku ale nie przerywaj — etykieta jest wygenerowana
                        error_log('ZebraPrinter error: ' . $printErr->getMessage());
                    }
                }
            }

            $repo->logEvent(
                $sessionId, 'label_generated',
                'Label generated via ' . $labelProvider,
                [
                    'order_code'       => $orderCode,
                    'label_provider'   => $labelProvider,
                    'tracking_number'  => $result['tracking_number'],
                    'user_id'          => (int)$currentSession['user_id'],
                ],
                (int)$currentSession['user_id']
            );

            ApiResponse::ok([
                'shipping' => [
                    'order_code'      => $orderCode,
                    'tracking_number' => $result['tracking_number'],
                    'label_format'    => $result['label_format'] ?? 'pdf',
                    'label_status'    => $result['label_status'] ?? 'ok',
                    'file_token'      => $result['file_token'] ?? null,
                    'source'          => 'generated',
                ]
            ]);

        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function getLabel(array $params = []): void
    {
        global $currentSession;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            $repo = $this->bootPacking();

            $packingSession = $repo->findSessionByOrderCode($orderCode);
            if (!$packingSession) {
                ApiResponse::error('No packing session for order: ' . $orderCode, 400);
                return;
            }

            $package = $repo->findPackageBySession((int)$packingSession['id']);
            if (!$package) {
                ApiResponse::error('No package for order: ' . $orderCode, 400);
                return;
            }

            $label = $repo->findLabelByPackage((int)$package['id']);
            if (!$label) {
                ApiResponse::error('No label for order: ' . $orderCode, 400);
                return;
            }

            ApiResponse::ok([
                'shipping' => [
                    'order_code'      => $orderCode,
                    'tracking_number' => $package['tracking_number'],
                    'label_format'    => $label['label_format'],
                    'label_status'    => $label['label_status'],
                    'file_token'      => $label['file_token'],
                    'file_path'       => $label['file_path'],
                ]
            ]);

        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
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

    public function rules(): void
    {
        try {
            $mapCfg = require BASE_PATH . '/app/Config/shipping_map.php';
            ApiResponse::ok(['rules' => $mapCfg]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function resolveMethod(): void
    {
        try {
            $body = Request::jsonBody();
            require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
            $mapCfg  = require BASE_PATH . '/app/Config/shipping_map.php';
            $resolver = new ShippingMethodResolver($mapCfg);
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($body['delivery_method'] ?? ''),
                'carrier_code'    => (string)($body['carrier_code'] ?? ''),
                'courier_code'    => (string)($body['courier_code'] ?? ''),
            ]);
            ApiResponse::ok(['shipping' => $resolved]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function options(array $params = []): void
    {
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            $repo  = $this->bootPacking();
            $order = $repo->findOrder($orderCode);
            if (!$order) {
                ApiResponse::error('Order not found: ' . $orderCode, 400);
                return;
            }
            require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
            $mapCfg   = require BASE_PATH . '/app/Config/shipping_map.php';
            $resolver = new ShippingMethodResolver($mapCfg);
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($order['delivery_method'] ?? ''),
                'carrier_code'    => (string)($order['carrier_code'] ?? ''),
                'courier_code'    => (string)($order['courier_code'] ?? ''),
            ]);
            ApiResponse::ok(['shipping' => $resolved]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function label(array $params = []): void
    {
        $this->getLabel($params);
    }

    public function reprint(array $params = []): void
    {
        global $currentSession;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            $repo = $this->bootPacking();

            $packingSession = $repo->findSessionByOrderCode($orderCode);
            if (!$packingSession) {
                ApiResponse::error('No packing session for order: ' . $orderCode, 400);
                return;
            }

            $package = $repo->findPackageBySession((int)$packingSession['id']);
            if (!$package) {
                ApiResponse::error('No package for order: ' . $orderCode, 400);
                return;
            }

            $label = $repo->findLabelByPackage((int)$package['id']);
            if (!$label) {
                ApiResponse::error('No label for order: ' . $orderCode, 400);
                return;
            }

            $repo->logEvent(
                (int)$packingSession['id'], 'label_reprinted',
                'Label reprint requested for order: ' . $orderCode,
                ['order_code' => $orderCode, 'user_id' => (int)$currentSession['user_id']],
                (int)$currentSession['user_id']
            );

            ApiResponse::ok([
                'shipping' => [
                    'order_code'      => $orderCode,
                    'tracking_number' => $package['tracking_number'],
                    'label_format'    => $label['label_format'],
                    'label_status'    => $label['label_status'],
                    'file_token'      => $label['file_token'],
                    'file_path'       => $label['file_path'],
                    'source'          => 'reprint',
                ]
            ]);

        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

}