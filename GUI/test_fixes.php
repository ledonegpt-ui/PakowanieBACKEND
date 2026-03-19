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

$current1 = apicall('GET', '/picking/batches/current');
out('CURRENT BEFORE OPEN', $current1);

$open = apicall('POST', '/picking/batches/open', array(
    'carrier_key' => 'inpost'
));
out('OPEN', $open);

$current2 = apicall('GET', '/picking/batches/current');
out('CURRENT AFTER OPEN', $current2);

$batchId = null;

$candidates = array(
    $open,
    $current2,
    $current1
);

foreach ($candidates as $src) {
    if (isset($src['data']['picking']['batch']['id'])) {
        $batchId = $src['data']['picking']['batch']['id'];
        break;
    }
    if (isset($src['data']['batch']['id'])) {
        $batchId = $src['data']['batch']['id'];
        break;
    }
}

echo "\nBATCH ID = " . var_export($batchId, true) . "\n";

if (!$batchId) {
    echo "NIE UDAŁO SIĘ USTALIĆ BATCH ID\n";
    exit(1);
}

$products = apicall('GET', '/picking/batches/' . $batchId . '/products');
out('PRODUCTS', $products);

$orders = apicall('GET', '/picking/batches/' . $batchId . '/orders');
out('ORDERS', $orders);

$hasItems = false;

if (isset($orders['data']['orders']) && is_array($orders['data']['orders'])) {
    foreach ($orders['data']['orders'] as $order) {
        if (
            (isset($order['items']) && is_array($order['items']) && count($order['items']) > 0) ||
            (isset($order['positions']) && is_array($order['positions']) && count($order['positions']) > 0) ||
            (isset($order['order_items']) && is_array($order['order_items']) && count($order['order_items']) > 0)
        ) {
            $hasItems = true;
            break;
        }
    }
}

echo "\nHAS_ITEMS_IN_ORDERS = " . ($hasItems ? 'YES' : 'NO') . "\n";
