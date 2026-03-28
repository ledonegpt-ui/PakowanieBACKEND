<?php
declare(strict_types=1);

final class PickingBatchesController
{
    private function boot(): PickingBatchService
    {
        global $cfg, $currentSession;

        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Picking/Repositories/PickingBatchRepository.php';
        require_once BASE_PATH . '/app/Modules/Picking/Services/PickingBatchService.php';

        $db     = Db::mysql($cfg);
        $mapCfg = require BASE_PATH . '/app/Config/shipping_map.php';
        $repo   = new PickingBatchRepository($db);
        return new PickingBatchService($repo, $mapCfg, $cfg);
    }

    public function open(array $params = []): void
    {
        global $currentSession;
        $body = Request::jsonBody();

        try {
            $service = $this->boot();
            $result  = $service->openBatch($currentSession, $body);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            $details = array();

            if (strpos((string)$e->getMessage(), 'No available orders for carrier_key:') === 0) {
                try {
                    $service = isset($service) ? $service : $this->boot();

                    $packageMode = isset($currentSession['package_mode'])
                        ? trim((string)$currentSession['package_mode'])
                        : 'small';
                    if (!in_array($packageMode, array('small', 'large'), true)) {
                        $packageMode = 'small';
                    }

                    $carrierKey = trim((string)($body['carrier_key'] ?? ''));
                    if ($carrierKey !== '') {
                        $details = $service->diagnoseOpenBatchUnavailable($carrierKey, $packageMode);
                    }
                } catch (Throwable $inner) {
                    $details = array(
                        'reason' => 'diagnostic_failed',
                        'message' => $inner->getMessage(),
                    );
                }
            }

            ApiResponse::error($e->getMessage(), 400, $details);
        }
    }

    public function current(array $params = []): void
    {
        global $currentSession;
        try {
            $service = $this->boot();
            $result  = $service->currentBatch($currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function show(array $params = []): void
    {
        global $currentSession;
        try {
            $batchId = (int)($params['batchId'] ?? 0);
            $service = $this->boot();
            $result  = $service->showBatch($batchId, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function refill(array $params = []): void
    {
        global $currentSession;
        try {
            $batchId = (int)($params['batchId'] ?? 0);
            $service = $this->boot();
            $result  = $service->refillBatch($batchId, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function selectionMode(array $params = []): void
    {
        global $currentSession;
        try {
            $batchId = (int)($params['batchId'] ?? 0);
            $body    = Request::jsonBody();
            $service = $this->boot();
            $result  = $service->updateSelectionMode($batchId, $body, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function close(array $params = []): void
    {
        global $currentSession;
        try {
            $batchId = (int)($params['batchId'] ?? 0);
            $service = $this->boot();
            $result  = $service->closeBatch($batchId, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
    public function abandon(array $params = []): void
    {
        global $currentSession;
        try {
            $batchId = (int)($params['batchId'] ?? 0);
            $service = $this->boot();
            $result  = $service->abandonBatch($batchId, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
