<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

function parse_packers_map(string $raw): array
{
    $out = [];

    if (trim($raw) === '') {
        return $out;
    }

    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '') continue;

        $sep = null;
        if (strpos($part, '=') !== false) $sep = '=';
        elseif (strpos($part, ':') !== false) $sep = ':';
        if ($sep === null) continue;

        list($id, $name) = array_map('trim', explode($sep, $part, 2));
        if ($id === '' || $name === '') continue;

        $id = preg_replace('/\D+/', '', $id);
        if ($id === '') continue;

        $id = str_pad($id, 2, '0', STR_PAD_LEFT);

        $out[] = [
            'id' => $id,
            'login' => 'packer_' . $id,
            'display_name' => $name,
            'barcode' => 'a0' . $id,
        ];
    }

    return $out;
}

try {
    $db = Db::mysql($cfg);

    $packers = parse_packers_map((string)getenv('PACKERS_MAP'));
    if (!$packers) {
        throw new RuntimeException('PACKERS_MAP is empty or invalid');
    }

    $sqlUser = "
        INSERT INTO users (login, display_name, barcode, pin_hash, is_active)
        VALUES (:login, :display_name, :barcode, NULL, 1)
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            barcode = VALUES(barcode),
            is_active = VALUES(is_active)
    ";
    $stUser = $db->prepare($sqlUser);

    $sqlRole = "
        INSERT INTO user_roles (user_id, role_code)
        VALUES (:user_id, :role_code)
        ON DUPLICATE KEY UPDATE role_code = VALUES(role_code)
    ";
    $stRole = $db->prepare($sqlRole);

    $getUser = $db->prepare("SELECT id FROM users WHERE login = :login LIMIT 1");

    foreach ($packers as $p) {
        $stUser->execute([
            ':login' => $p['login'],
            ':display_name' => $p['display_name'],
            ':barcode' => $p['barcode'],
        ]);

        $getUser->execute([':login' => $p['login']]);
        $row = $getUser->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Cannot fetch user after upsert: ' . $p['login']);
        }

        $stRole->execute([
            ':user_id' => $row['id'],
            ':role_code' => 'packer',
        ]);

        echo '[OK] ' . $p['login'] . ' | ' . $p['display_name'] . ' | ' . $p['barcode'] . PHP_EOL;
    }

} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
