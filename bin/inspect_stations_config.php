<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';

$file = __DIR__ . '/../app/Config/stations.php';
if (!is_file($file)) {
    fwrite(STDERR, "ERROR: stations.php not found" . PHP_EOL);
    exit(1);
}

$data = require $file;

echo "TYPE=" . gettype($data) . PHP_EOL;
echo "COUNT=" . (is_array($data) ? count($data) : 0) . PHP_EOL;
echo PHP_EOL;

if (!is_array($data)) {
    var_dump($data);
    exit(0);
}

$idx = 0;
foreach ($data as $key => $value) {
    echo "---- ENTRY #" . $idx . " KEY=" . (string)$key . PHP_EOL;
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if (is_scalar($v) || $v === null) {
                echo "  " . $k . " = " . (string)$v . PHP_EOL;
            } else {
                echo "  " . $k . " = [" . gettype($v) . "]" . PHP_EOL;
            }
        }
    } else {
        echo "  VALUE = " . (is_scalar($value) ? (string)$value : '[' . gettype($value) . ']') . PHP_EOL;
    }
    echo PHP_EOL;
    $idx++;
    if ($idx >= 20) {
        break;
    }
}
