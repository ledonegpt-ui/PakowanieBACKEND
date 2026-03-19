<?php
declare(strict_types=1);

final class CarriersController
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function index(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/CarriersRepository.php';
            require_once __DIR__ . '/../Services/CarriersService.php';

            $mapCfg = require __DIR__ . '/../../../Config/shipping_map.php';

            $db = Db::mysql($this->cfg);
            $repo = new CarriersRepository($db);
            $service = new CarriersService($repo, $mapCfg);

            ApiResponse::ok([
                'module' => 'carriers',
                'action' => 'index',
                'status' => 'ok',
                'carriers' => $service->listQueueSummary(),
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }
}
