<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';

$raw = trim((string)getenv('PACKERS_MAP'));
echo "PACKERS_MAP_RAW=" . $raw . PHP_EOL . PHP_EOL;

$items = [];
if ($raw !== '') {
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '') continue;

        $sep = null;
        if (strpos($part, '=') !== false) $sep = '=';
        elseif (strpos($part, ':') !== false) $sep = ':';

        if ($sep === null) continue;

        list($id, $name) = array_map('trim', explode($sep, $part, 2));
        if ($id === '' || $name === '') continue;

        $id = str_pad(preg_replace('/\D+/', '', $id), 2, '0', STR_PAD_LEFT);
        $items[] = ['id' => $id, 'name' => $name];
    }
}

echo "COUNT=" . count($items) . PHP_EOL;
foreach ($items as $row) {
    echo $row['id'] . " | " . $row['name'] . PHP_EOL;
}
