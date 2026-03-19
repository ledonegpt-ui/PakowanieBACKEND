<?php
declare(strict_types=1);

return [
    'name' => 'pakowanie-api',
    'version' => 'v1',
    'timezone' => 'Europe/Warsaw',
    'debug' => env_bool('APP_DEBUG', false),
    'json_flags' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
];
