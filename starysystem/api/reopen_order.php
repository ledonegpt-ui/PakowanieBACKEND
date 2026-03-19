<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';
require_once __DIR__ . '/../app/Lib/PakEvents.php';
require_once __DIR__ . '/../app/Lib/PackerAuth.php';

@session_start();

function normalize_order_code(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/\*(B\d+|E\d+|\d+)\*/i', $raw, $m)) $raw = (string)$m[1];
    $raw = strtoupper(trim($raw));
    if ($raw === '' || strlen($raw) > 32) return null;
    if (!preg_match('/^[0-9A-Z]+$/', $raw)) return null;
    return $raw;
}

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

function is_manager_name(string $packer): bool {
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

    $orderCode = normalize_order_code((string)($in['order_code'] ?? ''));
    if ($orderCode === null) Resp::bad('Brak lub niepoprawny order_code', 400);

    $reason = trim((string)($in['reason'] ?? ''));

    // KTO robi REOPEN?
    $managerName = '';
    $mode = '';

    // 1) jeśli ktoś jest zalogowany jako manager w sesji (np. queue)
    if ((string)($_SESSION['role'] ?? '') === 'manager') {
        $managerName = (string)($_SESSION['packer'] ?? '');
        $mode = 'session';
    }

    // 2) jeśli podano manager_code (kierownik podszedł i zeskanował badge)
    if ($managerName === '') {
        $managerCode = trim((string)($in['manager_code'] ?? ''));
        if ($managerCode !== '') {
            $name = PackerAuth::packerFromBarcode($managerCode) ?? '';
            if ($name !== '' && is_manager_name($name)) {
                $managerName = $name;
                $mode = 'badge';
            } else {
                Resp::json(['ok'=>false, 'error'=>'Nieprawidłowy kod kierownika (manager_code)'], 200);
            }
        }
    }

    if ($managerName === '') {
        Resp::json(['ok'=>false, 'error'=>'REOPEN wymaga kierownika (sesja manager lub manager_code)'], 200);
    }

    $station = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));

    $db = Db::mysql($cfg);

    $tag = '[REOPEN ' . date('Y-m-d H:i:s') . ' ' . $managerName . ' via:' . $mode . ']';
    if ($reason !== '') $tag .= ' reason: ' . $reason;

    // tylko CANCELLED -> NEW
    $upd = $db->prepare("
        UPDATE pak_orders
        SET
          status = 10,
          pack_started_at = NULL,
          pack_ended_at = NULL,
          packer = NULL,
          station = NULL,
          notes = CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '\n'), :tag)
        WHERE order_code = :c
          AND status = 60
    ");
    $upd->execute([':tag'=>$tag, ':c'=>$orderCode]);

    if ($upd->rowCount() !== 1) {
        $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code=:c LIMIT 1");
        $st->execute([':c'=>$orderCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) Resp::bad('Nie znaleziono zamówienia', 404, ['order_code'=>$orderCode]);
        Resp::json(['ok'=>false, 'error'=>'REOPEN dozwolony tylko dla status=60 (CANCELLED)', 'order'=>$row], 200);
    }

    PakEvents::log($db, $orderCode, 'REOPEN', $managerName ?: null, $station ?: null, $reason !== '' ? $reason : 'reopen');

    $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code=:c LIMIT 1");
    $st->execute([':c'=>$orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    Resp::json(['ok'=>true, 'order'=>$row], 200);

} catch (\Throwable $e) {
    Resp::bad('reopen error: ' . $e->getMessage(), 500);
}
