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

// proponowane kolumny (neutralne i przydatne do drukowania)
$cols = [
    'nr_nadania' => "VARCHAR(64) NULL",
    'courier_code' => "VARCHAR(32) NULL",
    'courier_inner_number' => "VARCHAR(64) NULL",
    'bl_package_id' => "INT NULL",
];

foreach ($cols as $name => $ddl) {
    if (!col_exists($pdo, $table, $name)) {
        echo "ADD {$table}.{$name}\n";
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$ddl}");
    } else {
        echo "OK  {$table}.{$name} exists\n";
    }
}

echo "DONE\n";
