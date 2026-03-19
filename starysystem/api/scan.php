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

    // pozwól na *ID* w skanie
    if (preg_match('/\*(B\d+|E\d+|\d+)\*/i', $raw, $m)) {
        $raw = (string)$m[1];
    }

    $raw = strtoupper(trim($raw));
    if ($raw === '' || strlen($raw) > 32) return null;
    if (!preg_match('/^[0-9A-Z]+$/', $raw)) return null;
    return $raw;
}

try {
    $mysql = Db::mysql($cfg);

    $raw = (string)($_GET['order_code'] ?? $_POST['order_code'] ?? $_GET['code'] ?? $_POST['code'] ?? '');
    $orderCode = normalize_order_code($raw);
    if ($orderCode === null) {
        Resp::bad('Brak lub niepoprawny order_code', 400);
    }

    $st = $mysql->prepare("
        SELECT
            order_code, status,
            subiekt_doc_no, subiekt_doc_id,
            delivery_method,
            pack_started_at, pack_ended_at, packer, station
        FROM pak_orders
        WHERE order_code = :c
        LIMIT 1
    ");
    $st->execute([':c' => $orderCode]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        Resp::bad('Nie znaleziono zamówienia', 404, ['order_code' => $orderCode]);
    }

    // items: symbol + nazwa + opis + ilość + image_url
    $it = $mysql->prepare("
        SELECT
            subiekt_symbol,
            name,
            subiekt_desc,
            quantity,
            image_url
        FROM pak_order_items
        WHERE order_code = :c
          AND line_key LIKE 'SUB-%'
        ORDER BY item_id ASC
    ");
    $it->execute([':c' => $orderCode]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // normalizacja (żeby frontend zawsze miał string albo null)
    foreach ($items as &$row) {
        $img = isset($row['image_url']) ? trim((string)$row['image_url']) : '';
        $row['image_url'] = ($img !== '') ? $img : null;
    }
    unset($row);

    $resp = [
        'ok' => true,
        'order_code' => $order['order_code'],
        'status' => (int)$order['status'],
        'subiekt_doc_no' => $order['subiekt_doc_no'],
        'subiekt_doc_id' => ($order['subiekt_doc_id'] !== null ? (int)$order['subiekt_doc_id'] : null),
        'delivery_method' => $order['delivery_method'],
        'pack_started_at' => $order['pack_started_at'],
        'pack_ended_at' => $order['pack_ended_at'],
        'packer' => $order['packer'],
        'station' => $order['station'],
        'items' => $items,
    ];

    if (!$items) {
        $resp['warning'] = 'Brak pozycji z Subiekta';
        $resp['items'] = [];
    }

    // LOGUJ SCAN TYLKO GDY ?log=1 (żeby order.php nie nabijał śmieci)
    $doLog = ((string)($_GET['log'] ?? '')) === '1';
    $status = (int)$order['status'];
    if ($doLog && ($status === 10 || $status === 40)) {
        $p = (string)($_SESSION['packer'] ?? '');
        $s = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));
        PakEvents::log($mysql, $orderCode, 'SCAN', $p ?: null, $s ?: null, 'scan');
    }

    Resp::json($resp, 200);

} catch (\Throwable $e) {
    Resp::bad('scan error: ' . $e->getMessage(), 500);
}