<?php
declare(strict_types=1);

final class WorkflowController
{
    public function status(array $params = array()): void
    {
        global $currentSession, $cfg;

        try {
            require_once BASE_PATH . '/app/Modules/Workflow/Services/WorkflowStatusService.php';

            $payload = (new WorkflowStatusService($cfg))->resolveFromSession($currentSession);
            ApiResponse::ok($payload);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
