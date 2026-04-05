<?php
declare(strict_types=1);

final class PanelOrdersController
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    private function boot(): PanelOrdersService
    {
        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Panel/Repositories/PanelOrdersRepository.php';
        require_once BASE_PATH . '/app/Modules/Panel/Services/PanelOrdersService.php';

        $db = Db::mysql($this->cfg);
        $mapCfg = require BASE_PATH . '/app/Config/shipping_map.php';

        $repo = new PanelOrdersRepository($db);
        return new PanelOrdersService($repo, $mapCfg);
    }

    public function index(): void
    {
        try {
            $service = $this->boot();
            $result = $service->search($_GET);

            ApiResponse::ok(array(
                'module' => 'panel',
                'action' => 'orders_index',
                'orders' => $result['orders'],
                'filters' => $result['filters'],
            ));
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function show(array $params): void
    {
        try {
            $orderCode = (string)($params['orderCode'] ?? '');
            $service = $this->boot();
            $result = $service->detail($orderCode);

            ApiResponse::ok(array(
                'module' => 'panel',
                'action' => 'orders_show',
                'order' => $result['order'],
            ));
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 404);
        }
    }

    public function update(array $params): void
    {
        global $currentSession;

        try {
            $orderCode = (string)($params['orderCode'] ?? '');
            $body = Request::jsonBody();

            $service = $this->boot();
            $result = $service->update($orderCode, $body, is_array($currentSession) ? $currentSession : array());

            ApiResponse::ok(array(
                'module' => 'panel',
                'action' => 'orders_update',
                'updated' => $result['updated'],
                'changes' => $result['changes'],
                'order' => $result['order'],
            ));
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function deleteForce(array $params): void
    {
        global $currentSession;

        try {
            $orderCode = (string)($params['orderCode'] ?? '');
            $body = Request::jsonBody();

            $service = $this->boot();
            $result = $service->deleteForce($orderCode, $body, is_array($currentSession) ? $currentSession : array());

            ApiResponse::ok(array(
                'module' => 'panel',
                'action' => 'orders_delete_force',
                'deleted' => $result['deleted'],
                'summary' => $result['summary'],
            ));
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
