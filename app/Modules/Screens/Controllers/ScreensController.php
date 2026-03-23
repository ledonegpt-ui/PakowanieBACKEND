<?php

declare(strict_types=1);

final class ScreensController
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    private function boot(): ScreenStateService
    {
        require_once __DIR__ . '/../../../Lib/Db.php';
        require_once __DIR__ . '/../Repositories/ScreenStateRepository.php';
        require_once __DIR__ . '/../Services/ScreenStateService.php';

        $db = Db::mysql($this->cfg);
        $repo = new ScreenStateRepository($db);

        return new ScreenStateService($repo);
    }

    public function current(array $params = []): void
    {
        try {
            $stationCode = trim((string)($params['stationCode'] ?? ''));

            if ($stationCode === '') {
                ApiResponse::error('Missing stationCode', 400);
                return;
            }

            $result = $this->boot()->getCurrentByStationCode($stationCode);

            ApiResponse::ok($result);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function idle(): void
    {
        global $currentSession;

        try {
            $stationId = isset($currentSession['station_id']) ? (int)$currentSession['station_id'] : 0;

            if ($stationId <= 0) {
                ApiResponse::error('Missing station_id in current session', 400);
                return;
            }

            $service = $this->boot();
            $service->setIdleState($stationId);

            ApiResponse::ok([
                'station_id' => $stationId,
                'state' => 'idle',
            ]);
        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function stream(array $params = []): void
    {
        try {
            $stationCode = trim((string)($params['stationCode'] ?? ''));

            if ($stationCode === '') {
                ApiResponse::error('Missing stationCode', 400);
                return;
            }

            $lastVersionRaw = (string)($_GET['last_version'] ?? '');
            $lastVersion = ctype_digit($lastVersionRaw) ? (int)$lastVersionRaw : null;

            $this->sendSseHeaders();

            $service = $this->boot();
            $deadline = time() + 25;

            do {
                if (connection_aborted()) {
                    return;
                }

                $snapshot = $service->getVersionSnapshotByStationCode($stationCode);

                if ($lastVersion === null || $snapshot['version'] > $lastVersion) {
                    $this->emitSse('screen_state_changed', [
                        'station_code' => $snapshot['station_code'],
                        'state' => $snapshot['state'],
                        'version' => $snapshot['version'],
                        'updated_at' => $snapshot['updated_at'],
                    ]);
                    return;
                }

                sleep(1);
            } while (time() < $deadline);

            echo ": keep-alive\n\n";
            @ob_flush();
            flush();
        } catch (Throwable $e) {
            if (!headers_sent()) {
                ApiResponse::error($e->getMessage(), 400);
                return;
            }

            $this->emitSse('screen_error', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function sendSseHeaders(): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        echo "retry: 3000\n\n";
        flush();
    }

    private function emitSse(string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = '{"message":"failed_to_encode_sse_payload"}';
        }

        echo 'event: ' . $event . "\n";
        echo 'data: ' . $json . "\n\n";

        @ob_flush();
        flush();
    }
}