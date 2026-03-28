<?php
declare(strict_types=1);

final class InPostAdapter implements ShippingAdapterInterface
{
    private const BASE_URL = 'https://api-shipx-pl.easypack24.net/v1';

    public function generateLabel(
        array $order,
        array $package,
        array $resolved,
        array $providerCfg
    ): array {
        $token  = getenv('INPOST_TOKEN') ?: ($providerCfg['token'] ?? '');
        $orgId  = getenv('INPOST_ORG_ID') ?: ($providerCfg['org_id'] ?? '');

        if (empty($token) || $token === 'CHANGE_ME') {
            throw new RuntimeException('InPost: brak tokenu (INPOST_TOKEN w .env)');
        }
        if (empty($orgId)) {
            throw new RuntimeException('InPost: brak org_id (INPOST_ORG_ID w .env)');
        }

        $shipmentType = (string)($resolved['shipment_type'] ?? '');

        $existingTracking = trim((string)($order['tracking_number'] ?? $order['nr_nadania'] ?? ''));
        if ($existingTracking !== '') {
            try {
                return $this->fetchExistingLabelByTracking($order, $existingTracking, $token);
            } catch (Throwable $e) {
                error_log('[InPostAdapter] Existing tracking fetch failed for order '
                    . (string)($order['order_code'] ?? '')
                    . ', tracking=' . $existingTracking
                    . ': ' . $e->getMessage());
            }
        }

        // paczkomat vs kurier
        if ($this->isPaczkomat($shipmentType, (string)($order['delivery_method'] ?? ''))) {
            return $this->generatePaczkomat($order, $resolved, $token, $orgId);
        } else {
            return $this->generateKurier($order, $token, $orgId);
        }
    }

    // -------------------------------------------------------------------------

    private function isPaczkomat(string $shipmentType, string $deliveryMethod): bool
    {
        if (stripos($shipmentType, 'paczkomat') !== false) return true;
        if (stripos($deliveryMethod, 'paczkomat') !== false) return true;
        if (stripos($deliveryMethod, 'paczkomaty') !== false) return true;
        return false;
    }

    private function mapLockerTemplate(string $packageSize): string
    {
        $normalized = strtoupper(trim($packageSize));

        switch ($normalized) {
            case 'A':
                return 'small';
            case 'B':
                return 'medium';
            case 'C':
                return 'large';
            case 'D':
                return 'xlarge';
            case 'SMALL':
            case 'MEDIUM':
            case 'LARGE':
            case 'XLARGE':
                return strtolower($normalized);
            default:
                return 'small';
        }
    }

    private function generatePaczkomat(array $order, array $resolved, string $token, string $orgId): array
    {
        $targetPoint = (string)($order['pickup_point_id'] ?? '');
        if (empty($targetPoint)) {
            throw new RuntimeException('InPost paczkomat: brak pickup_point_id w zamówieniu ' . $order['order_code']);
        }

        $phone = $this->normalizePhone((string)($order['phone'] ?? ''));
        $nameParts = $this->splitName((string)($order['delivery_fullname'] ?? ''));

        $payload = [
            'receiver' => [
                'name'         => (string)($order['delivery_fullname'] ?? ''),
                'first_name'   => $nameParts[0],
                'last_name'    => $nameParts[1],
                'email'        => (string)($order['email'] ?? ''),
                'phone'        => $phone,
            ],
            'parcels' => [
                'template' => $this->mapLockerTemplate((string)($resolved['package_size'] ?? 'A')),
            ],
            'custom_attributes' => [
                'sending_method' => 'dispatch_order',
                'target_point'   => $targetPoint,
            ],
            'service'   => $this->resolveLockerService((string)($order['delivery_method'] ?? '')),
            'reference' => (string)($order['order_code'] ?? ''),
            'comments'  => (string)($order['order_code'] ?? ''),
        ];

        // COD — pobranie
        $codAmount = (float)($order['cod_amount'] ?? 0);
        if ($codAmount > 0) {
            $payload['cod'] = [
                'amount'   => number_format($codAmount, 2, '.', ''),
                'currency' => (string)($order['cod_currency'] ?? 'PLN'),
            ];
        }

        $response = $this->post(
            self::BASE_URL . "/organizations/{$orgId}/shipments",
            $token,
            $payload
        );

        $shipmentId = (int)($response['id'] ?? 0);
        if (!$shipmentId) {
            throw new RuntimeException('InPost: nie udało się utworzyć przesyłki: ' . json_encode($response));
        }

        // poczekaj chwilę na tracking_number
        sleep(2);

        $trackingNumber = $this->fetchTrackingNumber($shipmentId, $token);
        $labelZpl       = $this->fetchLabel($trackingNumber, $token);
        $fileToken      = $this->saveLabel($labelZpl, (string)$order['order_code']);

        return [
            'tracking_number'      => $trackingNumber,
            'external_shipment_id' => (string)$shipmentId,
            'label_format'         => 'zpl',
            'label_status'         => 'ok',
            'file_token'           => $fileToken,
            'file_path'            => $fileToken,
            'raw_response'         => $response,
        ];
    }

    private function generateKurier(array $order, string $token, string $orgId): array
    {
        $phone     = $this->normalizePhone((string)($order['phone'] ?? ''));
        $nameParts = $this->splitName((string)($order['delivery_fullname'] ?? ''));
        $address   = $this->splitStreetNumber((string)($order['delivery_address'] ?? ''));

        $payload = [
            'receiver' => [
                'name'         => (string)($order['delivery_fullname'] ?? ''),
                'first_name'   => $nameParts[0],
                'last_name'    => $nameParts[1],
                'email'        => (string)($order['email'] ?? ''),
                'phone'        => $phone,
                'address'      => [
                    'street'          => $address['street'],
                    'building_number' => $address['number'],
                    'city'            => (string)($order['delivery_city'] ?? ''),
                    'post_code'       => (string)($order['delivery_postcode'] ?? ''),
                    'country_code'    => 'PL',
                ],
            ],
            'parcels' => [
                'dimensions' => [
                    'length' => '300',
                    'width'  => '200',
                    'height' => '150',
                    'unit'   => 'mm',
                ],
                'weight' => [
                    'amount' => '5',
                    'unit'   => 'kg',
                ],
            ],
            'custom_attributes' => [
                'sending_method' => 'dispatch_order',
            ],
            'service'   => $this->resolveCourierService((string)($order['delivery_method'] ?? '')),
            'reference' => (string)($order['order_code'] ?? ''),
        ];

        // COD — pobranie
        $codAmount = (float)($order['cod_amount'] ?? 0);
        if ($codAmount > 0) {
            $payload['cod'] = [
                'amount'   => number_format($codAmount, 2, '.', ''),
                'currency' => (string)($order['cod_currency'] ?? 'PLN'),
            ];
        }

        $response = $this->post(
            self::BASE_URL . "/organizations/{$orgId}/shipments",
            $token,
            $payload
        );

        $shipmentId = (int)($response['id'] ?? 0);
        if (!$shipmentId) {
            throw new RuntimeException('InPost kurier: nie udało się utworzyć przesyłki: ' . json_encode($response));
        }

        sleep(2);

        $trackingNumber = $this->fetchTrackingNumber($shipmentId, $token);
        $labelZpl       = $this->fetchLabel($trackingNumber, $token);
        $fileToken      = $this->saveLabel($labelZpl, (string)$order['order_code']);

        return [
            'tracking_number'      => $trackingNumber,
            'external_shipment_id' => (string)$shipmentId,
            'label_format'         => 'zpl',
            'label_status'         => 'ok',
            'file_token'           => $fileToken,
            'file_path'            => $fileToken,
            'raw_response'         => $response,
        ];
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function fetchExistingLabelByTracking(array $order, string $trackingNumber, string $token): array
    {
        $labelZpl  = $this->fetchLabel($trackingNumber, $token);
        $fileToken = $this->saveLabel($labelZpl, (string)$order['order_code']);

        return [
            'tracking_number'      => $trackingNumber,
            'external_shipment_id' => null,
            'label_format'         => 'zpl',
            'label_status'         => 'ok',
            'file_token'           => $fileToken,
            'file_path'            => $fileToken,
            'raw_response'         => [
                'source' => 'existing_tracking',
            ],
        ];
    }

    private function fetchTrackingNumber(int $shipmentId, string $token): string
    {
        $url = self::BASE_URL . "/organizations/" . getenv('INPOST_ORG_ID') . "/shipments?id={$shipmentId}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $raw  = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($raw, true);
        $nr   = (string)($data['items'][0]['tracking_number'] ?? '');
        if (empty($nr)) {
            throw new RuntimeException('InPost: brak tracking_number dla shipment_id=' . $shipmentId);
        }
        return $nr;
    }

    private function fetchLabel(string $trackingNumber, string $token): string
    {
        $orgId = getenv('INPOST_ORG_ID');
        // znajdź shipment po tracking_number
        $url = self::BASE_URL . "/organizations/{$orgId}/shipments?tracking_number={$trackingNumber}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $raw  = curl_exec($ch);
        curl_close($ch);
        $data       = json_decode($raw, true);
        $shipmentId = (int)($data['items'][0]['id'] ?? 0);
        if (!$shipmentId) {
            throw new RuntimeException('InPost: nie znaleziono przesyłki dla tracking=' . $trackingNumber);
        }

        // pobierz etykietę ZPL
        $url = self::BASE_URL . "/shipments/{$shipmentId}/label?format=zpl";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $zpl = curl_exec($ch);
        curl_close($ch);

        if (empty($zpl)) {
            throw new RuntimeException('InPost: pusta etykieta ZPL dla ' . $trackingNumber);
        }
        return $zpl;
    }

    private function saveLabel(string $zpl, string $orderCode): string
    {
        $dir = BASE_PATH . '/storage/labels';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'inpost_' . $orderCode . '_' . date('Ymd_His') . '.zpl';
        file_put_contents($dir . '/' . $filename, $zpl);
        return $filename;
    }

    private function post(string $url, string $token, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json",
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $raw = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new RuntimeException('InPost cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($raw, true) ?? [];
    }



    private function resolveCourierService(string $deliveryMethod): string
    {
        if (stripos($deliveryMethod, 'Allegro') !== false) {
            return 'inpost_courier_allegro';
        }
        // ERLI InPost Kurier — przez baselinker, ale gdyby trafił tu
        if (stripos($deliveryMethod, 'ERLI') !== false) {
            return 'inpost_courier_allegro';
        }
        // zwykły Kurier InPost na własnej umowie
        return 'inpost_courier_standard';
    }

    private function resolveLockerService(string $deliveryMethod): string
    {
        // Allegro Smart — dedykowany service z preferencyjnym rozliczeniem
        if (stripos($deliveryMethod, 'Allegro') !== false && stripos($deliveryMethod, 'Smart') !== false) {
            return 'inpost_locker_allegro_smart';
        }
        // Allegro bez Smart
        if (stripos($deliveryMethod, 'Allegro') !== false) {
            return 'inpost_locker_allegro';
        }
        // zwykły paczkomat na własnej umowie
        return 'inpost_locker_standard';
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '48') {
            $phone = substr($phone, 2);
        }
        return $phone;
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function splitStreetNumber(string $address): array
    {
        // "ul. Kowalska 12/3" → street="ul. Kowalska", number="12/3"
        if (preg_match('/^(.*?)\s+(\d+\S*)$/', trim($address), $m)) {
            return ['street' => trim($m[1]), 'number' => trim($m[2])];
        }
        return ['street' => $address, 'number' => ''];
    }
}
