<?php
declare(strict_types=1);

final class AllegroClient
{
    public static function getAccessTokenFromMysql2(?int $allegroUserId = null): string
    {
        $uidEnv = trim((string)getenv('ALLEGRO_USER_ID'));
        if ($allegroUserId === null && ctype_digit($uidEnv)) $allegroUserId = (int)$uidEnv;

        $pdo = self::mysql2();

        if ($allegroUserId !== null) {
            $st = $pdo->prepare("
                SELECT id, allegro_user_id, access_token, refresh_token, expires_at
                FROM allegro_accounts
                WHERE allegro_user_id = :u
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->execute([':u' => $allegroUserId]);
        } else {
            $st = $pdo->query("
                SELECT id, allegro_user_id, access_token, refresh_token, expires_at
                FROM allegro_accounts
                ORDER BY id DESC
                LIMIT 1
            ");
        }

        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('ALLEGRO: brak rekordów w admin_automat.allegro_accounts');

        $access    = trim((string)($row['access_token'] ?? ''));
        $refresh   = trim((string)($row['refresh_token'] ?? ''));
        $expiresAt = trim((string)($row['expires_at'] ?? ''));

        if ($access === '') throw new \RuntimeException('ALLEGRO: access_token pusty w admin_automat.allegro_accounts');

        $expired = false;
        if ($expiresAt !== '') {
            try {
                $exp = new \DateTimeImmutable($expiresAt);
                $now = new \DateTimeImmutable('now');
                if ($exp->getTimestamp() <= ($now->getTimestamp() + 60)) $expired = true;
            } catch (\Throwable $e) {
                $expired = false;
            }
        }
        if (!$expired) return $access;

        $cid  = trim((string)getenv('ALLEGRO_CLIENT_ID'));
        $csec = trim((string)getenv('ALLEGRO_CLIENT_SECRET'));
        if ($cid === '' || $csec === '') {
            throw new \RuntimeException('ALLEGRO: token wygasł, brak ALLEGRO_CLIENT_ID/ALLEGRO_CLIENT_SECRET do odświeżenia');
        }
        if ($refresh === '') {
            throw new \RuntimeException('ALLEGRO: token wygasł, a refresh_token pusty');
        }

        $new = self::refreshToken($cid, $csec, $refresh);

        $newAccess  = trim((string)($new['access_token'] ?? ''));
        $newRefresh = trim((string)($new['refresh_token'] ?? $refresh));
        $expiresIn  = (int)($new['expires_in'] ?? 0);

        if ($newAccess === '' || $expiresIn < 60) {
            throw new \RuntimeException('ALLEGRO: refresh odpowiedział niepoprawnie');
        }

        $newExp = (new \DateTimeImmutable('now'))->modify('+' . $expiresIn . ' seconds')->format('Y-m-d H:i:s');

        $upd = $pdo->prepare("
            UPDATE allegro_accounts
            SET access_token = :a,
                refresh_token = :r,
                expires_at = :e
            WHERE id = :id
        ");
        $upd->execute([
            ':a' => $newAccess,
            ':r' => $newRefresh,
            ':e' => $newExp,
            ':id' => (int)$row['id'],
        ]);

        return $newAccess;
    }

    /**
     * Allegro: pobierz etykietę (RAW) - może zwrócić PDF albo ZPL (w praktyce bywa ^XA).
     * Zwraca ['data'=>string, 'content_type'=>string, 'http_code'=>int]
     */
    public static function getShipmentLabelRaw(string $parcelId, string $pageSize = 'A6'): array
    {
        $parcelId = trim($parcelId);
        if ($parcelId === '') throw new \RuntimeException("ALLEGRO: parcelId puste");

        $token = self::getAccessTokenFromMysql2();

        $url = 'https://api.allegro.pl/shipment-management/label';
        $payload = json_encode([
            'shipmentIds' => [$parcelId],
            'pageSize' => $pageSize,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        if (!$ch) throw new \RuntimeException("ALLEGRO: curl_init failed");

        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/octet-stream',
                'Content-Type: application/vnd.allegro.public.v1+json',
            ],
        ]);

        $bin = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($bin === false) throw new \RuntimeException("ALLEGRO: cURL error: {$err}");
        if ($code < 200 || $code >= 300) {
            $snippet = substr((string)$bin, 0, 400);
            throw new \RuntimeException("ALLEGRO: HTTP {$code} CT={$ct} body={$snippet}");
        }
        if ($bin === '' || strlen($bin) < 20) {
            throw new \RuntimeException("ALLEGRO: pusty/za krótki plik (len=" . strlen($bin) . ") CT={$ct}");
        }

        return ['data'=>(string)$bin, 'content_type'=>$ct, 'http_code'=>$code];
    }

    private static function mysql2(): \PDO
    {
        $host = trim((string)getenv('MYSQL2_HOST'));
        $db   = trim((string)getenv('MYSQL2_DB'));
        $user = (string)getenv('MYSQL2_USER');
        $pass = (string)getenv('MYSQL2_PASS');
        $charset = trim((string)getenv('MYSQL2_CHARSET')) ?: 'utf8mb4';

        if ($host === '' || $db === '' || $user === '') {
            throw new \RuntimeException('ALLEGRO: brak konfiguracji MYSQL2_HOST/MYSQL2_DB/MYSQL2_USER');
        }

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private static function refreshToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $url = 'https://allegro.pl/auth/oauth/token';
        $post = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $ch = curl_init($url);
        if (!$ch) throw new \RuntimeException('ALLEGRO: curl_init refresh failed');

        $basic = base64_encode($clientId . ':' . $clientSecret);

        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $basic,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) throw new \RuntimeException("ALLEGRO: refresh cURL error: {$err}");
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("ALLEGRO: refresh HTTP {$code} body=" . substr((string)$raw, 0, 400));
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) throw new \RuntimeException("ALLEGRO: refresh invalid JSON " . substr((string)$raw, 0, 200));

        return $json;
    }
}
