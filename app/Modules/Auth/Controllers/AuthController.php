<?php
declare(strict_types=1);

final class AuthController
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function login(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/AuthRepository.php';
            require_once __DIR__ . '/../Services/AuthService.php';

            $db = Db::mysql($this->cfg);
            $repo = new AuthRepository($db);
            $service = new AuthService($repo, $this->cfg);

            $body = Request::jsonBody();
            $result = $service->login($body);

            ApiResponse::ok([
                'module' => 'auth',
                'action' => 'login',
                'status' => 'ok',
                'auth' => $result,
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function logout(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/AuthRepository.php';
            require_once __DIR__ . '/../Services/AuthService.php';

            $db = Db::mysql($this->cfg);
            $repo = new AuthRepository($db);
            $service = new AuthService($repo, $this->cfg);

            $result = $service->logout(Request::bearerToken());

            ApiResponse::ok([
                'module' => 'auth',
                'action' => 'logout',
                'status' => 'ok',
                'auth' => $result,
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 401);
        }
    }

    public function me(): void
    {
        try {
            require_once __DIR__ . '/../../../Lib/Db.php';
            require_once __DIR__ . '/../Repositories/AuthRepository.php';
            require_once __DIR__ . '/../Services/AuthService.php';

            $db = Db::mysql($this->cfg);
            $repo = new AuthRepository($db);
            $service = new AuthService($repo, $this->cfg);

            $result = $service->me(Request::bearerToken());

            ApiResponse::ok([
                'module' => 'auth',
                'action' => 'me',
                'status' => 'ok',
                'auth' => $result,
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 401);
        }
    }
}
