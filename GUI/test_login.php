<?php

session_start();
require_once __DIR__ . '/api.php';

$res = apicall('POST', '/auth/login', array(
    'login' => 'a001',
    'password' => 'a001',
    'station_code' => '11'
));

print_r($res);

if (isset($res['data']['token'])) {
    $_SESSION['token'] = $res['data']['token'];
    echo "\nTOKEN ZAPISANY\n";
}
