<?php
declare(strict_types=1);

/**
 * ENV helpers (PHP 7.2 friendly)
 */
if (!function_exists('env_val')) {
    function env_val(string $key, $default = null) {
        $v = getenv($key);
        if ($v === false || $v === '') {
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
            return $default;
        }
        return $v;
    }
}
if (!function_exists('env_int_val')) {
    function env_int_val(string $key, int $default = 0): int {
        $v = env_val($key, null);
        if ($v === null) return $default;
        $v = trim((string)$v);
        if ($v === '' || !preg_match('/^-?\d+$/', $v)) return $default;
        return (int)$v;
    }
}

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

require_once __DIR__ . '/../app/Services/ImportState.php';
require_once __DIR__ . '/../app/Services/SubiektReaderV2.php';
require_once __DIR__ . '/../app/Services/FirebirdEUReader.php';
require_once __DIR__ . '/../app/Services/BaselinkerBatchReader.php';
require_once __DIR__ . '/../app/Services/OrderRepositoryV2.php';
require_once __DIR__ . '/../app/Services/ImporterMasterV2.php';

function log_line(string $msg): void {
    $path = __DIR__ . '/../storage/logs/import_orders_master_v2.log';
    @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

$logger = function(string $m): void { log_line($m); };

try {
    $mysql = Db::mysql($cfg);
    $mssql = Db::mssql($cfg);
    $fb    = Db::firebird($cfg);

    $state = new ImportState($mysql);
    $repo  = new OrderRepositoryV2($mysql);

    $subReader = new SubiektReaderV2($mssql, $cfg, $logger);
    $euReader  = new FirebirdEUReader($fb);

    // ENV FIRST
    $blToken = (string)env_val('BASELINKER_TOKEN', (string)($cfg['baselinker']['token'] ?? ''));
    if ($blToken === '') throw new RuntimeException("Brak BASELINKER_TOKEN w ENV i brak cfg['baselinker']['token']");

    $blReader = new BaselinkerBatchReader($blToken, $logger);

    $imp = new ImporterMasterV2($mysql, $state, $repo, $subReader, $euReader, $blReader, $cfg, $logger);
    $res = $imp->run();

    $msg = "OK docs={$res['docs']} uniq={$res['uniq']} saved={$res['saved']} skipped={$res['skipped']} incomplete={$res['incomplete']}";
    $logger("MASTER: " . $msg);
    echo $msg . PHP_EOL;
    exit(0);

} catch (Throwable $e) {
    $m = "MASTER: ERROR " . $e->getMessage();
    log_line($m);
    fwrite(STDERR, $m . PHP_EOL);
    exit(1);
}
