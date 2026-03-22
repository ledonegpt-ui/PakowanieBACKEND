<?php
declare(strict_types=1);

final class AdminService
{
    /** @var AdminRepository */
    private $repo;

    public function __construct(AdminRepository $repo)
    {
        $this->repo = $repo;
    }

    public function batches(array $query): array
    {
        $status     = trim((string)($query['status'] ?? ''));
        $carrierKey = trim((string)($query['carrier'] ?? ''));
        $limit      = max(1, min(500, (int)($query['limit'] ?? 100)));
        return array(
            'batches' => $this->repo->getBatches($status, $carrierKey, $limit),
            'filters' => array('status' => $status, 'carrier' => $carrierKey, 'limit' => $limit),
        );
    }

    public function statsOverview(): array
    {
        return $this->repo->getOverviewStats();
    }

    public function statsDaily(array $query): array
    {
        $days = max(1, min(365, (int)($query['days'] ?? 30)));
        return array(
            'packing' => $this->repo->getDailyStats($days),
            'picking' => $this->repo->getPickingDailyStats($days),
            'hourly'  => $this->repo->getHourlyStats($days),
            'days'    => $days,
        );
    }

    public function statsPackers(array $query): array
    {
        $days = max(1, min(365, (int)($query['days'] ?? 7)));
        return array(
            'packers' => $this->repo->getPackersStats($days),
            'days'    => $days,
        );
    }

    public function statsCarriers(array $query): array
    {
        $days = max(1, min(365, (int)($query['days'] ?? 7)));
        return array(
            'carriers' => $this->repo->getCarrierStats($days),
            'days'     => $days,
        );
    }

    public function statsUserDaily(int $userId, array $query): array
    {
        $days = max(1, min(365, (int)($query['days'] ?? 30)));
        return array(
            'user_id'     => $userId,
            'daily'       => $this->repo->getUserDailyStats($userId, $days),
            'period_days' => $days,
        );
    }
}
