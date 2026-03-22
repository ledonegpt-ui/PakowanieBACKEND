<?php
declare(strict_types=1);

final class AdminController
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    private function boot(): AdminService
    {
        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Admin/Repositories/AdminRepository.php';
        require_once BASE_PATH . '/app/Modules/Admin/Services/AdminService.php';
        $db   = Db::mysql($this->cfg);
        $repo = new AdminRepository($db);
        return new AdminService($repo);
    }

    public function batches(): void
    {
        try {
            $r = $this->boot()->batches($_GET);
            ApiResponse::ok(array('module' => 'admin', 'action' => 'batches',
                'batches' => $r['batches'], 'filters' => $r['filters']));
        } catch (Throwable $e) { ApiResponse::error($e->getMessage(), 400); }
    }

    public function statsOverview(): void
    {
        try {
            $stats = $this->boot()->statsOverview();
            ApiResponse::ok(array('module' => 'admin', 'action' => 'stats_overview', 'stats' => $stats));
        } catch (Throwable $e) { ApiResponse::error($e->getMessage(), 400); }
    }

    public function statsDaily(): void
    {
        try {
            $r = $this->boot()->statsDaily($_GET);
            ApiResponse::ok(array('module' => 'admin', 'action' => 'stats_daily',
                'packing' => $r['packing'], 'picking' => $r['picking'],
                'hourly' => $r['hourly'], 'days' => $r['days']));
        } catch (Throwable $e) { ApiResponse::error($e->getMessage(), 400); }
    }

    public function statsPackers(): void
    {
        try {
            $r = $this->boot()->statsPackers($_GET);
            ApiResponse::ok(array('module' => 'admin', 'action' => 'stats_packers',
                'packers' => $r['packers'], 'days' => $r['days']));
        } catch (Throwable $e) { ApiResponse::error($e->getMessage(), 400); }
    }

    public function statsCarriers(): void
    {
        try {
            $r = $this->boot()->statsCarriers($_GET);
            ApiResponse::ok(array('module' => 'admin', 'action' => 'stats_carriers',
                'carriers' => $r['carriers'], 'days' => $r['days']));
        } catch (Throwable $e) { ApiResponse::error($e->getMessage(), 400); }
    }

    public function statsUserDaily(array $params): void
    {
        try {
            $userId = (int)($params['userId'] ?? 0);
            if ($userId <= 0) throw new RuntimeException('Invalid userId');
            $r = $this->boot()->statsUserDaily($userId, $_GET);
            ApiResponse::ok(array('module' => 'admin', 'action' => 'stats_user_daily',
                'user_id' => $r['user_id'], 'daily' => $r['daily'],
                'period_days' => $r['period_days']));
        } catch (Throwable $e) { ApiResponse::error($e->getMessage(), 400); }
    }
}
