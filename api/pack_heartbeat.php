<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';

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

    if ($packer === '' || $station === '') {
        Resp::json(['ok'=>false, 'error'=>'Brak sesji packer/station'], 200);
    }

    $orderCode = normalize_order_code(read_post_value('order_code'));
    if ($orderCode === null) {
        Resp::bad('Brak lub niepoprawny order_code', 400);
    }

    $db = Db::mysql($cfg);

    // heartbeat tylko dla aktywnego pakowania na tym stanowisku i przez tego packera
    $upd = $db->prepare("
        UPDATE pak_orders
        SET pack_heartbeat_at = NOW()
        WHERE order_code = :c
          AND status = 40
          AND packer = :packer
          AND station = :station
        LIMIT 1
    ");
    $upd->execute([
        ':c' => $orderCode,
        ':packer' => $packer,
        ':station' => $station,
    ]);

    // rowCount może być 0 (np. ta sama sekunda), więc sprawdzamy stan
    $st = $db->prepare("
        SELECT order_code, status, packer, station, pack_started_at, pack_heartbeat_at
        FROM pak_orders
        WHERE order_code = :c
        LIMIT 1
    ");
    $st->execute([':c' => $orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        Resp::json(['ok'=>false, 'error'=>'Nie znaleziono zamówienia', 'order_code'=>$orderCode], 200);
    }

    if ((int)$row['status'] !== 40 || (string)$row['packer'] !== $packer || (string)$row['station'] !== $station) {
        Resp::json(['ok'=>false, 'error'=>'Heartbeat odrzucony (zamówienie nieaktywne na tym stanowisku)', 'order'=>$row], 200);
    }

    Resp::json([
        'ok' => true,
        'order_code' => $orderCode,
        'server_time' => date('Y-m-d H:i:s'),
        'pack_started_at' => $row['pack_started_at'] ?? null,
        'pack_heartbeat_at' => $row['pack_heartbeat_at'] ?? null,
    ], 200);

} catch (\Throwable $e) {
    Resp::bad('heartbeat error: ' . $e->getMessage(), 500);
}
