<?php
declare(strict_types=1);

final class ShippingController
{

    private function respondLabelFailure(string $orderCode, string $message, int $sessionId = 0, int $userId = 0, $repo = null): void
    {
        if ($sessionId > 0 && $repo !== null) {
            try {
                $repo->logEvent(
                    $sessionId,
                    'label_generation_failed',
                    $message,
                    [
                        'order_code' => $orderCode,
                        'retry_allowed' => true,
                        'user_id' => $userId > 0 ? $userId : null,
                    ],
                    $userId > 0 ? $userId : null
                );
            } catch (Throwable $logErr) {
                error_log('label_generation_failed log error: ' . $logErr->getMessage());
            }
        }

        ApiResponse::ok([
            'shipping' => [
                'order_code' => $orderCode,
                'status' => 'failed',
                'retry_allowed' => true,
                'message' => $message,
            ]
        ]);
    }

    private function bootPacking(): PackingRepository
    {
        global $cfg;
        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Packing/Repositories/PackingRepository.php';
        return new PackingRepository(Db::mysql($cfg));
    }

    private function bootShippingService(PackingRepository $repo): ShippingService
    {
        global $cfg;
        require_once BASE_PATH . '/app/Modules/Shipping/Services/ShippingService.php';
        return new ShippingService($repo, $cfg);
    }

    public function generateLabel(array $params = []): void
    {
        global $currentSession;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            if ($orderCode === '') {
                $this->respondLabelFailure('', 'Missing orderCode');
                return;
            }

            $body      = Request::jsonBody();
            $sizeParam = (string)($body['size'] ?? '');

            $print = true;
            if (array_key_exists('print', $body)) {
                $parsed = filter_var($body['print'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $print = $parsed === null ? true : (bool)$parsed;
            }

            $repo = $this->bootPacking();
            $service = $this->bootShippingService($repo);
            $result = $service->generateLabel($orderCode, is_array($currentSession) ? $currentSession : [], $sizeParam, $print, false);

            ApiResponse::ok([
                'shipping' => $result
            ]);

        } catch (Throwable $e) {
            $safeOrderCode = isset($orderCode) ? (string)$orderCode : '';
            $safeUserId = isset($currentSession['user_id']) ? (int)$currentSession['user_id'] : 0;
            $safeRepo = isset($repo) ? $repo : null;
            $safeSessionId = 0;

            if ($safeOrderCode !== '' && $safeRepo !== null) {
                try {
                    $failedSession = $safeRepo->findSessionByOrderCode($safeOrderCode);
                    $safeSessionId = (int)($failedSession['id'] ?? 0);
                } catch (Throwable $ignored) {
                    $safeSessionId = 0;
                }
            }

            $this->respondLabelFailure(
                $safeOrderCode,
                $e->getMessage(),
                $safeSessionId,
                $safeUserId,
                $safeRepo
            );
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

            $stationCode = (string)($currentSession['station_code'] ?? '');
            if ($stationCode === '') {
                throw new RuntimeException('No station_code in session');
            }

            $filePath = (string)($label['file_path'] ?? '');
            if ($filePath === '') {
                throw new RuntimeException('No label file path for order: ' . $orderCode);
            }

            $fullPath = BASE_PATH . '/storage/labels/' . ltrim($filePath, '/');
            if (!file_exists($fullPath)) {
                throw new RuntimeException('Label file not found for reprint: ' . $filePath);
            }

            require_once BASE_PATH . '/app/Support/ZebraPrinter.php';
            ZebraPrinter::print($stationCode, $fullPath);

            $repo->logEvent(
                (int)$packingSession['id'], 'label_reprinted',
                'Label reprint requested for order: ' . $orderCode,
                [
                    'order_code'   => $orderCode,
                    'user_id'      => (int)$currentSession['user_id'],
                    'station_code' => $stationCode,
                    'file_path'    => $filePath,
                ],
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
                    'reprint_sent'    => true,
                    'station_code'    => $stationCode,
                ]
            ]);

        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

}