<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';
require_once __DIR__ . '/../app/Lib/ZebraTcpSender.php';
require_once __DIR__ . '/../app/Lib/PrintLogs.php';

@session_start();

function escZpl(string $s): string {
    $s = preg_replace('/[\x00-\x1F\x7F]/', ' ', $s);
    return (string)$s;
}

try {
    $packer    = (string)($_SESSION['packer'] ?? '');
    $role      = (string)($_SESSION['role'] ?? 'packer');
    $stationNo = (int)($_SESSION['station_no'] ?? 0);
    $station   = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));
    $printer   = (string)($_SESSION['printer_ip'] ?? '');

    if ($packer === '' || $station === '') Resp::json(['ok'=>false, 'error'=>'Brak sesji packer/station'], 200);
    if ($printer === '') Resp::json(['ok'=>false, 'error'=>'Brak printer_ip dla stanowiska'], 200);

    $ts = date('Y-m-d H:i:s');
    $zpl =
        "^XA\n" .
        "^CF0,40\n" .
        "^FO20,20^FDTEST DRUKU^FS\n" .
        "^CF0,30\n" .
        "^FO20,70^FDStanowisko: " . escZpl($station) . "^FS\n" .
        "^FO20,110^FDUzytkownik: " . escZpl($packer) . " (" . escZpl($role) . ")^FS\n" .
        "^FO20,150^FDTimestamp: " . escZpl($ts) . "^FS\n" .
        "^XZ\n";

    $res = ZebraTcpSender::send($printer, $zpl, 9100, 2500, 2);

    $db = Db::mysql($cfg);
    PrintLogs::log($db, [
        'type' => 'TEST',
        'station_name' => $station,
        'station_no' => $stationNo ?: null,
        'order_code' => null,
        'printer_ip' => $printer,
        'success' => $res['ok'],
        'duration_ms' => $res['duration_ms'],
        'error_code' => $res['error_code'],
        'error_message' => $res['error_message'],
        'zpl_bytes' => strlen($zpl),
    ]);

    Resp::json(['ok'=>$res['ok'], 'result'=>$res], 200);

} catch (\Throwable $e) {
    Resp::bad('print_test error: ' . $e->getMessage(), 500);
}
