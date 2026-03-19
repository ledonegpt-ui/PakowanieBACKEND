<?php
declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok' => true,
    'system' => 'pakowanie-api',
    'status' => 'bootstrap',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
