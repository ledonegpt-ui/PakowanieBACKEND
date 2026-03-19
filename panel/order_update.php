<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/api.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['token']) || $_SESSION['token'] === '') {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Brak aktywnej sesji panelu'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$orderCode = trim((string)($_GET['order_code'] ?? ''));
if ($orderCode === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Brak order_code'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    $body = array();
}

$res = panel_api_call('POST', '/panel/orders/' . rawurlencode($orderCode) . '/update', $body);

http_response_code((int)($res['_http_code'] ?? 200));
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
