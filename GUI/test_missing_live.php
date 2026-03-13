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
out('CURRENT BEFORE', $current);

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

$batchId = $current['data']['picking']['batch']['id'];
$orders = isset($current['data']['picking']['orders']) ? $current['data']['picking']['orders'] : array();

if (empty($orders)) {
    $ordersRes = apicall('GET', '/picking/batches/' . $batchId . '/orders');
    out('ORDERS FETCH', $ordersRes);
    $orders = isset($ordersRes['data']['orders']) ? $ordersRes['data']['orders'] : array();
}

$order = null;
$item = null;

foreach ($orders as $o) {
    if (!isset($o['items']) || !is_array($o['items'])) {
        continue;
    }

    foreach ($o['items'] as $it) {
        if (isset($it['status']) && $it['status'] === 'pending') {
            $order = $o;
            $item = $it;
            break 2;
        }
    }
}

if (!$order || !$item) {
    echo "BRAK PENDING ITEM\n";
    exit(1);
}

echo "BATCH ID: " . $batchId . "\n";
echo "ORDER ID: " . $order['id'] . "\n";
echo "ITEM ID: " . $item['id'] . "\n";
echo "STATUS BEFORE: " . $item['status'] . "\n";
echo "PRODUCT: " . $item['product_name'] . "\n";

$missing = apicall('POST', '/picking/orders/' . $order['id'] . '/items/' . $item['id'] . '/missing', array());
out('MISSING', $missing);

$ordersAfter = apicall('GET', '/picking/batches/' . $batchId . '/orders');
out('ORDERS AFTER', $ordersAfter);

$found = null;
if (isset($ordersAfter['data']['orders']) && is_array($ordersAfter['data']['orders'])) {
    foreach ($ordersAfter['data']['orders'] as $o) {
        if ((int)$o['id'] !== (int)$order['id']) {
            continue;
        }
        if (!isset($o['items']) || !is_array($o['items'])) {
            continue;
        }
        foreach ($o['items'] as $it) {
            if ((int)$it['id'] === (int)$item['id']) {
                $found = $it;
                break 2;
            }
        }
    }
}

echo "\n==================== FINAL STATUS ====================\n";
if ($found) {
    print_r($found);
} else {
    echo "ITEM NOT FOUND AFTER\n";
}
