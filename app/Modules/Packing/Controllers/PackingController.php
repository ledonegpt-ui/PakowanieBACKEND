<?php
declare(strict_types=1);

final class PackingController
{
    private function boot(): PackingService
    {
        global $cfg;

        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Packing/Repositories/PackingRepository.php';
        require_once BASE_PATH . '/app/Modules/Packing/Services/PackingService.php';

        $db     = Db::mysql($cfg);
        $mapCfg = require BASE_PATH . '/app/Config/shipping_map.php';
        $repo   = new PackingRepository($db);
        return new PackingService($repo, $mapCfg);
    }

    public function open(array $params = []): void
    {
        global $currentSession;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            if ($orderCode === '') {
                ApiResponse::error('Missing orderCode', 400);
                return;
            }
            $service = $this->boot();
            $result  = $service->openSession($orderCode, $currentSession);
            ApiResponse::ok(['packing' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function show(array $params = []): void
    {
        global $currentSession;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            if ($orderCode === '') {
                ApiResponse::error('Missing orderCode', 400);
                return;
            }
            $service = $this->boot();
            $result  = $service->showSession($orderCode, $currentSession);
            ApiResponse::ok(['packing' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function finish(array $params = []): void
    {
        global $currentSession;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            if ($orderCode === '') {
                ApiResponse::error('Missing orderCode', 400);
                return;
            }
            $service = $this->boot();
            $result  = $service->finishSession($orderCode, $currentSession);
            ApiResponse::ok(['packing' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function cancel(array $params = []): void
    {
        global $currentSession;
        try {
            $orderCode = (string)($params['orderId'] ?? '');
            if ($orderCode === '') {
                ApiResponse::error('Missing orderCode', 400);
                return;
            }
            $service = $this->boot();
            $result  = $service->cancelSession($orderCode, $currentSession);
            ApiResponse::ok(['packing' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function heartbeat(array $params = []): void
    {
        // obsługiwane przez globalny POST /api/v1/heartbeat
        ApiResponse::ok(['heartbeat' => ['status' => 'use_global_heartbeat']]);
    }
}
