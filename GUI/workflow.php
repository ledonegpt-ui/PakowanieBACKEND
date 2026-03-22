<?php
session_start();
require_once __DIR__ . '/api.php';

if (!isset($_SESSION['token'])) {
    header('Location: index.php');
    exit;
}

// Odśwież dane usera i stacji
$meRes = apicall('GET', '/auth/me');
if (isset($meRes['data']['auth']['station']) && is_array($meRes['data']['auth']['station'])) {
    $_SESSION['station'] = $meRes['data']['auth']['station'];
}
if (isset($meRes['data']['auth']['user']) && is_array($meRes['data']['auth']['user'])) {
    $_SESSION['user'] = $meRes['data']['auth']['user'];
}

// Zapytaj backend co operator powinien teraz robić
$workflowRes = apicall('GET', '/workflow/status');
$workflow    = isset($workflowRes['data']['workflow']) && is_array($workflowRes['data']['workflow'])
    ? $workflowRes['data']['workflow']
    : null;

$action = $workflow !== null ? (string)($workflow['action'] ?? 'start_picking') : 'start_picking';

// Przekierowania recovery
if ($action === 'resume_packing') {
    $orderCode = isset($workflow['order_code']) ? (string)$workflow['order_code'] : '';
    $batchId   = isset($workflow['batch_id'])   ? (int)$workflow['batch_id']   : 0;
    $url = 'packing.php?order_code=' . urlencode($orderCode) . '&batch_id=' . $batchId;
    header('Location: ' . $url);
    exit;
}

if ($action === 'resume_picking') {
    $carrierKey = isset($workflow['carrier_key']) ? (string)$workflow['carrier_key'] : '';
    $url = 'picking.php' . ($carrierKey !== '' ? '?carrier=' . urlencode($carrierKey) : '');
    header('Location: ' . $url);
    exit;
}

if ($action === 'start_packing') {
    $batchId    = isset($workflow['batch_id'])    ? (int)$workflow['batch_id']    : 0;
    $carrierKey = isset($workflow['carrier_key']) ? (string)$workflow['carrier_key'] : '';
    $url = 'packing.php?batch_id=' . $batchId;
    if ($carrierKey !== '') {
        $url .= '&carrier=' . urlencode($carrierKey);
    }
    header('Location: ' . $url);
    exit;
}

// start_picking — pokaż listę kurierów
$carriersRes = apicall('GET', '/carriers');
$carriers    = isset($carriersRes['data']['carriers']) && is_array($carriersRes['data']['carriers'])
    ? $carriersRes['data']['carriers']
    : [];

$station           = isset($_SESSION['station']) && is_array($_SESSION['station']) ? $_SESSION['station'] : [];
$stationCode       = isset($station['station_code'])       ? (string)$station['station_code']       : '—';
$stationName       = isset($station['station_name'])       ? (string)$station['station_name']       : '—';
$packageMode       = isset($station['package_mode'])       ? (string)$station['package_mode']       : 'small';
$packageModeDefault = isset($station['package_mode_default']) ? (string)$station['package_mode_default'] : $packageMode;
$displayName       = isset($_SESSION['user']['display_name']) ? (string)$_SESSION['user']['display_name'] : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Workflow — pakowanie</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:24px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px}
h2{margin:0 0 10px 0}
h3{margin:0 0 10px 0}
ul{margin:4px 0 0 0;padding-left:20px}
li{margin:0 0 8px 0}
a{color:#0d6efd;text-decoration:none}
a:hover{text-decoration:underline}
.btn{display:inline-block;padding:8px 14px;background:#222;color:#fff;border-radius:4px;text-decoration:none;border:0;cursor:pointer;font-size:14px;margin-top:4px}
.btn:hover{background:#444;text-decoration:none}
.small{font-size:12px;color:#666}
.count{font-weight:bold;color:#198754}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#e9ecef;font-size:12px}
.alert-info{background:#cfe2ff;border:1px solid #9ec5fe;padding:10px 14px;border-radius:6px;margin-bottom:12px}
</style>
</head>
<body>

<div class="card">
    <h2>Witaj, <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
    <div><strong>Stanowisko:</strong> <?php echo htmlspecialchars($stationCode, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($stationName, ENT_QUOTES, 'UTF-8'); ?></div>
    <div><strong>Tryb pakowania:</strong> <span class="badge"><?php echo htmlspecialchars($packageMode, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="small">(domyślny: <?php echo htmlspecialchars($packageModeDefault, ENT_QUOTES, 'UTF-8'); ?>)</span>
    </div>
</div>

<?php if (empty($carriers)): ?>
    <div class="card">
        <div class="alert-info">Brak zamówień oczekujących na picking.</div>
    </div>
<?php else: ?>
    <div class="card">
        <h3>Wybierz kuriera — rozpocznij picking</h3>
        <ul>
        <?php foreach ($carriers as $c): ?>
            <li>
                <a href="picking.php?carrier=<?php echo urlencode($c['group_key']); ?>">
                    <?php echo htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="count">(<?php echo (int)$c['orders_count']; ?>)</span>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<a href="logout.php" class="btn">Wyloguj</a>

</body>
</html>
