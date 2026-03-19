<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function panel_api_call(string $method, string $endpoint, array $body = []): array
{
    $method = strtoupper($method);
    $token = $_SESSION['token'] ?? '';

    $url = rtrim(PANEL_API_BASE, '/') . $endpoint;

    if ($method === 'GET' && !empty($body)) {
        $qs = http_build_query($body);
        if ($qs !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $qs;
        }
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    $data = json_decode((string)$response, true);

    if (!is_array($data)) {
        return [
            '_http_code' => $httpCode,
            '_raw' => $response,
            '_curl_error' => $curlError,
        ];
    }

    $data['_http_code'] = $httpCode;

    return $data;
}

function panel_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
