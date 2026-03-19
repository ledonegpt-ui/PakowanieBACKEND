<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

$file = __DIR__ . '/../app/Config/stations.php';
if (!is_file($file)) {
    fwrite(STDERR, "ERROR: stations config not found" . PHP_EOL);
    exit(1);
}

$data = require $file;
if (!is_array($data)) {
    fwrite(STDERR, "ERROR: stations config is not array" . PHP_EOL);
    exit(1);
}

try {
    $db = Db::mysql($cfg);

    $sql = "
        INSERT INTO stations (
            station_code,
            station_name,
            printer_ip,
            printer_name,
            is_active
        ) VALUES (
            :station_code,
            :station_name,
            :printer_ip,
            :printer_name,
            :is_active
        )
        ON DUPLICATE KEY UPDATE
            station_name = VALUES(station_name),
            printer_ip = VALUES(printer_ip),
            printer_name = VALUES(printer_name),
            is_active = VALUES(is_active)
    ";
    $st = $db->prepare($sql);

    foreach ($data as $key => $row) {
        if (!is_array($row)) {
            continue;
        }

        $stationCode = (string)$key;
        $stationName = isset($row['name']) ? trim((string)$row['name']) : '';
        $printerIp   = isset($row['printer_ip']) ? trim((string)$row['printer_ip']) : null;

        if ($stationName === '') {
            fwrite(STDERR, "WARN: skip station key={$stationCode}, empty name" . PHP_EOL);
            continue;
        }

        $st->execute([
            ':station_code' => $stationCode,
            ':station_name' => $stationName,
            ':printer_ip'   => ($printerIp !== '' ? $printerIp : null),
            ':printer_name' => null,
            ':is_active'    => 1,
        ]);

        echo "[OK] code={$stationCode} name={$stationName} printer_ip=" . ($printerIp ?: '-') . PHP_EOL;
    }

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
