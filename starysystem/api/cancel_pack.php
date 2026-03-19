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
    $packer  = (string)($_SESSION['packer'] ?? '');
    $station = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));
    if ($packer === '' || $station === '') Resp::json(['ok'=>false, 'error'=>'Brak sesji packer/station'], 200);

    $in = read_input();
    $orderCode = normalize_order_code((string)($in['order_code'] ?? ''));
    if ($orderCode === null) Resp::bad('Brak lub niepoprawny order_code', 400);

    $reason = trim((string)($in['reason'] ?? ''));

    $db = Db::mysql($cfg);

    $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
    $st->execute([':c' => $orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) Resp::bad('Nie znaleziono zamówienia', 404, ['order_code' => $orderCode]);

    $status = (int)$row['status'];
    if ($status === 50) Resp::json(['ok'=>false, 'error'=>'Zamówienie już spakowane', 'order'=>$row], 200);
    if ($status === 60) Resp::json(['ok'=>true, 'order'=>$row], 200);

    $tag = '[CANCEL ' . date('Y-m-d H:i:s') . ' ' . $packer . ' ' . $station . ']';
    if ($reason !== '') $tag .= ' ' . $reason;

    if ($status === 40) {
        $upd = $db->prepare("
            UPDATE pak_orders
            SET
              status = 60,
              pack_ended_at = IF(pack_ended_at IS NULL, NOW(), pack_ended_at),
              notes = CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '\n'), :tag)
            WHERE order_code = :c
              AND status = 40
              AND packer = :packer
              AND station = :station
        ");
        $upd->execute([':tag'=>$tag, ':c'=>$orderCode, ':packer'=>$packer, ':station'=>$station]);

        if ($upd->rowCount() !== 1) {
            Resp::json(['ok'=>false, 'error'=>'Zamówienie w trakcie na innym stanowisku', 'order'=>$row], 200);
        }
    } else {
        $upd = $db->prepare("
            UPDATE pak_orders
            SET
              status = 60,
              packer = :packer,
              station = :station,
              pack_ended_at = NOW(),
              notes = CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '\n'), :tag)
            WHERE order_code = :c
              AND status < 40
        ");
        $upd->execute([':tag'=>$tag, ':c'=>$orderCode, ':packer'=>$packer, ':station'=>$station]);

        if ($upd->rowCount() !== 1) {
            $st->execute([':c'=>$orderCode]);
            $row2 = $st->fetch(PDO::FETCH_ASSOC);
            Resp::json(['ok'=>false, 'error'=>'Nie można anulować (status zmienił się)', 'order'=>$row2 ?: $row], 200);
        }
    }

    PakEvents::log($db, $orderCode, 'CANCEL', $packer, $station, $reason !== '' ? $reason : 'cancel');

    $st->execute([':c' => $orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    Resp::json(['ok'=>true, 'order'=>$row], 200);

} catch (\Throwable $e) {
    Resp::bad('cancel_pack error: ' . $e->getMessage(), 500);
}
