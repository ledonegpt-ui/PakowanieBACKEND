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

function read_post_value(string $key): string {
    if (isset($_POST[$key])) return (string)$_POST[$key];
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j[$key])) return (string)$j[$key];
    }
    return '';
}

try {
    $packer  = (string)($_SESSION['packer'] ?? '');
    $station = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));
    if ($packer === '' || $station === '') Resp::json(['ok'=>false, 'error'=>'Brak sesji packer/station'], 200);

    $orderCode = normalize_order_code(read_post_value('order_code'));
    if ($orderCode === null) Resp::bad('Brak lub niepoprawny order_code', 400);

    $db = Db::mysql($cfg);

    $upd = $db->prepare("
        UPDATE pak_orders
        SET
            status = 40,
            pack_started_at = IF(pack_started_at IS NULL, NOW(), pack_started_at),
            pack_heartbeat_at = NOW(),
            packer = :packer,
            station = :station
        WHERE order_code = :c
          AND status < 40
    ");
    $upd->execute([':packer' => $packer, ':station' => $station, ':c' => $orderCode]);

    if ($upd->rowCount() === 1) {
        PakEvents::log($db, $orderCode, 'START', $packer, $station, 'start');
        $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
        $st->execute([':c' => $orderCode]);
        Resp::json(['ok'=>true, 'order'=>$st->fetch(PDO::FETCH_ASSOC)], 200);
    }

    $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
    $st->execute([':c' => $orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) Resp::bad('Nie znaleziono zamówienia', 404, ['order_code' => $orderCode]);

    $status = (int)$row['status'];

    if ($status === 50) Resp::json(['ok'=>false, 'error'=>'Zamówienie już spakowane', 'order'=>$row], 200);

    if ($status === 40) {
        // jeżeli to samo stanowisko / ten sam packer -> odśwież heartbeat i zwróć ok (idempotentnie)
        if ((string)$row['packer'] === $packer && (string)$row['station'] === $station) {
            try {
                $hb = $db->prepare("UPDATE pak_orders SET pack_heartbeat_at = NOW() WHERE order_code = :c AND status = 40 LIMIT 1");
                $hb->execute([':c' => $orderCode]);
            } catch (\Throwable $e) {
                // brak wpływu na flow
            }
            Resp::json(['ok'=>true, 'order'=>$row], 200);
        }
        Resp::json(['ok'=>false, 'error'=>'Zamówienie w trakcie na innym stanowisku', 'order'=>$row], 200);
    }

    Resp::json(['ok'=>false, 'error'=>'Nie można rozpocząć pakowania dla tego statusu', 'order'=>$row], 200);

} catch (\Throwable $e) {
    Resp::bad('start_pack error: ' . $e->getMessage(), 500);
}
