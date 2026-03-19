<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/api.php';

if (!isset($_SESSION['token']) || $_SESSION['token'] === '') {
    header('Location: /GUI/index.php?redirect=' . urlencode('/panel/'));
    exit;
}

$orderCode = trim((string)($_GET['order_code'] ?? ''));
if ($orderCode === '') {
    header('Location: index.php');
    exit;
}

$apiResponse = panel_api_call('GET', '/panel/orders/' . rawurlencode($orderCode));
$data = $apiResponse['data']['order'] ?? null;
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Szczegóły zamówienia <?php echo panel_h($orderCode); ?></title>
<style>
body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937}
.top{background:#111827;color:#fff;padding:14px 18px}
.top a{color:#fff;text-decoration:none}
.wrap{max-width:1200px;margin:0 auto;padding:18px}
.card{background:#fff;border:1px solid #d8dee4;border-radius:10px;padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.small{font-size:12px;color:#6b7280}
pre{white-space:pre-wrap;word-break:break-word}
</style>
</head>
<body>
<div class="top">
    <strong>Szczegóły zamówienia</strong>
    &nbsp; | &nbsp;
    <a href="index.php">← Powrót do listy</a>
</div>

<div class="wrap">
    <div class="card">
        <h2 style="margin-top:0"><?php echo panel_h($orderCode); ?></h2>
        <div class="small">Ten ekran pokaże pełne szczegóły po wdrożeniu endpointu <code>/api/v1/panel/orders/{orderCode}</code>.</div>
    </div>

    <?php if (is_array($data)): ?>
        <div class="card">
            <pre><?php echo panel_h(print_r($data, true)); ?></pre>
        </div>
    <?php else: ?>
        <div class="card">
            <pre><?php echo panel_h(print_r($apiResponse, true)); ?></pre>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
