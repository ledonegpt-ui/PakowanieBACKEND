<?php
session_start();
require_once __DIR__ . '/api.php';

function out($title, $data) {
    echo "\n==================== " . $title . " ====================\n";
    print_r($data);
    echo "\n";
}

$login = apicall('POST', '/auth/login', array(
    'login' => 'a001',
    'password' => 'a001',
    'station_code' => '11'
));
out('LOGIN', $login);

if (!isset($login['data']['auth']['token'])) {
    echo "BRAK TOKENA\n";
    exit(1);
}

$_SESSION['token'] = $login['data']['auth']['token'];

$current = apicall('GET', '/picking/batches/current');
out('CURRENT', $current);

if (!isset($current['data']['picking']['batch']['id'])) {
    $open = apicall('POST', '/picking/batches/open', array(
        'carrier_key' => 'inpost'
    ));
    out('OPEN', $open);

    if (!isset($open['data']['picking']['batch']['id'])) {
        echo "BRAK BATCHA\n";
        exit(1);
    }

    $current = $open;
}

$batch = $current['data']['picking']['batch'];
$orders = isset($current['data']['picking']['orders']) ? $current['data']['picking']['orders'] : array();

echo "BATCH ID: " . $batch['id'] . "\n";

if (empty($orders) || empty($orders[0]['items'])) {
    echo "BRAK ORDERS/ITEMS W CURRENT/OPEN\n";
    exit(1);
}

$order = $orders[0];
$item = $order['items'][0];

echo "ORDER ID: " . $order['id'] . "\n";
echo "ITEM ID: " . $item['id'] . "\n";
echo "PRODUCT: " . $item['product_name'] . "\n";

$picked = apicall('POST', '/picking/orders/' . $order['id'] . '/items/' . $item['id'] . '/picked', array());
out('PICKED', $picked);

$ordersAfter = apicall('GET', '/picking/batches/' . $batch['id'] . '/orders');
out('ORDERS AFTER', $ordersAfter);
