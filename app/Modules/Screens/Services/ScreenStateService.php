<?php

declare(strict_types=1);

final class ScreenStateService
{
    /** @var ScreenStateRepository */
    private $repo;

    public function __construct(ScreenStateRepository $repo)
    {
        $this->repo = $repo;
    }

    public function getCurrentByStationCode(string $stationCode): array
    {
        $row = $this->repo->findCurrentByStationCode($stationCode);

        if (!$row) {
            throw new RuntimeException('Station not found: ' . $stationCode);
        }

        $state = (string)($row['state'] ?? 'idle');

        if ($state !== 'packing') {
            return [
                'station_code' => (string)$row['station_code'],
                'state' => 'idle',
                'message' => 'CZEKAM NA ZEBRANIE TOWARU',
                'updated_at' => $row['updated_at'],
            ];
        }

        $payloadJson = isset($row['payload_json']) ? (string)$row['payload_json'] : '';

        if ($payloadJson === '') {
            throw new RuntimeException('Packing screen payload is empty for station: ' . $stationCode);
        }

        $packing = json_decode($payloadJson, true);

        if (!is_array($packing)) {
            throw new RuntimeException('Packing screen payload is invalid JSON for station: ' . $stationCode);
        }

        return [
            'station_code' => (string)$row['station_code'],
            'state' => 'packing',
            'updated_at' => $row['updated_at'],
            'packing' => $packing,
        ];
    }

    public function setPackingState(int $stationId, array $packingPayload): void
    {
        if ($stationId <= 0) {
            throw new RuntimeException('Invalid stationId for packing screen state');
        }

        $this->repo->upsertPackingState($stationId, $packingPayload);
    }

    public function setIdleState(int $stationId): void
    {
        if ($stationId <= 0) {
            throw new RuntimeException('Invalid stationId for idle screen state');
        }

        $this->repo->upsertIdleState($stationId);
    }

    public function getVersionSnapshotByStationCode(string $stationCode): array
    {
        $row = $this->repo->getVersionSnapshotByStationCode($stationCode);

        if (!$row) {
            throw new RuntimeException('Station not found: ' . $stationCode);
        }

        return [
            'station_code' => (string)$row['station_code'],
            'state' => (string)($row['state'] ?? 'idle'),
            'version' => (int)($row['version'] ?? 0),
            'updated_at' => $row['updated_at'],
        ];
    }
}