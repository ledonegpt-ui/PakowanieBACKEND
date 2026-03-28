<?php
declare(strict_types=1);

final class PickingBacklogController
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

    public function summary(array $params = []): void
    {
        global $currentSession;

        try {
            $service = $this->boot();
            $result  = $service->getBacklogSummary($currentSession);
            ApiResponse::ok(['backlog' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function products(array $params = []): void
    {
        global $currentSession;

        try {
            $service = $this->boot();
            $result  = $service->getBacklogProducts($currentSession);
            ApiResponse::ok(['products' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function resolve(array $params = []): void
    {
        global $currentSession;

        try {
            $body = Request::jsonBody();
            $itemKey = trim((string)($body['item_key'] ?? ''));
            $service = $this->boot();
            $result  = $service->resolveBacklogByItemKey($itemKey, $currentSession);
            ApiResponse::ok(['resolve' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function orders(array $params = []): void
    {
        global $currentSession;

        try {
            $itemKey = trim((string)($_GET['item_key'] ?? ''));
            $service = $this->boot();
            $result  = $service->getBacklogOrdersByItemKey($itemKey, $currentSession);
            ApiResponse::ok(['orders' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
