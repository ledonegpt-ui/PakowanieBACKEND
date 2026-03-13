<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';
require_once __DIR__ . '/../app/Lib/PakEvents.php';

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

try {
    $role = (string)($_SESSION['role'] ?? 'packer');
    if ($role !== 'manager') {
        Resp::json(['ok'=>false, 'error'=>'Brak uprawnień (manager required)'], 200);
    }

    $manager = (string)($_SESSION['packer'] ?? '');
    $station = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));

    $in = read_input();
    $orderCode = normalize_order_code((string)($in['order_code'] ?? ''));
    if ($orderCode === null) Resp::bad('Brak lub niepoprawny order_code', 400);

    $reason = trim((string)($in['reason'] ?? ''));

    $db = Db::mysql($cfg);

    $tag = '[UNLOCK ' . date('Y-m-d H:i:s') . ' ' . $manager . ']';
    if ($reason !== '') $tag .= ' reason: ' . $reason;

    // atomowo: tylko gdy status=40
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
          AND status = 40
    ");
    $upd->execute([':tag'=>$tag, ':c'=>$orderCode]);

    if ($upd->rowCount() !== 1) {
        $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code=:c LIMIT 1");
        $st->execute([':c'=>$orderCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) Resp::bad('Nie znaleziono zamówienia', 404, ['order_code'=>$orderCode]);
        Resp::json(['ok'=>false, 'error'=>'UNLOCK dozwolony tylko dla status=40', 'order'=>$row], 200);
    }

    PakEvents::log($db, $orderCode, 'UNLOCK', $manager ?: null, $station ?: null, $reason !== '' ? $reason : 'unlock');

    $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code=:c LIMIT 1");
    $st->execute([':c'=>$orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    Resp::json(['ok'=>true, 'order'=>$row], 200);

} catch (\Throwable $e) {
    Resp::bad('unlock error: ' . $e->getMessage(), 500);
}
