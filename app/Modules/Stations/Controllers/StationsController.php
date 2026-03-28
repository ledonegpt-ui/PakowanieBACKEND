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
        $body = Request::jsonBody();

        ApiResponse::ok([
            'module' => 'stations',
            'action' => 'select',
            'status' => 'stub',
            'received' => [
                'station_code' => $body['station_code'] ?? null,
            ],
        ]);
    }

    public function packageMode(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/StationsRepository.php';
            require_once __DIR__ . '/../Services/StationsService.php';

            $db = Db::mysql($this->cfg);
            $repo = new StationsRepository($db);
            $service = new StationsService($repo);

            $body = Request::jsonBody();
            $result = $service->updatePackageMode(Request::bearerToken(), $body);

            ApiResponse::ok([
                'ok' => true,
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }


    public function workflowMode(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/StationsRepository.php';
            require_once __DIR__ . '/../Services/StationsService.php';

            $db = Db::mysql($this->cfg);
            $repo = new StationsRepository($db);
            $service = new StationsService($repo);

            $body = Request::jsonBody();
            $result = $service->updateWorkflowMode(Request::bearerToken(), $body);

            ApiResponse::ok([
                'ok' => true,
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function workMode(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/StationsRepository.php';
            require_once __DIR__ . '/../Services/StationsService.php';

            $db = Db::mysql($this->cfg);
            $repo = new StationsRepository($db);
            $service = new StationsService($repo);

            $body = Request::jsonBody();
            $result = $service->updateWorkMode(Request::bearerToken(), $body);

            ApiResponse::ok([
                'ok' => true,
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function pickingBatchSize(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/StationsRepository.php';
            require_once __DIR__ . '/../Services/StationsService.php';

            $db = Db::mysql($this->cfg);
            $repo = new StationsRepository($db);
            $service = new StationsService($repo);

            $body = Request::jsonBody();
            $result = $service->updatePickingBatchSize(Request::bearerToken(), $body);

            ApiResponse::ok([
                'ok' => true,
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

}
