<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Resp.php';
require_once __DIR__ . '/../app/Lib/PackerAuth.php';

@session_start();

function read_input(): array {
    $data = [];
    foreach ($_POST as $k => $v) $data[$k] = $v;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) $data = array_merge($data, $j);
    }
    return $data;
}

function is_manager(string $packer): bool {
    $list = (string)env('MANAGERS', '');
    if (trim($list) === '') return false;
    $want = strtoupper(trim($packer));
    foreach (array_filter(array_map('trim', explode(',', $list))) as $m) {
        if ($m !== '' && strtoupper($m) === $want) return true;
    }
    return false;
}

try {
    $in = read_input();

    $stationNo = (int)($in['station_no'] ?? 0);
    $stations = $cfg['stations'] ?? [];

    if ($stationNo < 1 || $stationNo > 11 || !isset($stations[$stationNo])) {
        Resp::json(['ok'=>false, 'error'=>'Niepoprawne station_no (1..11)'], 200);
    }

    $stationName = (string)($stations[$stationNo]['name'] ?? '');
    $printerIp   = (string)($stations[$stationNo]['printer_ip'] ?? '');

    $packerCode = trim((string)($in['packer_code'] ?? ''));
    $packer = $packerCode !== '' ? (PackerAuth::packerFromBarcode($packerCode) ?? '') : trim((string)($in['packer'] ?? ''));

    if (mb_strlen($packer, 'UTF-8') < 2) {
        Resp::json(['ok'=>false, 'error'=>'Zły login (nieznany kod pakowacza)'], 200);
    }

    if ($printerIp !== '' && filter_var($printerIp, FILTER_VALIDATE_IP) === false) {
        Resp::json(['ok'=>false, 'error'=>'Błędny printer_ip w configu stanowiska'], 200);
    }

    $_SESSION['packer'] = $packer;
    $_SESSION['station_no'] = $stationNo;
    $_SESSION['station_name'] = $stationName;
    $_SESSION['station'] = $stationName;
    $_SESSION['printer_ip'] = $printerIp;

    $role = is_manager($packer) ? 'manager' : 'packer';
    $_SESSION['role'] = $role;
    $_SESSION['manager'] = ($role === 'manager') ? 1 : 0;

    Resp::json([
        'ok'=>true,
        'packer'=>$packer,
        'role'=>$role,
        'station_no'=>$stationNo,
        'station_name'=>$stationName,
        'printer_ip'=>$printerIp,
        'has_station'=>true,
    ], 200);

} catch (\Throwable $e) {
    Resp::bad('login error: ' . $e->getMessage(), 500);
}
