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
                'station_id' => (int)$updated['station_id'],
                'station_code' => (string)$updated['station_code'],
                'package_mode' => (string)$updated['package_mode'],
                'package_mode_default' => (string)$updated['package_mode_default'],
            ],
        ];
    }
}
