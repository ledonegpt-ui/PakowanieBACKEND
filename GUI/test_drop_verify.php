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

$batchId = 22;
$droppedOrderId = 108;

$orders = apicall('GET', '/picking/batches/' . $batchId . '/orders');
out('ORDERS', $orders);

$found = false;
$foundOrder = null;

if (isset($orders['data']['orders']) && is_array($orders['data']['orders'])) {
    foreach ($orders['data']['orders'] as $order) {
        if (isset($order['id']) && (int)$order['id'] === $droppedOrderId) {
            $found = true;
            $foundOrder = $order;
            break;
        }
    }
}

echo "\n==================== RESULT ====================\n";
echo "BATCH ID: " . $batchId . "\n";
echo "DROPPED ORDER ID: " . $droppedOrderId . "\n";
echo "STILL PRESENT IN /orders: " . ($found ? 'YES' : 'NO') . "\n";

if ($found) {
    echo "\n==================== FOUND ORDER ====================\n";
    print_r($foundOrder);
}
