<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

try {
    $db = Db::mysql($cfg);

    $sql = "
        SELECT
            COALESCE(NULLIF(carrier_code, ''), NULLIF(courier_code, ''), NULLIF(delivery_method, ''), 'unknown') AS carrier_key,
            carrier_code,
            courier_code,
            delivery_method,
            COUNT(*) AS total_count,
            SUM(CASE WHEN status = 10 THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN status = 40 THEN 1 ELSE 0 END) AS packing_count,
            SUM(CASE WHEN status = 50 THEN 1 ELSE 0 END) AS packed_count,
            SUM(CASE WHEN status = 60 THEN 1 ELSE 0 END) AS cancelled_count
        FROM pak_orders
        GROUP BY
            COALESCE(NULLIF(carrier_code, ''), NULLIF(courier_code, ''), NULLIF(delivery_method, ''), 'unknown'),
            carrier_code,
            courier_code,
            delivery_method
        ORDER BY new_count DESC, total_count DESC, carrier_key ASC
    ";

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo "COUNT=" . count($rows) . PHP_EOL . PHP_EOL;

    $i = 0;
    foreach ($rows as $r) {
        echo "---- #" . (++$i) . PHP_EOL;
        echo "carrier_key=" . ($r['carrier_key'] ?? '') . PHP_EOL;
        echo "carrier_code=" . ($r['carrier_code'] ?? '') . PHP_EOL;
        echo "courier_code=" . ($r['courier_code'] ?? '') . PHP_EOL;
        echo "delivery_method=" . ($r['delivery_method'] ?? '') . PHP_EOL;
        echo "total_count=" . ($r['total_count'] ?? 0) . PHP_EOL;
        echo "new_count=" . ($r['new_count'] ?? 0) . PHP_EOL;
        echo "packing_count=" . ($r['packing_count'] ?? 0) . PHP_EOL;
        echo "packed_count=" . ($r['packed_count'] ?? 0) . PHP_EOL;
        echo "cancelled_count=" . ($r['cancelled_count'] ?? 0) . PHP_EOL;
        echo PHP_EOL;
    }

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
