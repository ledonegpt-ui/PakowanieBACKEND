<?php
declare(strict_types=1);

final class ZebraTcpSender
{
    public static function send(string $ip, string $zpl, int $port = 9100, int $timeoutMs = 2500, int $retries = 2): array
    {
        $startAll = microtime(true);
        $attempts = 0;
        $lastCode = null;
        $lastMsg  = null;

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [
                'ok' => false,
                'duration_ms' => (int)round((microtime(true) - $startAll) * 1000),
                'attempts' => 0,
                'error_code' => 'BAD_IP',
                'error_message' => 'Błędny adres IP drukarki',
            ];
        }

        $timeoutSec = max(1, (int)ceil($timeoutMs / 1000));

        for ($i = 0; $i <= $retries; $i++) {
            $attempts++;
            $t0 = microtime(true);

            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($ip, $port, $errno, $errstr, (float)$timeoutSec);

            if (!$fp) {
                $lastCode = 'CONNECT_FAIL';
                $lastMsg  = "fsockopen errno={$errno} err={$errstr}";
                usleep(200000 * $attempts);
                continue;
            }

            stream_set_timeout($fp, $timeoutSec);

            $len = strlen($zpl);
            $sent = 0;
            $ok = true;

            while ($sent < $len) {
                $w = @fwrite($fp, substr($zpl, $sent));
                if ($w === false || $w === 0) {
                    $ok = false;
                    $meta = stream_get_meta_data($fp);
                    $lastCode = (!empty($meta['timed_out'])) ? 'WRITE_TIMEOUT' : 'WRITE_FAIL';
                    $lastMsg  = 'Nie udało się wysłać danych do drukarki';
                    break;
                }
                $sent += $w;
            }

            @fclose($fp);

            if ($ok) {
                return [
                    'ok' => true,
                    'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
                    'attempts' => $attempts,
                    'error_code' => null,
                    'error_message' => null,
                ];
            }

            usleep(200000 * $attempts);
        }

        return [
            'ok' => false,
            'duration_ms' => (int)round((microtime(true) - $startAll) * 1000),
            'attempts' => $attempts,
            'error_code' => $lastCode,
            'error_message' => $lastMsg,
        ];
    }
}
