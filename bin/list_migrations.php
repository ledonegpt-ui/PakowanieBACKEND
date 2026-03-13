<?php
declare(strict_types=1);

$dir = __DIR__ . '/../database/migrations';
$files = glob($dir . '/*.sql');
sort($files);

foreach ($files as $file) {
    echo basename($file) . PHP_EOL;
}
