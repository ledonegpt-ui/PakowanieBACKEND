<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function apicall($method, $endpoint, $body = array())
{
    $method = strtoupper($method);
    $token = isset($_SESSION['token']) ? $_SESSION['token'] : '';

    $url = BASE_URL . $endpoint;

    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json'
    );

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return array(
            '_http_code' => $httpCode,
            '_raw' => $response,
            '_curl_error' => $curlError
        );
    }

    $data['_http_code'] = $httpCode;

    return $data;
}
