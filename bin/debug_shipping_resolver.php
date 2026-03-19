<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Support/ShippingMethodResolver.php';

$cfg = require __DIR__ . '/../app/Config/shipping_map.php';
$resolver = new ShippingMethodResolver($cfg);

$samples = [
    ['delivery_method' => 'Allegro Kurier DPD (AD) (Smart)', 'carrier_code' => '', 'courier_code' => ''],
    ['delivery_method' => 'Kurier DPD', 'carrier_code' => '', 'courier_code' => ''],
    ['delivery_method' => 'Allegro One Box, DPD (Smart)', 'carrier_code' => '', 'courier_code' => ''],
    ['delivery_method' => 'Allegro One Box, One Kurier (Smart)', 'carrier_code' => '', 'courier_code' => ''],
    ['delivery_method' => 'Paczkomat InPost', 'carrier_code' => '', 'courier_code' => 'paczkomaty'],
    ['delivery_method' => 'ERLI InPost Paczkomaty 24/7 - 25 kg', 'carrier_code' => '', 'courier_code' => 'erlipro'],
    ['delivery_method' => 'GLS', 'carrier_code' => '', 'courier_code' => 'gls'],
    ['delivery_method' => 'Allegro Automat ORLEN Paczka (Smart)', 'carrier_code' => '', 'courier_code' => ''],
];

foreach ($samples as $sample) {
    echo "==== INPUT ====" . PHP_EOL;
    echo json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "==== OUTPUT ====" . PHP_EOL;
    echo json_encode($resolver->resolve($sample), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo PHP_EOL;
}
