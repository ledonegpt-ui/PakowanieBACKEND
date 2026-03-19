<?php
declare(strict_types=1);

final class BaseLinkerAdapter implements ShippingAdapterInterface
{
    private const API_URL    = 'https://api.baselinker.com/connector.php';
    private const COURIER    = 'erlipro';
    private const ACCOUNT_ID = 2886;

    public function generateLabel(
        array $order,
        array $package,
        array $resolved,
        array $providerCfg
    ): array {
        $token = (string)($providerCfg['token'] ?? '');
        if (empty($token)) {
            throw new RuntimeException('BaseLinkerAdapter: brak tokenu w shipping_providers');
        }

        $blOrderId   = (int)($order['bl_order_id']  ?? 0);
        $blPackageId = (int)($order['bl_package_id'] ?? 0);

        if (!$blOrderId) {
            throw new RuntimeException('BaseLinkerAdapter: brak bl_order_id w zamówieniu ' . $order['order_code']);
        }

        // jeśli przesyłka już istnieje — pobierz etykietę bez tworzenia nowej
        if ($blPackageId) {
            return $this->fetchExistingLabel($token, $blPackageId, $order);
        }

        // brak przesyłki — utwórz nową przez BaseLinker
        return $this->createAndFetchLabel($token, $blOrderId, $order, $package);
    }

    // -------------------------------------------------------------------------

    private function fetchExistingLabel(string $token, int $blPackageId, array $order): array
    {
        // pobierz courier_code z listy paczek
        $packages    = $this->apiCall($token, 'getOrderPackages', ['order_id' => (int)$order['bl_order_id']]);
        $pkg         = [];
        foreach (($packages['packages'] ?? []) as $p) {
            if ((int)$p['package_id'] === $blPackageId) {
                $pkg = $p;
                break;
            }
        }
        if (empty($pkg)) {
            $pkg = ($packages['packages'] ?? [])[0] ?? [];
        }

        $courierCode = (string)($pkg['courier_code'] ?? self::COURIER);
        $nrNadania   = (string)($pkg['courier_package_nr'] ?? $order['nr_nadania'] ?? '');

        // próbuj pobrać etykietę — jeśli BL już nie ma paczki, utwórz nową
        try {
            $labelResult = $this->apiCall($token, 'getLabel', [
                'courier_code' => $courierCode,
                'package_id'   => $blPackageId,
            ]);
        } catch (RuntimeException $e) {
            // paczka nie istnieje w BL (stara/usunięta) — utwórz nową
            return $this->createAndFetchLabel($token, (int)$order['bl_order_id'], $order, []);
        }

        $extension = strtolower((string)($labelResult['extension'] ?? 'pdf'));
        $labelData = base64_decode($labelResult['label'] ?? '');

        if (empty($labelData)) {
            // pusta etykieta — utwórz nową
            return $this->createAndFetchLabel($token, (int)$order['bl_order_id'], $order, []);
        }

        $fileToken = $this->saveLabel($labelData, (string)$order['order_code'], $courierCode, $extension);

        return [
            'tracking_number'      => $nrNadania,
            'external_shipment_id' => (string)$blPackageId,
            'label_format'         => $extension,
            'label_status'         => 'ok',
            'file_token'           => $fileToken,
            'file_path'            => $fileToken,
            'raw_response'         => $pkg,
        ];
    }

    private function resolveErliService(string $deliveryMethod): string
    {
        $dm = strtolower($deliveryMethod);
        if (strpos($dm, 'paczkomat') !== false) {
            return 'erliPaczkomat';
        }
        if (strpos($dm, 'kurier') !== false && strpos($dm, '10') !== false) {
            return 'erliKurier24InPost10kg';
        }
        if (strpos($dm, 'kurier') !== false) {
            return 'erliKurier24InPost30kg';
        }
        if (strpos($dm, 'dhl') !== false) {
            return 'erliDHL20kg';
        }
        if (strpos($dm, 'orlen') !== false) {
            return 'erliOrlenPaczka20kg';
        }
        // fallback — wykryj automatycznie
        return 'detect';
    }

    private function createAndFetchLabel(string $token, int $blOrderId, array $order, array $package): array
    {
        // wymiary z $package lub domyślne
        $weight = (float)($package['weight'] ?? 5);
        $length = (int)($package['length']   ?? 40);
        $width  = (int)($package['width']    ?? 30);
        $height = (int)($package['height']   ?? 20);

        $service = $this->resolveErliService((string)($order['delivery_method'] ?? ''));

        $result = $this->apiCall($token, 'createPackage', [
            'order_id'    => $blOrderId,
            'courier_code'=> self::COURIER,
            'account_id'  => self::ACCOUNT_ID,
            'fields'      => [
                ['id' => 'courier', 'value' => $service],
            ],
            'packages'    => [
                [
                    'weight'      => $weight,
                    'size_length' => $length,
                    'size_width'  => $width,
                    'size_height' => $height,
                ],
            ],
        ]);

        $newPackageId = (int)($result['package_id']     ?? 0);
        $nrNadania    = (string)($result['package_number'] ?? '');

        if (!$newPackageId) {
            throw new RuntimeException('BaseLinkerAdapter: createPackage nie zwrócił package_id dla order_id=' . $blOrderId);
        }

        // pobierz etykietę
        $labelResult = $this->apiCall($token, 'getLabel', [
            'courier_code' => self::COURIER,
            'package_id'   => $newPackageId,
        ]);

        $extension = strtolower((string)($labelResult['extension'] ?? 'pdf'));
        $labelData = base64_decode($labelResult['label'] ?? '');

        if (empty($labelData)) {
            throw new RuntimeException('BaseLinkerAdapter: pusta etykieta po createPackage, package_id=' . $newPackageId);
        }

        $fileToken = $this->saveLabel($labelData, (string)$order['order_code'], self::COURIER, $extension);

        return [
            'tracking_number'      => $nrNadania,
            'external_shipment_id' => (string)$newPackageId,
            'label_format'         => $extension,
            'label_status'         => 'ok',
            'file_token'           => $fileToken,
            'file_path'            => $fileToken,
            'raw_response'         => $result,
        ];
    }

    // -------------------------------------------------------------------------

    private function apiCall(string $token, string $method, array $params): array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'method'     => $method,
                'parameters' => json_encode($params),
            ],
            CURLOPT_HTTPHEADER     => ['X-BLToken: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new RuntimeException('BaseLinker cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($raw, true) ?? [];
        if (($data['status'] ?? '') === 'ERROR') {
            throw new RuntimeException('BaseLinker API error [' . $method . ']: ' . ($data['error_message'] ?? 'unknown'));
        }
        return $data;
    }

    private function saveLabel(string $data, string $orderCode, string $courierCode, string $ext): string
    {
        $dir = BASE_PATH . '/storage/labels';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $prefix   = 'bl_' . strtolower(preg_replace('/[^a-z0-9]/i', '', $courierCode)) . '_';
        $filename = $prefix . $orderCode . '_' . date('Ymd_His') . '.' . $ext;
        file_put_contents($dir . '/' . $filename, $data);
        return $filename;
    }
}
