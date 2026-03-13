<?php
declare(strict_types=1);

final class CupsPrintSender
{
    /**
     * Zwraca nazwę kolejki CUPS dla stanowiska i formatu.
     * Akceptuje m.in.:
     * - STATION8
     * - STANOWISKO8
     * - STANOWISKO 8
     * - 8
     *
     * $format: ZPL|RAW|TXT -> *_RAW, PDF -> *_PDF
     */
    public static function resolveQueueForStation(string $stationName, string $format): string
    {
        $stationNo = self::extractStationNo($stationName);
        $suffix = self::queueSuffixForFormat($format); // RAW / PDF

        $envKey = 'STATION' . $stationNo . '_PRINTER_QUEUE_' . $suffix;
        $queue = getenv($envKey);

        if ($queue === false || trim((string)$queue) === '') {
            throw new \RuntimeException("CUPS: brak {$envKey} w ENV");
        }

        return trim((string)$queue);
    }

    /**
     * Wysyła payload do konkretnej kolejki CUPS.
     *
     * Zwraca spójny wynik pod logi / API:
     * [
     *   ok, backend, queue, job_id, format, duration_ms,
     *   error_code, error_message, stdout, stderr
     * ]
     */
    public static function send($queue, $payload, $format = 'RAW', $jobName = ''): array
    {
        $t0 = microtime(true);

        $queue = trim((string)$queue);
        $payload = (string)$payload;
        $format = strtoupper(trim((string)$format));
        $jobName = trim((string)$jobName);

        if ($queue === '') {
            return self::fail('INVALID_QUEUE', 'CUPS: pusta nazwa kolejki', $t0);
        }
        if ($payload === '') {
            return self::fail('EMPTY_PAYLOAD', 'CUPS: pusty payload', $t0, [
                'queue' => $queue,
                'format' => $format,
            ]);
        }

        if ($format === '' || $format === 'TXT') {
            $format = 'ZPL';
        }

        $isPdf = ($format === 'PDF');
        $isRaw = !$isPdf; // ZPL/RAW/TXT -> RAW

        $lpBin = trim((string)(getenv('CUPS_LP_BIN') ?: '/usr/bin/lp'));
        $timeoutSec = (int)(getenv('CUPS_CMD_TIMEOUT_SEC') ?: 8);
        if ($timeoutSec < 1) $timeoutSec = 8;

        $tmpDir = __DIR__ . '/../../storage/tmp/cups_jobs';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $ext = $isPdf ? 'pdf' : 'zpl';
        $tmpFile = $tmpDir . '/job_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        $stdout = '';
        $stderr = '';
        $jobId = null;

        try {
            if (@file_put_contents($tmpFile, $payload) === false) {
                return self::fail('TMP_WRITE_FAIL', 'CUPS: nie mogę zapisać pliku tymczasowego', $t0, [
                    'queue' => $queue,
                    'format' => $isPdf ? 'PDF' : 'ZPL',
                ]);
            }

            $jobTitle = ($jobName !== '') ? $jobName : ('pak_' . date('Ymd_His'));

            $cmd = escapeshellarg($lpBin)
                 . ' -d ' . escapeshellarg($queue)
                 . ' -t ' . escapeshellarg($jobTitle);

            // RAW dla ZPL/TXT, PDF bez -o raw (idzie przez kolejkę *_pdf)
            if ($isRaw) {
                $cmd .= ' -o raw';
            }

            $cmd .= ' ' . escapeshellarg($tmpFile);

            $run = self::runCommand($cmd, $timeoutSec);
            $stdout = (string)$run['stdout'];
            $stderr = (string)$run['stderr'];
            $rc     = (int)$run['rc'];
            $timedOut = !empty($run['timed_out']);

            if ($timedOut) {
                return self::fail('TIMEOUT', 'CUPS: timeout polecenia lp', $t0, [
                    'queue' => $queue,
                    'format' => $isPdf ? 'PDF' : 'ZPL',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ]);
            }

            if ($rc !== 0) {
                $msg = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
                if ($msg === '') $msg = 'lp zakończył się kodem ' . $rc;
                return self::fail('LP_RC_' . $rc, 'CUPS: ' . $msg, $t0, [
                    'queue' => $queue,
                    'format' => $isPdf ? 'PDF' : 'ZPL',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ]);
            }

            $jobId = self::parseJobId($stdout . "\n" . $stderr);

            // Tryb sukcesu (na teraz i tak macie "accepted")
            $successMode = strtolower(trim((string)(getenv('PRINT_CUPS_SUCCESS_MODE') ?: 'accepted')));
            if ($successMode === '') $successMode = 'accepted';

            $ok = true; // accepted = lp rc=0
            $errorCode = null;
            $errorMessage = null;

            return [
                'ok' => $ok,
                'backend' => 'cups',
                'queue' => $queue,
                'job_id' => $jobId,
                'format' => $isPdf ? 'PDF' : 'ZPL',
                'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'success_mode' => $successMode,
                'accepted' => true,
            ];
        } catch (\Throwable $e) {
            return self::fail('EXCEPTION', 'CUPS: ' . $e->getMessage(), $t0, [
                'queue' => $queue,
                'format' => $isPdf ? 'PDF' : 'ZPL',
                'stdout' => $stdout,
                'stderr' => $stderr,
            ]);
        } finally {
            if (isset($tmpFile) && is_string($tmpFile) && $tmpFile !== '') {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Wysyłka po nazwie stanowiska (sam rozwiązuje kolejkę po formacie).
     */
    public static function sendToStation(string $stationName, string $payload, string $format = 'RAW', string $jobName = ''): array
    {
        $queue = self::resolveQueueForStation($stationName, $format);
        return self::send($queue, $payload, $format, $jobName);
    }

    /**
     * Alias kompatybilności (jeśli gdzieś użyty).
     */
    public static function sendToQueue(string $queue, string $payload, string $format = 'RAW', string $jobName = ''): array
    {
        return self::send($queue, $payload, $format, $jobName);
    }

    private static function extractStationNo(string $stationName): int
    {
        $raw = strtoupper(trim($stationName));
        if ($raw === '') {
            throw new \RuntimeException('CUPS: niepoprawna nazwa stanowiska: ' . $stationName);
        }

        // usuń spacje / myślniki / podkreślenia dla tolerancji
        $norm = preg_replace('/[\s\-_]+/', '', $raw);
        if (!is_string($norm)) $norm = $raw;

        // STATION10 / STANOWISKO10
        if (preg_match('/^(?:STATION|STANOWISKO)(\d+)$/', $norm, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 99) return $n;
        }

        // samo "10"
        if (preg_match('/^(\d{1,2})$/', $norm, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 99) return $n;
        }

        throw new \RuntimeException('CUPS: niepoprawna nazwa stanowiska: ' . $stationName);
    }

    private static function queueSuffixForFormat(string $format): string
    {
        $f = strtoupper(trim($format));
        if ($f === 'PDF') return 'PDF';

        // wszystko inne traktujemy jako RAW/ZPL
        return 'RAW';
    }

    private static function parseJobId(string $txt): ?string
    {
        $txt = trim($txt);
        if ($txt === '') return null;

        // Typowe: "request id is zebra_st8_pdf-11 (1 file(s))"
        if (preg_match('/request id is\s+([^\s]+)\s*/i', $txt, $m)) {
            return trim((string)$m[1]);
        }

        // Fallback: wyłap coś w stylu queue-123
        if (preg_match('/([A-Za-z0-9_.-]+-\d+)\b/', $txt, $m)) {
            return trim((string)$m[1]);
        }

        return null;
    }

    /**
     * Uruchamia komendę z timeoutem bez zależności od "timeout" binarki.
     *
     * @return array{rc:int,stdout:string,stderr:string,timed_out:bool}
     */
    private static function runCommand(string $cmd, int $timeoutSec): array
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return ['rc' => 127, 'stdout' => '', 'stderr' => 'proc_open failed', 'timed_out' => false];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $start = microtime(true);
        $lastStatus = null;

        while (true) {
            $status = proc_get_status($proc);
            $lastStatus = is_array($status) ? $status : null;
            $running = is_array($lastStatus) ? (bool)$lastStatus['running'] : false;

            $read = [];
            if (isset($pipes[1]) && is_resource($pipes[1])) $read[] = $pipes[1];
            if (isset($pipes[2]) && is_resource($pipes[2])) $read[] = $pipes[2];

            if ($read) {
                $w = null; $e = null;
                @stream_select($read, $w, $e, 0, 200000); // 200ms
                foreach ($read as $r) {
                    $chunk = @fread($r, 8192);
                    if ($chunk === false || $chunk === '') continue;
                    if ($r === $pipes[1]) $stdout .= $chunk;
                    if ($r === $pipes[2]) $stderr .= $chunk;
                }
            }

            if (!$running) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSec) {
                $timedOut = true;
                @proc_terminate($proc);
                usleep(200000);

                $chunk1 = @stream_get_contents($pipes[1]);
                $chunk2 = @stream_get_contents($pipes[2]);
                if ($chunk1 !== false) $stdout .= $chunk1;
                if ($chunk2 !== false) $stderr .= $chunk2;
                break;
            }

            usleep(50000);
        }

        if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);

        $rc = @proc_close($proc);

        // proc_close() bywa -1 po wcześniejszym proc_get_status(); spróbuj wziąć exitcode z ostatniego statusu
        if ((!is_int($rc) || $rc < 0) && !$timedOut && is_array($lastStatus)) {
            $exit = $lastStatus['exitcode'] ?? null;
            if (is_int($exit) && $exit >= 0) {
                $rc = $exit;
            } elseif (is_numeric($exit) && (int)$exit >= 0) {
                $rc = (int)$exit;
            }
        }

        if (!is_int($rc)) $rc = $timedOut ? 124 : 0;

        // Ostatnia asekuracja na fałszywe -1 (typowe przy proc_* w niektórych środowiskach)
        if ($rc < 0 && !$timedOut) {
            $rc = 0;
        }

        if ($timedOut && $rc === 0) $rc = 124;

        return [
            'rc' => $rc,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
        ];
    }

    private static function fail(string $code, string $msg, float $t0, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'backend' => 'cups',
            'queue' => $extra['queue'] ?? null,
            'job_id' => null,
            'format' => $extra['format'] ?? null,
            'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            'error_code' => $code,
            'error_message' => $msg,
            'stdout' => $extra['stdout'] ?? '',
            'stderr' => $extra['stderr'] ?? '',
        ], $extra);
    }
}
