<?php
declare(strict_types=1);

final class StationsController
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function index(): void
    {
        require_once __DIR__ . '/../../../Lib/Db.php';
        require_once __DIR__ . '/../Repositories/StationsRepository.php';
        require_once __DIR__ . '/../Services/StationsService.php';

        $db = Db::mysql($this->cfg);
        $repo = new StationsRepository($db);
        $service = new StationsService($repo);

        ApiResponse::ok([
            'module' => 'stations',
            'action' => 'index',
            'status' => 'ok',
            'stations' => $service->listStations(),
        ]);
    }

    public function select(): void
    {
        try {
            $result = $this->handleStationMutation('select', 'selectStation');
            ApiResponse::ok($result);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function packageMode(): void
    {
        try {
            $result = $this->handleStationMutation('packageMode', 'updatePackageMode');
            ApiResponse::ok($result);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function workflowMode(): void
    {
        try {
            $result = $this->handleStationMutation('workflowMode', 'updateWorkflowMode');
            ApiResponse::ok($result);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function workMode(): void
    {
        try {
            $result = $this->handleStationMutation('workMode', 'updateWorkMode');
            ApiResponse::ok($result);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function pickingBatchSize(): void
    {
        try {
            $result = $this->handleStationMutation('pickingBatchSize', 'updatePickingBatchSize');
            ApiResponse::ok($result);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    private function handleStationMutation(string $action, string $serviceMethod): array
    {
        require_once __DIR__ . '/../../../Lib/Db.php';
        require_once __DIR__ . '/../Repositories/StationsRepository.php';
        require_once __DIR__ . '/../Services/StationsService.php';
        require_once BASE_PATH . '/app/Modules/Workflow/Services/WorkflowStatusService.php';

        $db = Db::mysql($this->cfg);
        $repo = new StationsRepository($db);
        $service = new StationsService($repo);

        $body = Request::jsonBody();
        $stationResult = $service->{$serviceMethod}(Request::bearerToken(), $body);

        $station = isset($stationResult['station']) && is_array($stationResult['station'])
            ? $stationResult['station']
            : [];

        $sessionLike = [
            'user_id' => isset($station['user_id']) ? (int)$station['user_id'] : 0,
            'station_id' => isset($station['station_id']) ? (int)$station['station_id'] : 0,
            'workflow_mode' => isset($station['workflow_mode']) ? (string)$station['workflow_mode'] : 'integrated',
            'work_mode' => isset($station['work_mode']) ? (string)$station['work_mode'] : 'picker',
            'package_mode' => isset($station['package_mode']) ? (string)$station['package_mode'] : 'small',
            'picking_batch_size' => isset($station['picking_batch_size']) ? (int)$station['picking_batch_size'] : null,
        ];

        $workflowPayload = null;
        if (!empty($sessionLike['user_id']) && !empty($sessionLike['station_id'])) {
            $workflowPayload = (new WorkflowStatusService($this->cfg))->resolveFromSession($sessionLike);
        }

        return [
            'module' => 'stations',
            'action' => $action,
            'status' => 'ok',
            'station' => $station,
            'workflow' => $workflowPayload['workflow'] ?? null,
            'next_action' => $workflowPayload['next_action'] ?? null,
        ];
    }
}
