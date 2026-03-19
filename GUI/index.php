<?php
session_start();
require_once __DIR__ . '/api.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $login = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';
    $station = $_POST['station_code'] ?? '';

    $res = apicall('POST', '/auth/login', array(
        'login' => $login,
        'password' => $password,
        'station_code' => $station
    ));

    if (isset($res['data']['auth']['token'])) {

        $_SESSION['token'] = $res['data']['auth']['token'];
        $_SESSION['user']  = $res['data']['auth']['user'];
        $_SESSION['station'] = $res['data']['auth']['station'];

        header("Location: workflow.php");
        exit;

    } else {
        $error = 'Błąd logowania';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Panel Pakowania</title>
</head>
<body>

<h2>Logowanie</h2>

<?php if ($error): ?>
<p style="color:red;"><?php echo $error; ?></p>
<?php endif; ?>

<form method="post">

Login / barcode:<br>
<input type="text" name="login" value="a001"><br><br>

Password:<br>
<input type="password" name="password" value="a001"><br><br>

Station code:<br>
<input type="text" name="station_code" value="11"><br><br>

<button type="submit">Zaloguj</button>

</form>

</body>
</html>
