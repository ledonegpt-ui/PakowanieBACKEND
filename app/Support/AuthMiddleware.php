<?php
declare(strict_types=1);

final class AuthMiddleware
{
    /** @var array */
    private static $publicRoutes = [
        'GET /api/v1/health',
        'POST /api/v1/auth/login',
    ];

    public static function handle(string $method, string $path, array $cfg): ?array
    {
        foreach (self::$publicRoutes as $public) {
            if (($method . ' ' . $path) === $public) {
                return null;
            }
        }

        $token = Request::bearerToken();

        if (!$token) {
            ApiResponse::error('Unauthorized: missing token', 401);
            exit;
        }

        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Auth/Repositories/AuthRepository.php';

        $db = Db::mysql($cfg);
        $repo = new AuthRepository($db);
        $session = $repo->findActiveSessionByToken($token);

        if (!$session) {
            ApiResponse::error('Unauthorized: invalid or expired token', 401);
            exit;
        }

        $repo->touchSession($token);

        return $session;
    }
}
