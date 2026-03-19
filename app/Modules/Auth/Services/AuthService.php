<?php
declare(strict_types=1);

final class AuthService
{
    /** @var AuthRepository */
    private $repo;

    /** @var array */
    private $cfg;

    public function __construct(AuthRepository $repo, array $cfg)
    {
        $this->repo = $repo;
        $this->cfg = $cfg;
    }

    public function login(array $body): array
    {
        $loginOrBarcode = trim((string)($body['barcode'] ?? $body['login'] ?? ''));
        $stationCode = trim((string)($body['station_code'] ?? ''));
        $workflowMode = trim((string)($body['workflow_mode'] ?? 'integrated'));

        if ($loginOrBarcode === '') {
            throw new RuntimeException('Missing login or barcode');
        }

        if ($stationCode === '') {
            throw new RuntimeException('Missing station_code');
        }

        $user = $this->repo->findActiveUserByLoginOrBarcode($loginOrBarcode);
        if (!$user) {
            throw new RuntimeException('User not found');
        }

        $station = $this->repo->findActiveStationByCode($stationCode);
        if (!$station) {
            throw new RuntimeException('Station not found');
        }

        $token = bin2hex(random_bytes(32));
        $workflowMode = ($workflowMode !== '' ? $workflowMode : 'integrated');

        $packageMode = isset($station['package_mode_default'])
            ? trim((string)$station['package_mode_default'])
            : 'small';

        if (!in_array($packageMode, ['small', 'large'], true)) {
            $packageMode = 'small';
        }

        $this->repo->deactivateActiveSessionsForUser((int)$user['id']);
        $this->repo->createSession(
            (int)$user['id'],
            (int)$station['id'],
            $token,
            $workflowMode,
            $packageMode
        );

        $roles = $this->repo->rolesForUser((int)$user['id']);

        return [
            'token' => $token,
            'workflow_mode' => $workflowMode,
            'user' => [
                'id' => (int)$user['id'],
                'login' => $user['login'],
                'display_name' => $user['display_name'],
                'barcode' => $user['barcode'],
                'roles' => $roles,
            ],
            'station' => [
                'id' => (int)$station['id'],
                'station_code' => $station['station_code'],
                'station_name' => $station['station_name'],
                'printer_ip' => $station['printer_ip'],
                'printer_name' => $station['printer_name'],
                'package_mode' => $packageMode,
                'package_mode_default' => $packageMode,
            ],
        ];
    }

    public function me(?string $token): array
    {
        if (!$token) {
            throw new RuntimeException('Missing bearer token');
        }

        $session = $this->repo->findActiveSessionByToken($token);
        if (!$session) {
            throw new RuntimeException('Session not found');
        }

        $this->repo->touchSession($token);
        $roles = $this->repo->rolesForUser((int)$session['user_id']);

        $packageMode = isset($session['package_mode']) ? trim((string)$session['package_mode']) : 'small';
        if (!in_array($packageMode, ['small', 'large'], true)) {
            $packageMode = 'small';
        }

        $packageModeDefault = isset($session['package_mode_default'])
            ? trim((string)$session['package_mode_default'])
            : 'small';

        if (!in_array($packageModeDefault, ['small', 'large'], true)) {
            $packageModeDefault = 'small';
        }

        return [
            'token' => $session['session_token'],
            'workflow_mode' => $session['workflow_mode'],
            'user' => [
                'id' => (int)$session['user_id'],
                'login' => $session['login'],
                'display_name' => $session['display_name'],
                'barcode' => $session['barcode'],
                'roles' => $roles,
            ],
            'station' => [
                'id' => (int)$session['station_id'],
                'station_code' => $session['station_code'],
                'station_name' => $session['station_name'],
                'printer_ip' => $session['printer_ip'],
                'printer_name' => $session['printer_name'],
                'package_mode' => $packageMode,
                'package_mode_default' => $packageModeDefault,
            ],
            'session' => [
                'session_id' => (int)$session['session_id'],
                'started_at' => $session['started_at'],
                'last_seen_at' => $session['last_seen_at'],
            ],
        ];
    }

    public function logout(?string $token): array
    {
        if (!$token) {
            throw new RuntimeException('Missing bearer token');
        }

        $session = $this->repo->findActiveSessionByToken($token);
        if (!$session) {
            throw new RuntimeException('Session not found');
        }

        $this->repo->deactivateSession($token);

        return [
            'logged_out' => true,
            'session_token' => $token,
        ];
    }
}
