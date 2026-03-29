<?php
declare(strict_types=1);

final class StationsService
{
    /** @var StationsRepository */
    private $repo;

    public function __construct(StationsRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listStations(): array
    {
        return $this->repo->allActive();
    }

    public function selectStation(?string $token, array $body): array
    {
        if (!$token) {
            throw new RuntimeException('Missing bearer token');
        }

        $stationCode = trim((string)($body['station_code'] ?? ''));
        if ($stationCode === '') {
            throw new RuntimeException('Missing station_code');
        }

        $session = $this->repo->findActiveSessionByToken($token);
        if (!$session) {
            throw new RuntimeException('Active station session not found');
        }

        $station = $this->repo->findActiveStationByCode($stationCode);
        if (!$station) {
            throw new RuntimeException('Station not found');
        }

        $packageMode = isset($station['package_mode_default'])
            ? trim((string)$station['package_mode_default'])
            : 'small';
        if (!in_array($packageMode, ['small', 'large'], true)) {
            $packageMode = 'small';
        }

        $this->repo->transferOpenBatchToStation((int)$session['user_id'], (int)$station['id']);
        $this->repo->transferOpenPackingSessionToStation((int)$session['user_id'], (int)$station['id']);
        $this->repo->updateSessionStation((int)$session['session_id'], (int)$station['id'], $packageMode);

        $updated = $this->repo->findActiveSessionByToken($token);
        if (!$updated) {
            throw new RuntimeException('Active station session not found after update');
        }

        return [
            'station' => [
                'user_id' => (int)$updated['user_id'],
                'station_id' => (int)$updated['station_id'],
                'station_code' => (string)$updated['station_code'],
                'station_name' => (string)$updated['station_name'],
                'workflow_mode' => (string)($updated['workflow_mode'] ?? 'integrated'),
                'work_mode' => (string)($updated['work_mode'] ?? 'picker'),
                'package_mode' => (string)$updated['package_mode'],
                'package_mode_default' => (string)$updated['package_mode_default'],
                'picking_batch_size' => (int)($updated['picking_batch_size'] ?? 0),
            ],
        ];
    }

    public function updatePackageMode(?string $token, array $body): array
    {
        if (!$token) {
            throw new RuntimeException('Missing bearer token');
        }

        $packageMode = trim((string)($body['package_mode'] ?? ''));
        if (!in_array($packageMode, ['small', 'large'], true)) {
            throw new RuntimeException('Invalid package_mode');
        }

        $session = $this->repo->findActiveSessionByToken($token);
        if (!$session) {
            throw new RuntimeException('Active station session not found');
        }

        $this->repo->updateSessionPackageMode((int)$session['session_id'], $packageMode);

        $updated = $this->repo->findActiveSessionByToken($token);
        if (!$updated) {
            throw new RuntimeException('Active station session not found after update');
        }

        return [
            'station' => [
                'user_id' => (int)$updated['user_id'],
                'station_id' => (int)$updated['station_id'],
                'station_code' => (string)$updated['station_code'],
                'workflow_mode' => (string)($updated['workflow_mode'] ?? 'integrated'),
                'work_mode' => (string)($updated['work_mode'] ?? 'picker'),
                'package_mode' => (string)$updated['package_mode'],
                'package_mode_default' => (string)$updated['package_mode_default'],
                'picking_batch_size' => (int)($updated['picking_batch_size'] ?? 0),
            ],
        ];
    }


    public function updateWorkflowMode(?string $token, array $body): array
    {
        if (!$token) {
            throw new RuntimeException('Missing bearer token');
        }

        $workflowMode = trim((string)($body['workflow_mode'] ?? ''));
        if (!in_array($workflowMode, ['integrated', 'split'], true)) {
            throw new RuntimeException('Invalid workflow_mode');
        }

        $session = $this->repo->findActiveSessionByToken($token);
        if (!$session) {
            throw new RuntimeException('Active station session not found');
        }

        $this->repo->updateSessionWorkflowMode((int)$session['session_id'], $workflowMode);

        $updated = $this->repo->findActiveSessionByToken($token);
        if (!$updated) {
            throw new RuntimeException('Active station session not found after update');
        }

        return [
            'station' => [
                'user_id' => (int)$updated['user_id'],
                'station_id' => (int)$updated['station_id'],
                'station_code' => (string)$updated['station_code'],
                'workflow_mode' => (string)($updated['workflow_mode'] ?? $workflowMode),
                'work_mode' => (string)($updated['work_mode'] ?? 'picker'),
                'package_mode' => (string)$updated['package_mode'],
                'package_mode_default' => (string)$updated['package_mode_default'],
                'picking_batch_size' => (int)$updated['picking_batch_size'],
            ],
        ];
    }

    public function updateWorkMode(?string $token, array $body): array
    {
        if (!$token) {
            throw new RuntimeException('Missing bearer token');
        }

        $workMode = trim((string)($body['work_mode'] ?? ''));
        if (!in_array($workMode, ['picker', 'packer'], true)) {
            throw new RuntimeException('Invalid work_mode');
        }

        $session = $this->repo->findActiveSessionByToken($token);
        if (!$session) {
            throw new RuntimeException('Active station session not found');
        }

        $this->repo->updateSessionWorkMode((int)$session['session_id'], $workMode);

        $updated = $this->repo->findActiveSessionByToken($token);
        if (!$updated) {
            throw new RuntimeException('Active station session not found after update');
        }

        return [
            'station' => [
                'user_id' => (int)$updated['user_id'],
                'station_id' => (int)$updated['station_id'],
                'station_code' => (string)$updated['station_code'],
                'workflow_mode' => (string)($updated['workflow_mode'] ?? 'integrated'),
                'work_mode' => (string)$updated['work_mode'],
                'package_mode' => (string)$updated['package_mode'],
                'package_mode_default' => (string)$updated['package_mode_default'],
                'picking_batch_size' => (int)$updated['picking_batch_size'],
            ],
        ];
    }

    public function updatePickingBatchSize(?string $token, array $body): array
    {
        if (!$token) {
            throw new RuntimeException('Missing bearer token');
        }

        $pickingBatchSize = (int)($body['picking_batch_size'] ?? 0);
        if ($pickingBatchSize < 1 || $pickingBatchSize > 100) {
            throw new RuntimeException('Invalid picking_batch_size');
        }

        $session = $this->repo->findActiveSessionByToken($token);
        if (!$session) {
            throw new RuntimeException('Active station session not found');
        }

        $this->repo->updateSessionPickingBatchSize((int)$session['session_id'], $pickingBatchSize);

        $updated = $this->repo->findActiveSessionByToken($token);
        if (!$updated) {
            throw new RuntimeException('Active station session not found after update');
        }

        return [
            'station' => [
                'user_id' => (int)$updated['user_id'],
                'station_id' => (int)$updated['station_id'],
                'station_code' => (string)$updated['station_code'],
                'workflow_mode' => (string)($updated['workflow_mode'] ?? 'integrated'),
                'work_mode' => (string)($updated['work_mode'] ?? 'picker'),
                'package_mode' => (string)$updated['package_mode'],
                'package_mode_default' => (string)$updated['package_mode_default'],
                'picking_batch_size' => (int)$updated['picking_batch_size'],
            ],
        ];
    }

}
