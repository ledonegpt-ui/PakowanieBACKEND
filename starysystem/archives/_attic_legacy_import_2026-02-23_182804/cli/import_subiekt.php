<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Services/ImporterSubiekt.php';

function log_line(string $msg): void {
    $path = __DIR__ . '/../storage/logs/import_subiekt.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND);
}

try {
    $mysql = Db::mysql($cfg);
    $mssql = Db::mssql($cfg);

    $imp = new ImporterSubiekt($mysql, $mssql, $cfg, function($m){ log_line((string)$m); });
    $res = $imp->run();

    $msg = "SUB: OK docs={$res['docs']} orders={$res['orders']} items={$res['items']} skipped={$res['skipped']}";
    log_line($msg);
    echo $msg . PHP_EOL;
    exit(0);

} catch (\Throwable $e) {
    log_line("SUB: ERROR " . $e->getMessage());
    fwrite(STDERR, "SUB: ERROR " . $e->getMessage() . PHP_EOL);
    exit(1);
}
