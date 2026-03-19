<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

try {
    $db = Db::mysql($cfg);

    echo "===== SERVER =====\n";
    $row = $db->query("SELECT VERSION() AS v, DATABASE() AS db")->fetch(PDO::FETCH_ASSOC);
    echo "version=" . ($row['v'] ?? '') . "\n";
    echo "database=" . ($row['db'] ?? '') . "\n\n";

    echo "===== EXISTING PAK TABLES =====\n";
    $sql = "
        SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('pak_orders', 'pak_order_items')
        ORDER BY TABLE_NAME
    ";
    foreach ($db->query($sql) as $r) {
        echo $r['TABLE_NAME'] . " | " . $r['ENGINE'] . " | " . $r['TABLE_COLLATION'] . "\n";
    }
    echo "\n";

    echo "===== NEW API TABLES ALREADY PRESENT? =====\n";
    $tables = [
        'users',
        'user_roles',
        'stations',
        'user_station_sessions',
        'shipping_providers',
        'shipping_rule_sets',
        'shipping_rules',
        'picking_batches',
        'picking_batch_orders',
        'picking_order_items',
        'picking_batch_items',
        'picking_events',
        'packing_sessions',
        'packing_session_items',
        'packages',
        'package_labels',
        'packing_events',
        'order_events',
        'api_request_logs',
        'workflow_errors'
    ];

    $st = $db->prepare("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
    ");

    foreach ($tables as $t) {
        $st->execute([':t' => $t]);
        $ok = $st->fetch(PDO::FETCH_ASSOC) ? 'YES' : 'NO';
        echo $t . "=" . $ok . "\n";
    }
    echo "\n";

    echo "===== SHOW CREATE TABLE pak_orders =====\n";
    $row = $db->query("SHOW CREATE TABLE pak_orders")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo ($row['Create Table'] ?? '') . "\n";
    }
    echo "\n";

    echo "===== SHOW CREATE TABLE pak_order_items =====\n";
    $row = $db->query("SHOW CREATE TABLE pak_order_items")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo ($row['Create Table'] ?? '') . "\n";
    }
    echo "\n";

    echo "===== SAMPLE COLUMNS pak_orders =====\n";
    $sql = "
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pak_orders'
        ORDER BY ORDINAL_POSITION
    ";
    foreach ($db->query($sql) as $r) {
        echo implode(' | ', [
            $r['COLUMN_NAME'],
            $r['COLUMN_TYPE'],
            $r['IS_NULLABLE'],
            (string)$r['COLUMN_DEFAULT'],
        ]) . "\n";
    }
    echo "\n";

    echo "===== SAMPLE COLUMNS pak_order_items =====\n";
    $sql = "
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pak_order_items'
        ORDER BY ORDINAL_POSITION
    ";
    foreach ($db->query($sql) as $r) {
        echo implode(' | ', [
            $r['COLUMN_NAME'],
            $r['COLUMN_TYPE'],
            $r['IS_NULLABLE'],
            (string)$r['COLUMN_DEFAULT'],
        ]) . "\n";
    }
    echo "\n";

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
