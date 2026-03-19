<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/ZebraTcpSender.php';
require_once __DIR__ . '/../app/Lib/CupsPrintSender.php';
require_once __DIR__ . '/../app/Services/LabelService.php';

$orderCode = $argv[1] ?? '';
$printer   = $argv[2] ?? ''; // ip albo STATION1 albo (dla cups manager/test) queue name

if ($orderCode === '' || $printer === '') {
    echo "Użycie:\n";
    echo "  php bin/print_label.php 1870409 192.168.0.29\n";
    echo "  php bin/print_label.php 1870409 STATION1\n";
    echo "  php bin/print_label.php 1870409 zebra_st8_raw   # (CUPS, jawna kolejka)\n";
    exit(1);
}

$backend = strtolower(trim((string)getenv('PRINT_BACKEND')));
if ($backend !== 'cups') $backend = 'socket';

$db = Db::mysql($cfg);

if ($backend === 'cups') {
    $lab = LabelService::getLabelForPrint($db, $cfg, $orderCode);
    $fmt = strtoupper((string)($lab['format'] ?? ''));
    if ($fmt === 'TXT') $fmt = 'ZPL';
    $payload = (string)($lab['data'] ?? '');
    if ($payload === '') {
        fwrite(STDERR, "Pusta etykieta\n");
        exit(2);
    }

    if (preg_match('/^STATION\d+$/i', $printer)) {
        $queue = CupsPrintSender::resolveQueueForStation(strtoupper($printer), $fmt);
    } else {
        $queue = trim($printer);
        if ($queue === '' || !CupsPrintSender::isValidQueueName($queue)) {
            fwrite(STDERR, "Niepoprawna kolejka CUPS: {$printer}\n");
            exit(2);
        }
    }

    $res = CupsPrintSender::send($queue, $payload, $fmt, 'CLI-'.$orderCode);
    if (empty($res['ok'])) {
        fwrite(STDERR, "CUPS print fail: " . ($res['error_message'] ?? 'unknown') . "\n");
        exit(3);
    }

    echo "OK queued order={$orderCode} queue={$queue} format={$fmt}";
    if (!empty($res['job_id'])) echo " job_id={$res['job_id']}";
    echo "\n";
    exit(0);
}

// legacy socket backend
if (preg_match('/^STATION(\d+)$/i', $printer, $m)) {
    $k = 'STATION' . (int)$m[1] . '_PRINTER_IP';
    $ip = (string)getenv($k);
    if ($ip === '') {
        fwrite(STDERR, "Brak {$k} w ENV\n");
        exit(1);
    }
    $printerIp = $ip;
} else {
    $printerIp = $printer;
}

$res = LabelService::getLabel($db, $cfg, $orderCode);
$zpl = (string)$res['data'];

$fp = @fsockopen($printerIp, 9100, $errno, $errstr, 3.0);
if (!$fp) {
    fwrite(STDERR, "Nie mogę połączyć z drukarką {$printerIp}:9100 errno={$errno} {$errstr}\n");
    exit(2);
}

stream_set_timeout($fp, 10);

$len = strlen($zpl);
$sent = 0;
while ($sent < $len) {
    $n = fwrite($fp, substr($zpl, $sent), min(8192, $len - $sent));
    if ($n === false || $n === 0) {
        $meta = stream_get_meta_data($fp);
        fclose($fp);
        $timedOut = !empty($meta['timed_out']) ? ' timeout' : '';
        fwrite(STDERR, "Błąd wysyłki do drukarki: sent={$sent}/{$len}{$timedOut}\n");
        exit(3);
    }
    $sent += $n;
}
fflush($fp);
fclose($fp);

echo "OK printed order={$orderCode} printer={$printerIp} bytes={$sent}/{$len}\n";
