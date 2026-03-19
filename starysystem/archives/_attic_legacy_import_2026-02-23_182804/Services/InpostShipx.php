<?php
declare(strict_types=1);

final class InpostShipx
{
    public static function getLabelZpl(array $cfg, int $shipmentId): array
    {
        $token = (string)($cfg['inpost']['token'] ?? '');
        if ($token === '') return ['ok'=>false, 'error'=>'Brak INPOST_TOKEN w konfiguracji'];
        if ($shipmentId <= 0) return ['ok'=>false, 'error'=>'Niepoprawny shipment_id'];

        $url = "https://api-shipx-pl.easypack24.net/v1/shipments/{$shipmentId}/label?format=zpl";

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token],
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false) return ['ok'=>false, 'http'=>$http, 'error'=>'cURL: '.$err];
            if ($http >= 400) return ['ok'=>false, 'http'=>$http, 'error'=>'HTTP '.$http.': '.substr((string)$body,0,300)];
            return ['ok'=>true, 'http'=>$http, 'data'=>(string)$body];
        }

        $ctx = stream_context_create([
            'http' => ['method'=>'GET','timeout'=>12,'header'=>"Authorization: Bearer {$token}\r\n"],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return ['ok'=>false, 'error'=>'Brak curl i nie udało się pobrać przez file_get_contents'];
        return ['ok'=>true, 'http'=>200, 'data'=>(string)$body];
    }
}
