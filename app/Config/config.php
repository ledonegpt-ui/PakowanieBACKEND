<?php
declare(strict_types=1);

return [
    'app' => [
        'env'      => env('APP_ENV', 'prod'),
        'debug'    => env_bool('APP_DEBUG', false),
        'base_url' => env('APP_BASE_URL', ''),
        'timezone' => env('APP_TIMEZONE', 'Europe/Warsaw'),
    ],

    'mysql' => [
        'host'    => env('MYSQL_HOST', '127.0.0.1'),
        'db'      => env('MYSQL_DB', ''),
        'user'    => env('MYSQL_USER', ''),
        'pass'    => env('MYSQL_PASS', ''),
        'charset' => env('MYSQL_CHARSET', 'utf8mb4'),
    ],

    // opcjonalnie: stara baza (np. aukcje/fotki/tytuły)
    'mysql2' => [
        'host'    => env('MYSQL2_HOST', '127.0.0.1'),
        'db'      => env('MYSQL2_DB', ''),
        'user'    => env('MYSQL2_USER', ''),
        'pass'    => env('MYSQL2_PASS', ''),
        'charset' => env('MYSQL2_CHARSET', 'utf8mb4'),
    ],

    'firebird' => [
        'dsn'           => env('FB_DSN', ''),
        'user'          => env('FB_USER', ''),
        'pass'          => env('FB_PASS', ''),
        'limit'         => env_int('FB_LIMIT', 500),
        'section_ready' => env('FB_SECTION_READY', '27'),
        'section_packed'=> env('FB_SECTION_PACKED', '47'),
    ],

    'mssql' => [
        'host'    => env('MSSQL_HOST', ''),
        'port'    => env_int('MSSQL_PORT', 1433),
        'db'      => env('MSSQL_DB', ''),
        'user'    => env('MSSQL_USER', ''),
        'pass'    => env('MSSQL_PASS', ''),
        'charset' => env('MSSQL_CHARSET', 'UTF-8'),
    ],

    'baselinker' => [
        'token'         => env('BASELINKER_TOKEN', ''),
        'status_packed' => env_int('BASELINKER_STATUS_PACKED', 16060),
    ],

    'allegro' => [
        'user_id' => env('ALLEGRO_USER_ID', ''),
        'token'   => env('ALLEGRO_TOKEN', ''),
    ],

    'inpost' => [
        'token'  => env('INPOST_TOKEN', ''),
        'org_id' => env('INPOST_ORG_ID', ''),
    ],

    'dpd' => [
        'wsdl'       => env('DPD_WSDL', ''),
        'login'      => env('DPD_LOGIN', ''),
        'master_fid' => env('DPD_MASTER_FID', ''),
        'pass'       => env('DPD_PASS', ''),
    ],

    'gls' => [
        'wsdl' => env('GLS_WSDL', ''),
        'user' => env('GLS_USER', ''),
        'pass' => env('GLS_PASS', ''),
    ],

    'poczta' => [
        'wsdl'     => env('POCZTA_WSDL', ''),
        'location' => env('POCZTA_LOCATION', ''),
        'user'     => env('POCZTA_USER', ''),
        'pass'     => env('POCZTA_PASS', ''),
    ],

    'picking_batch_size' => env_int('PICKING_BATCH_SIZE', 3),

    'stations' => require __DIR__ . '/stations.php',
];
