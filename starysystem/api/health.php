<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';

function file_age_sec(string $path): ?int {
    if (!is_file($path)) return null;
    $t = @filemtime($path);
    if (!$t) return null;
    return time() - $t;
}

try {
    $db = Db::mysql($cfg);

    $newCnt  = (int)$db->query("SELECT COUNT(*) FROM pak_orders WHERE status=10")->fetchColumn();
    $packCnt = (int)$db->query("SELECT COUNT(*) FROM pak_orders WHERE status=40")->fetchColumn();

    $version = (string)env('APP_VERSION', 'dev');
    $staleMin = env_int('PACKING_STALE_MIN', 30);

    $cacheDir = __DIR__ . '/../storage/cache';
    $runFile = $cacheDir . '/import_subiekt_last_run.txt';
    $okFile  = $cacheDir . '/import_subiekt_last_ok.txt';

    $runAge = file_age_sec($runFile);
    $okAge  = file_age_sec($okFile);

    // import_ok = ostatni OK był max 2 min temu
    $importOk = ($okAge !== null && $okAge <= 120);

    Resp::json([
        'ok'=>true,
        'now'=>date('Y-m-d H:i:s'),
        'app_version'=>$version,
        'packing_stale_min'=>$staleMin,
        'new_count'=>$newCnt,
        'packing_count'=>$packCnt,

        'import_last_run_age_sec'=>$runAge,
        'import_last_ok_age_sec'=>$okAge,
        'import_ok'=>$importOk,
    ], 200);

} catch (\Throwable $e) {
    Resp::bad('health error: ' . $e->getMessage(), 500);
}
