<?php
declare(strict_types=1);

final class PickingOrdersController
{
    private function boot(): PickingBatchService
    {
        global $cfg;

        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Picking/Repositories/PickingBatchRepository.php';
        require_once BASE_PATH . '/app/Modules/Picking/Services/PickingBatchService.php';

        $db     = Db::mysql($cfg);
        $mapCfg = require BASE_PATH . '/app/Config/shipping_map.php';
        $repo   = new PickingBatchRepository($db);
        return new PickingBatchService($repo, $mapCfg, $cfg);
    }

    public function orders(array $params = []): void
    {
        global $currentSession;
        try {
            $batchId = (int)($params['batchId'] ?? 0);
            $service = $this->boot();
            $result  = $service->getBatchOrders($batchId, $currentSession);
            ApiResponse::ok(['orders' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function products(array $params = []): void
    {
        global $currentSession;
        try {
            $batchId = (int)($params['batchId'] ?? 0);
            $service = $this->boot();
            $result  = $service->getBatchProducts($batchId, $currentSession);
            ApiResponse::ok(['products' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function markPicked(array $params = []): void
    {
        global $currentSession;
        try {
            $orderId   = (int)($params['orderId'] ?? 0);
            $itemId    = (int)($params['itemId'] ?? 0);
            $service   = $this->boot();
            $result    = $service->markPicked($orderId, $itemId, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function markMissing(array $params = []): void
    {
        global $currentSession;
        try {
            $orderId   = (int)($params['orderId'] ?? 0);
            $itemId    = (int)($params['itemId'] ?? 0);
            $body      = Request::jsonBody();
            $service   = $this->boot();
            $result    = $service->markMissing($orderId, $itemId, $body, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function drop(array $params = []): void
    {
        global $currentSession;
        try {
            $orderId   = (int)($params['orderId'] ?? 0);
            $body      = Request::jsonBody();
            $service   = $this->boot();
            $result    = $service->dropOrderManual($orderId, $body, $currentSession);
            ApiResponse::ok(['picking' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
