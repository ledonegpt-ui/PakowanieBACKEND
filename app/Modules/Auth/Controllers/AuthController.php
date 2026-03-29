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
            require_once BASE_PATH . '/app/Modules/Workflow/Services/WorkflowStatusService.php';

            $db = Db::mysql($this->cfg);
            $repo = new AuthRepository($db);
            $service = new AuthService($repo, $this->cfg);

            $body = Request::jsonBody();
            $result = $service->login($body);

            $sessionLike = array(
                'user_id' => (int)$result['user']['id'],
                'station_id' => (int)$result['station']['id'],
                'workflow_mode' => isset($result['workflow_mode']) ? (string)$result['workflow_mode'] : 'integrated',
                'work_mode' => isset($result['work_mode']) ? (string)$result['work_mode'] : 'picker',
                'package_mode' => isset($result['station']['package_mode']) ? (string)$result['station']['package_mode'] : 'small',
            );

            $workflowPayload = (new WorkflowStatusService($this->cfg))->resolveFromSession($sessionLike);

            ApiResponse::ok(array(
                'module' => 'auth',
                'action' => 'login',
                'status' => 'ok',
                'auth' => $result,
                'workflow' => $workflowPayload['workflow'],
                'next_action' => $workflowPayload['next_action'],
            ));
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
            require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';

            $db = Db::mysql($this->cfg);
            $repo = new AuthRepository($db);
            $service = new AuthService($repo, $this->cfg);

            $result = $service->logout(Request::bearerToken());

            ApiResponse::ok(array(
                'module' => 'auth',
                'action' => 'logout',
                'status' => 'ok',
                'auth' => $result,
                'next_action' => NextActionResolver::goHome(),
            ));
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
            require_once BASE_PATH . '/app/Modules/Workflow/Services/WorkflowStatusService.php';

            $db = Db::mysql($this->cfg);
            $repo = new AuthRepository($db);
            $service = new AuthService($repo, $this->cfg);

            $result = $service->me(Request::bearerToken());

            $sessionLike = array(
                'user_id' => (int)$result['user']['id'],
                'station_id' => (int)$result['station']['id'],
                'workflow_mode' => isset($result['workflow_mode']) ? (string)$result['workflow_mode'] : 'integrated',
                'work_mode' => isset($result['work_mode']) ? (string)$result['work_mode'] : 'picker',
                'package_mode' => isset($result['station']['package_mode']) ? (string)$result['station']['package_mode'] : 'small',
            );

            $workflowPayload = (new WorkflowStatusService($this->cfg))->resolveFromSession($sessionLike);

            ApiResponse::ok(array(
                'module' => 'auth',
                'action' => 'me',
                'status' => 'ok',
                'auth' => $result,
                'workflow' => $workflowPayload['workflow'],
                'next_action' => $workflowPayload['next_action'],
            ));
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 401);
        }
    }
}
