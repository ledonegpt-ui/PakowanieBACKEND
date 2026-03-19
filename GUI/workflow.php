<?php
session_start();
require_once __DIR__ . '/api.php';

if (!isset($_SESSION['token'])) {
    header("Location: index.php");
    exit;
}

$meRes = apicall('GET', '/auth/me');
if (isset($meRes['data']['auth']['station']) && is_array($meRes['data']['auth']['station'])) {
    $_SESSION['station'] = $meRes['data']['auth']['station'];
}
if (isset($meRes['data']['auth']['user']) && is_array($meRes['data']['auth']['user'])) {
    $_SESSION['user'] = $meRes['data']['auth']['user'];
}

$res = apicall('GET', '/carriers');
$carriers = $res['data']['carriers'] ?? [];

$station = isset($_SESSION['station']) && is_array($_SESSION['station']) ? $_SESSION['station'] : array();
$stationCode = isset($station['station_code']) ? (string)$station['station_code'] : '—';
$stationName = isset($station['station_name']) ? (string)$station['station_name'] : '—';
$packageMode = isset($station['package_mode']) ? (string)$station['package_mode'] : 'small';
$packageModeDefault = isset($station['package_mode_default']) ? (string)$station['package_mode_default'] : $packageMode;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Workflow</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:24px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px}
ul{margin:0;padding-left:20px}
li{margin:0 0 8px 0}
a{color:#0d6efd;text-decoration:none}
.small{font-size:12px;color:#666}
</style>
</head>
<body>

<div class="card">
    <h2>Witaj <?php echo htmlspecialchars($_SESSION['user']['display_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <div><strong>Stanowisko:</strong> <?php echo htmlspecialchars($stationCode, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($stationName, ENT_QUOTES, 'UTF-8'); ?></div>
    <div><strong>Tryb stanowiska:</strong> <?php echo htmlspecialchars($packageMode, ENT_QUOTES, 'UTF-8'); ?></div>
    <div class="small">Domyślny tryb stanowiska: <?php echo htmlspecialchars($packageModeDefault, ENT_QUOTES, 'UTF-8'); ?></div>
</div>

<div class="card">
    <h3>Wybierz kuriera</h3>
    <ul>
    <?php foreach ($carriers as $c): ?>
    <li>
        <a href="picking.php?carrier=<?php echo urlencode($c['group_key']); ?>">
            <?php echo htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int)$c['orders_count']; ?>)
        </a>
    </li>
    <?php endforeach; ?>
    </ul>
</div>

<a href="logout.php">Wyloguj</a>

</body>
</html>
