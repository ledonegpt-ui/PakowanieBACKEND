<?php
declare(strict_types=1);

final class Db
{
    /** @var array<string,\PDO> */
    private static $pool = [];

    public static function mysql(array $cfg): \PDO
    {
        return self::get('mysql', function () use ($cfg) {
            $c = $cfg['mysql'];
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $c['host'],
                $c['db'],
                $c['charset'] ?? 'utf8mb4'
            );

            $pdo = new \PDO($dsn, $c['user'], $c['pass'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            return $pdo;
        });
    }

    public static function mysql2(array $cfg): \PDO
    {
        return self::get('mysql2', function () use ($cfg) {
            $c = $cfg['mysql2'];
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $c['host'],
                $c['db'],
                $c['charset'] ?? 'utf8mb4'
            );

            $pdo = new \PDO($dsn, $c['user'], $c['pass'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            return $pdo;
        });
    }

    public static function firebird(array $cfg): \PDO
    {
        return self::get('firebird', function () use ($cfg) {
            $c = $cfg['firebird'];
            if (empty($c['dsn'])) {
                throw new \RuntimeException('FB_DSN is empty');
            }

            $pdo = new \PDO($c['dsn'], $c['user'], $c['pass'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);

            return $pdo;
        });
    }

    public static function mssql(array $cfg): \PDO
    {
        return self::get('mssql', function () use ($cfg) {
            $c = $cfg['mssql'];
            if (empty($c['host']) || empty($c['db'])) {
                throw new \RuntimeException('MSSQL config missing');
            }
            $host = $c['host'];
            $port = (int)($c['port'] ?? 1433);
            $db   = $c['db'];
            $cs   = $c['charset'] ?? 'UTF-8';

            // dblib często działa najlepiej bez charset, więc robimy 2 próby
            $dsn1 = "dblib:host={$host}:{$port};dbname={$db};charset={$cs}";
            $dsn2 = "dblib:host={$host}:{$port};dbname={$db}";

            try {
                $pdo = new \PDO($dsn1, $c['user'], $c['pass'], [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                return $pdo;
            } catch (\Throwable $e) {
                $pdo = new \PDO($dsn2, $c['user'], $c['pass'], [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                return $pdo;
            }
        });
    }

    /** @param callable():\PDO $factory */
    private static function get(string $key, callable $factory): \PDO
    {
        if (!isset(self::$pool[$key])) {
            self::$pool[$key] = $factory();
        }
        return self::$pool[$key];
    }
}
