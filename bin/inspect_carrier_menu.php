<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Modules/Carriers/Repositories/CarriersRepository.php';
require_once __DIR__ . '/../app/Modules/Carriers/Services/CarriersService.php';

$db = Db::mysql($cfg);
$mapCfg = require __DIR__ . '/../app/Config/shipping_map.php';

$repo = new CarriersRepository($db);
$service = new CarriersService($repo, $mapCfg);

$data = $service->listQueueSummary();

foreach ($data as $row) {
    echo $row['group_key'] . " | " . $row['label'] . " | " . $row['orders_count'] . PHP_EOL;
    foreach ($row['sample_methods'] as $sample) {
        echo "  - " . $sample['method'] . " | " . $sample['count'] . PHP_EOL;
    }
    echo PHP_EOL;
}
