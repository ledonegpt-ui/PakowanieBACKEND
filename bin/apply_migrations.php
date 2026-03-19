<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function normalize_sql(string $sql): string
{
    $sql = str_replace("\r\n", "\n", $sql);
    return trim($sql);
}

function split_sql_statements(string $sql): array
{
    $sql = normalize_sql($sql);

    $lines = explode("\n", $sql);
    $filtered = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (strpos($trim, '-- ') === 0 || $trim === '--' || $trim === '') {
            continue;
        }
        $filtered[] = $line;
    }

    $sql = trim(implode("\n", $filtered));
    if ($sql === '') {
        return [];
    }

    $parts = preg_split('/;\s*(?:\n|$)/', $sql);
    $out = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return $out;
}

$mode = $argv[1] ?? 'plan';
if (!in_array($mode, ['plan', 'apply'], true)) {
    fwrite(STDERR, "Usage: php bin/apply_migrations.php [plan|apply]" . PHP_EOL);
    exit(1);
}

$dir = __DIR__ . '/../database/migrations';
$files = glob($dir . '/*.sql');
sort($files);

if (!$files) {
    out('No migration files found.');
    exit(0);
}

try {
    $db = Db::mysql($cfg);

    $lockName = 'pakowanie_api_migrations_lock';
    $st = $db->prepare('SELECT GET_LOCK(:name, 10) AS lck');
    $st->execute([':name' => $lockName]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || (string)$row['lck'] !== '1') {
        throw new RuntimeException('Could not acquire migration lock.');
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_name VARCHAR(255) NOT NULL,
            checksum_sha1 CHAR(40) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_migrations_name (migration_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    out('Mode: ' . $mode);
    out('Database: ' . (string)$db->query('SELECT DATABASE()')->fetchColumn());
    out('');

    $check = $db->prepare('SELECT checksum_sha1 FROM schema_migrations WHERE migration_name = :name');

    foreach ($files as $file) {
        $name = basename($file);
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Cannot read migration: ' . $name);
        }

        $sql = normalize_sql($sql);
        $checksum = sha1($sql);

        $check->execute([':name' => $name]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $oldChecksum = (string)$existing['checksum_sha1'];
            if ($oldChecksum !== $checksum) {
                throw new RuntimeException(
                    'Migration already applied but file changed: ' . $name .
                    ' (db=' . $oldChecksum . ', file=' . $checksum . ')'
                );
            }

            out('[SKIP] ' . $name . ' already applied');
            continue;
        }

        $statements = split_sql_statements($sql);

        out('[PENDING] ' . $name . ' statements=' . count($statements));

        if ($mode !== 'apply') {
            continue;
        }

        foreach ($statements as $idx => $statement) {
            $db->exec($statement);
            out('  [OK] statement ' . ($idx + 1));
        }

        $ins = $db->prepare('
            INSERT INTO schema_migrations (migration_name, checksum_sha1)
            VALUES (:name, :checksum)
        ');
        $ins->execute([
            ':name' => $name,
            ':checksum' => $checksum,
        ]);

        out('[DONE] ' . $name);
    }

    $st = $db->prepare('SELECT RELEASE_LOCK(:name)');
    $st->execute([':name' => $lockName]);

    out('');
    out('Finished.');

} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);

    try {
        if (isset($db) && $db instanceof PDO) {
            $st = $db->prepare('SELECT RELEASE_LOCK(:name)');
            $st->execute([':name' => 'pakowanie_api_migrations_lock']);
        }
    } catch (Throwable $ignored) {
    }

    exit(1);
}
