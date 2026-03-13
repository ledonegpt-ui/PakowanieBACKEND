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
    $packerCode = trim((string)($in['packer_code'] ?? ''));
    $packer = $packerCode !== '' ? (PackerAuth::packerFromBarcode($packerCode) ?? '') : trim((string)($in['packer'] ?? ''));

    if (mb_strlen($packer, 'UTF-8') < 2) {
        Resp::json(['ok'=>false, 'error'=>'Zły login'], 200);
    }

    $_SESSION['packer'] = $packer;
    $role = is_manager($packer) ? 'manager' : 'packer';
    $_SESSION['role'] = $role;
    $_SESSION['manager'] = ($role === 'manager') ? 1 : 0;

    // queue nie potrzebuje station/printer
    unset($_SESSION['station_no'], $_SESSION['station_name'], $_SESSION['station'], $_SESSION['printer_ip']);

    Resp::json(['ok'=>true, 'packer'=>$packer, 'role'=>$role], 200);

} catch (\Throwable $e) {
    Resp::bad('login_queue error: ' . $e->getMessage(), 500);
}
