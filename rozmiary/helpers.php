<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function sizes_bootstrap(): array
{
    static $boot = null;

    if ($boot !== null) {
        return $boot;
    }

    $cfg = require __DIR__ . '/../app/bootstrap.php';
    require_once __DIR__ . '/../app/Lib/Db.php';

    $boot = [
        'cfg' => $cfg,
        'db'  => Db::mysql($cfg),
    ];

    return $boot;
}

function sizes_operators(): array
{
    $items = [];
    for ($i = 1; $i <= 15; $i++) {
        $items[] = 'a' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    }
    return $items;
}

function sizes_is_valid_operator($login): bool
{
    return is_string($login) && in_array($login, sizes_operators(), true);
}

function sizes_current_operator(): ?string
{
    if (!isset($_SESSION['sizes_operator']) || !is_string($_SESSION['sizes_operator'])) {
        return null;
    }

    $login = $_SESSION['sizes_operator'];
    return sizes_is_valid_operator($login) ? $login : null;
}

function sizes_set_operator(string $login): void
{
    $_SESSION['sizes_operator'] = $login;
}

function sizes_set_flash(string $type, string $message): void
{
    $_SESSION['sizes_flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function sizes_get_flash(): ?array
{
    if (!isset($_SESSION['sizes_flash']) || !is_array($_SESSION['sizes_flash'])) {
        return null;
    }

    $flash = $_SESSION['sizes_flash'];
    unset($_SESSION['sizes_flash']);

    return $flash;
}

function sizes_redirect(string $url = '/rozmiary/'): void
{
    header('Location: ' . $url);
    exit;
}

function sizes_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sizes_fetch_counts(\PDO $db, ?string $login): array
{
    $globalUnclassified = (int)$db->query("
        SELECT COUNT(*) 
        FROM product_size_map 
        WHERE size_status IS NULL
    ")->fetchColumn();

    $totalLocal = (int)$db->query("
        SELECT COUNT(*) 
        FROM product_size_map
    ")->fetchColumn();

    $assignedToMe = 0;
    if ($login !== null) {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM product_size_map
            WHERE size_status IS NULL
              AND assigned_to_login = ?
        ");
        $stmt->execute([$login]);
        $assignedToMe = (int)$stmt->fetchColumn();
    }

    return [
        'assigned_to_me'      => $assignedToMe,
        'global_unclassified' => $globalUnclassified,
        'total_local'         => $totalLocal,
    ];
}

function sizes_fetch_assigned_items(\PDO $db, ?string $login): array
{
    if ($login === null) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT
            id,
            subiekt_tow_id,
            subiekt_symbol,
            name,
            subiekt_desc,
            assigned_at
        FROM product_size_map
        WHERE size_status IS NULL
          AND assigned_to_login = ?
        ORDER BY assigned_at ASC, id ASC
    ");
    $stmt->execute([$login]);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function sizes_import_all(\PDO $mysql, \PDO $mssql): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $sql = "
        SELECT
            tw_Id,
            CONVERT(VARCHAR(191), tw_Symbol) AS tw_Symbol,
            CONVERT(NVARCHAR(255), tw_Nazwa) AS tw_Nazwa,
            CONVERT(NVARCHAR(MAX), tw_Opis) AS tw_Opis
        FROM [LED_ONE].[dbo].[tw__Towar]
        WHERE ISNULL(tw_Usuniety, 0) = 0
        ORDER BY tw_Id ASC
    ";

    $rows = $mssql->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

    $stmt = $mysql->prepare("
        INSERT INTO product_size_map (
            subiekt_tow_id,
            subiekt_symbol,
            name,
            subiekt_desc,
            size_status,
            assigned_to_login,
            assigned_at,
            classified_by_login,
            classified_at,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            subiekt_symbol = VALUES(subiekt_symbol),
            name = VALUES(name),
            subiekt_desc = VALUES(subiekt_desc),
            updated_at = NOW()
    ");

    $processed = 0;

    $mysql->beginTransaction();
    try {
        foreach ($rows as $row) {
            $subiektId = isset($row['tw_Id']) ? (int)$row['tw_Id'] : 0;
            if ($subiektId <= 0) {
                continue;
            }

            $symbol = isset($row['tw_Symbol']) ? trim((string)$row['tw_Symbol']) : '';
            $name   = isset($row['tw_Nazwa']) ? trim((string)$row['tw_Nazwa']) : '';

            $desc = null;
            if (isset($row['tw_Opis'])) {
                $tmp = trim((string)$row['tw_Opis']);
                $desc = ($tmp === '') ? null : $tmp;
            }

            $stmt->execute([
                $subiektId,
                $symbol,
                $name,
                $desc,
            ]);

            $processed++;
        }

        $mysql->commit();
    } catch (\Throwable $e) {
        if ($mysql->inTransaction()) {
            $mysql->rollBack();
        }
        throw $e;
    }

    return [
        'processed' => $processed,
    ];
}

function sizes_claim_20(\PDO $db, string $login): array
{
    $existingStmt = $db->prepare("
        SELECT COUNT(*)
        FROM product_size_map
        WHERE size_status IS NULL
          AND assigned_to_login = ?
    ");
    $existingStmt->execute([$login]);
    $existing = (int)$existingStmt->fetchColumn();

    if ($existing > 0) {
        return [
            'existing' => $existing,
            'claimed'  => 0,
        ];
    }

    $db->beginTransaction();

    try {
        $ids = $db->query("
            SELECT id
            FROM product_size_map
            WHERE size_status IS NULL
              AND assigned_to_login IS NULL
            ORDER BY id ASC
            LIMIT 20
            FOR UPDATE
        ")->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $db->commit();
            return [
                'existing' => 0,
                'claimed'  => 0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$login], $ids);

        $stmt = $db->prepare("
            UPDATE product_size_map
            SET assigned_to_login = ?,
                assigned_at = NOW(),
                updated_at = NOW()
            WHERE id IN ($placeholders)
              AND size_status IS NULL
              AND assigned_to_login IS NULL
        ");
        $stmt->execute($params);

        $db->commit();

        return [
            'existing' => 0,
            'claimed'  => count($ids),
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function sizes_classify(\PDO $db, string $login, int $id, string $sizeStatus): bool
{
    $allowed = ['small', 'large', 'other'];
    if (!in_array($sizeStatus, $allowed, true)) {
        return false;
    }

    $stmt = $db->prepare("
        UPDATE product_size_map
        SET size_status = ?,
            classified_by_login = ?,
            classified_at = NOW(),
            assigned_to_login = NULL,
            assigned_at = NULL,
            updated_at = NOW()
        WHERE id = ?
          AND size_status IS NULL
          AND assigned_to_login = ?
    ");

    $stmt->execute([
        $sizeStatus,
        $login,
        $id,
        $login,
    ]);

    return $stmt->rowCount() > 0;
}
