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

        return new PackingService($repo, $mapCfg, $cfg);
    }

    private function bootScreenState(): ScreenStateService
    {
        global $cfg;

        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Screens/Repositories/ScreenStateRepository.php';
        require_once BASE_PATH . '/app/Modules/Screens/Services/ScreenStateService.php';

        $db   = Db::mysql($cfg);
        $repo = new ScreenStateRepository($db);

        return new ScreenStateService($repo);
    }

    private function syncScreenPacking(array $session, array $packingPayload): void
    {
        $stationId = isset($session['station_id']) ? (int)$session['station_id'] : 0;

        if ($stationId <= 0) {
            throw new RuntimeException('Missing station_id in current session');
        }

        $this->bootScreenState()->setPackingState($stationId, $packingPayload);
    }

    private function syncScreenIdle(array $session): void
    {
        $stationId = isset($session['station_id']) ? (int)$session['station_id'] : 0;

        if ($stationId <= 0) {
            throw new RuntimeException('Missing station_id in current session');
        }

        $this->bootScreenState()->setIdleState($stationId);
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

            $this->syncScreenPacking($currentSession, $result);

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

            if (!empty($result['batch_completed'])) {
                $this->syncScreenIdle($currentSession);
            }

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
        ApiResponse::ok(['heartbeat' => ['status' => 'use_global_heartbeat']]);
    }

    /**
     * GET /api/v1/packing/next?batch_id={id}
     * Zwraca nastepne zamowienie do spakowania w batchu.
     */
    public function next(array $params = []): void
    {
        global $currentSession;

        try {
            $batchId = trim((string)($_GET['batch_id'] ?? ''));
            if ($batchId === '' || !ctype_digit($batchId)) {
                ApiResponse::error('Missing or invalid batch_id', 400);
                return;
            }

            $service = $this->boot();
            $result  = $service->getNextOrder((int)$batchId, $currentSession);

            ApiResponse::ok(['packing' => $result]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}