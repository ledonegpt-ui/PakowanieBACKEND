<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

$db = Db::mysql($cfg);

function tableExists(PDO $db, string $table): bool {
    $st = $db->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
        LIMIT 1
    ");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function columnExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
          AND COLUMN_NAME = :c
        LIMIT 1
    ");
    $st->execute([':t' => $table, ':c' => $col]);
    return (bool)$st->fetchColumn();
}

function indexExists(PDO $db, string $table, string $idx): bool {
    $st = $db->prepare("
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
          AND INDEX_NAME = :i
        LIMIT 1
    ");
    $st->execute([':t' => $table, ':i' => $idx]);
    return (bool)$st->fetchColumn();
}

function execSql(PDO $db, string $sql): void {
    echo "SQL> $sql\n";
    $db->exec($sql);
}

if (!tableExists($db, 'pak_orders')) {
    throw new RuntimeException('Brak tabeli pak_orders');
}
if (!tableExists($db, 'pak_events')) {
    throw new RuntimeException('Brak tabeli pak_events');
}

/* 1) Kolumna heartbeat */
if (!columnExists($db, 'pak_orders', 'pack_heartbeat_at')) {
    // jeśli istnieje pack_started_at -> dodaj po niej, inaczej po prostu na końcu
    if (columnExists($db, 'pak_orders', 'pack_started_at')) {
        execSql($db, "ALTER TABLE pak_orders ADD COLUMN pack_heartbeat_at DATETIME NULL DEFAULT NULL AFTER pack_started_at");
    } else {
        execSql($db, "ALTER TABLE pak_orders ADD COLUMN pack_heartbeat_at DATETIME NULL DEFAULT NULL");
    }
} else {
    echo "OK: kolumna pak_orders.pack_heartbeat_at już istnieje\n";
}

/* 2) Indeksy pak_orders pod queue i raporty */
$pakOrdersIndexes = [
    'idx_po_status_imported'      => "CREATE INDEX idx_po_status_imported ON pak_orders (status, imported_at)",
    'idx_po_status_started'       => "CREATE INDEX idx_po_status_started ON pak_orders (status, pack_started_at)",
    'idx_po_status_heartbeat'     => "CREATE INDEX idx_po_status_heartbeat ON pak_orders (status, pack_heartbeat_at)",
    'idx_po_status_ended'         => "CREATE INDEX idx_po_status_ended ON pak_orders (status, pack_ended_at)",
    'idx_po_status_packer_ended'  => "CREATE INDEX idx_po_status_packer_ended ON pak_orders (status, packer, pack_ended_at)",
    'idx_po_subiekt_doc_no'       => "CREATE INDEX idx_po_subiekt_doc_no ON pak_orders (subiekt_doc_no)",
];

foreach ($pakOrdersIndexes as $name => $sql) {
    if (!indexExists($db, 'pak_orders', $name)) {
        execSql($db, $sql);
    } else {
        echo "OK: indeks pak_orders.$name już istnieje\n";
    }
}

/* 3) Indeksy pak_events pod raporty i unlock/finish lookup */
$pakEventsIndexes = [
    'idx_pe_packer_created_type' => "CREATE INDEX idx_pe_packer_created_type ON pak_events (packer, created_at, event_type)",
    'idx_pe_order_type_created'  => "CREATE INDEX idx_pe_order_type_created ON pak_events (order_code, event_type, created_at)",
];

foreach ($pakEventsIndexes as $name => $sql) {
    if (!indexExists($db, 'pak_events', $name)) {
        execSql($db, $sql);
    } else {
        echo "OK: indeks pak_events.$name już istnieje\n";
    }
}

echo "DONE\n";
