<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

$pdo = Db::mysql($cfg);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
          AND COLUMN_NAME = :c
        LIMIT 1
    ");
    $st->execute([':t'=>$table, ':c'=>$col]);
    return (bool)$st->fetchColumn();
}

$table = 'pak_orders';
$col = 'allegro_parcel_id';

if (!col_exists($pdo, $table, $col)) {
    echo "ADD {$table}.{$col}\n";
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} VARCHAR(64) NULL");
} else {
    echo "OK  {$table}.{$col} exists\n";
}

echo "DONE\n";
