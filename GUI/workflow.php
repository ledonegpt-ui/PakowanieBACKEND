<?php
session_start();
require_once __DIR__ . '/api.php';

if (!isset($_SESSION['token'])) {
    header("Location: index.php");
    exit;
}

$res = apicall('GET', '/carriers');
$carriers = $res['data']['carriers'] ?? [];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Workflow</title>
</head>
<body>

<h2>Witaj <?php echo $_SESSION['user']['display_name']; ?></h2>

<h3>Wybierz kuriera</h3>

<ul>
<?php foreach ($carriers as $c): ?>
<li>
<a href="picking.php?carrier=<?php echo $c['group_key']; ?>">
<?php echo $c['label']; ?> (<?php echo $c['orders_count']; ?>)
</a>
</li>
<?php endforeach; ?>
</ul>

<br>
<a href="logout.php">Wyloguj</a>

</body>
</html>
