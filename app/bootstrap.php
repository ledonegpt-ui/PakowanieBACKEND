<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

/**
 * Minimalny loader .env (bez composer).
 */
function load_env(string $file): void {
    if (!is_readable($file)) return;

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // pozwól na komentarze po wartości: KEY=VAL #comment
        if (strpos($line, '#') !== false) {
            $parts = explode('#', $line, 2);
            $line = rtrim($parts[0]);
        }

        if (strpos($line, '=') === false) continue;

        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);

        // usuń cudzysłowy
        if ($val !== '' && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
            $val = substr($val, 1, -1);
        }

        // nie nadpisuj jeśli już ustawione w systemie
        if (getenv($key) === false) {
            putenv($key.'='.$val);
            $_ENV[$key] = $val;
        }
    }
}

function env(string $key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function env_int(string $key, int $default = 0): int {
    $v = env($key, null);
    return ($v === null) ? $default : (int)$v;
}

function env_bool(string $key, bool $default = false): bool {
    $v = strtolower((string)env($key, ''));
    if ($v === '') return $default;
    return in_array($v, ['1','true','yes','on'], true);
}

load_env(BASE_PATH . '/.env');

date_default_timezone_set((string)env('APP_TIMEZONE', 'Europe/Warsaw'));

error_reporting(E_ALL);
ini_set('display_errors', env_bool('APP_DEBUG', false) ? '1' : '0');

return require BASE_PATH . '/app/Config/config.php';
