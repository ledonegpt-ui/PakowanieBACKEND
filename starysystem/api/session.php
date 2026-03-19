<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Lib/Resp.php';
@session_start();

$packer = (string)($_SESSION['packer'] ?? '');
$role = (string)($_SESSION['role'] ?? 'packer');

$stationNo = (int)($_SESSION['station_no'] ?? 0);
$station = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));
$printer = (string)($_SESSION['printer_ip'] ?? '');

if ($packer === '') {
    Resp::json(['ok'=>false], 200);
}

Resp::json([
    'ok'=>true,
    'packer'=>$packer,
    'role'=>$role,
    'has_station'=>($stationNo > 0 && $station !== ''),
    'station_no'=>$stationNo,
    'station_name'=>$station,
    'printer_ip'=>$printer,
], 200);
