<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

try {
    $db = Db::mysql($cfg);

    $providers = [
        ['code' => 'allegro',      'name' => 'Allegro API'],
        ['code' => 'baselinker',   'name' => 'BaseLinker API'],
        ['code' => 'inpost_shipx', 'name' => 'InPost ShipX API'],
        ['code' => 'dpd_contract', 'name' => 'DPD Contract API'],
    ];

    $sql = "
        INSERT INTO shipping_providers (provider_code, provider_name, is_active, config_json)
        VALUES (:code, :name, :active, :config_json)
        ON DUPLICATE KEY UPDATE
            provider_name = VALUES(provider_name),
            is_active = VALUES(is_active),
            config_json = VALUES(config_json)
    ";
    $st = $db->prepare($sql);

    foreach ($providers as $p) {
        $st->execute([
            ':code' => $p['code'],
            ':name' => $p['name'],
            ':active' => 1,
            ':config_json' => json_encode(new stdClass()),
        ]);
        echo "[OK] " . $p['code'] . " | " . $p['name'] . PHP_EOL;
    }

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
