<?php
declare(strict_types=1);

/**
 * Pobiera aktualny token Allegro z zewnętrznej bazy (MYSQL2).
 * Token jest odświeżany codziennie przez bota — zawsze aktualny.
 */
final class AllegroTokenProvider
{
    public static function getToken(array $cfg): string
    {
        $db  = Db::mysql2($cfg);
        $row = $db->query("
            SELECT access_token, expires_at
            FROM allegro_accounts
            WHERE login = 'LED-ONE'
            ORDER BY updated_at DESC
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['access_token'])) {
            throw new \RuntimeException('AllegroTokenProvider: brak tokenu w allegro_accounts');
        }

        // ostrzeżenie jeśli token wygasł (bot powinien go już odświeżyć)
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            error_log('AllegroTokenProvider: token wygasł ' . $row['expires_at'] . ' — bot powinien go odświeżyć');
        }

        return (string)$row['access_token'];
    }
}
