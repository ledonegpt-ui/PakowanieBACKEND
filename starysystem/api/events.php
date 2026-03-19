<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';

try {
    $db = Db::mysql($cfg);

    $code = trim((string)($_GET['order_code'] ?? ''));
    if ($code === '') Resp::bad('Brak order_code', 400);

    $st = $db->prepare("
        SELECT id, order_code, event_type, packer, station, message, created_at
        FROM pak_events
        WHERE order_code = :c
        ORDER BY created_at ASC, id ASC
        LIMIT 500
    ");
    $st->execute([':c'=>$code]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    Resp::json(['ok'=>true, 'items'=>$rows], 200);

} catch (\Throwable $e) {
    Resp::bad('events error: ' . $e->getMessage(), 500);
}
