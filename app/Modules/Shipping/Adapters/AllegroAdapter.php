<?php
declare(strict_types=1);

final class AllegroAdapter implements ShippingAdapterInterface
{
    private const BASE_URL = 'https://api.allegro.pl';

    private const SENDER = [
        'name'        => 'Mateusz Woźniak',
        'company'     => 'Led-One',
        'street'      => 'Jasnogórska 183',
        'postalCode'  => '42-125',
        'city'        => 'Biała',
        'countryCode' => 'PL',
        'email'       => 'BOK@led-one.com.pl',
        'phone'       => '534551171',
    ];

    private $cfg;
    private $db;

    public function __construct(array $cfg = [])
    {
        $this->cfg = $cfg;
        $this->db  = \Db::mysql($cfg);
    }

    private function shippingDebugEnabled(): bool
    {
        $flag = (string)(getenv('CARRIER_DEBUG_VERBOSE') ?: getenv('SHIPPING_DEBUG_VERBOSE') ?: '');
        $flag = strtolower(trim($flag));
        return in_array($flag, ['1', 'true', 'yes', 'on', 'debug'], true);
    }

    private function shippingDebugLog(string $event, array $context = []): void
    {
        if (!$this->shippingDebugEnabled()) {
            return;
        }

        error_log('[SHIPPING_DEBUG][ALLEGRO][' . $event . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function generateLabel(array $order, array $package, array $resolved, array $providerCfg): array
    {
        $token   = \AllegroTokenProvider::getToken($this->cfg);
        $orderId = (string)($order['order_code'] ?? '');

        $this->shippingDebugLog('generateLabel.start', [
            'order'       => $order,
            'package'     => $package,
            'resolved'    => $resolved,
            'providerCfg' => $providerCfg,
        ]);

        if (empty($orderId)) {
            throw new \RuntimeException('AllegroAdapter: brak order_code');
        }

        $shopOrderId = (string)($order['shop_order_id'] ?? '');
        if (empty($shopOrderId)) {
            throw new \RuntimeException('AllegroAdapter: brak shop_order_id dla zamówienia ' . $orderId);
        }

        // Pobierz pełne dane zamówienia z Allegro
        $form = $this->apiGet('/order/checkout-forms/' . $shopOrderId, $token);
        $this->shippingDebugLog('generateLabel.checkoutForm', [
            'order_code'    => $orderId,
            'shop_order_id' => $shopOrderId,
            'form'          => $form,
        ]);

        // Sprawdź czy przesyłka już istnieje
        $parcelId = (string)($order['allegro_parcel_id'] ?? '');
        if (empty($parcelId)) {
            $parcelId = $this->fetchExistingParcelId($shopOrderId, $token);
        }

        // Jeśli brak — utwórz nową
        if (empty($parcelId)) {
            $parcelId = $this->createShipment($orderId, $form, $token);
        }
        $this->shippingDebugLog('generateLabel.parcelId', [
            'order_code' => $orderId,
            'parcel_id'  => $parcelId,
        ]);

        // Pobierz szczegóły przesyłki
        $shipment       = $this->apiGet('/shipment-management/shipments/' . $parcelId, $token);
        $waybill        = (string)($shipment['packages'][0]['waybill'] ?? '');
        $trackingNumber = (string)($shipment['additionalProperties']['EXTERNAL_CARRIER_WAYBILL'] ?? $waybill);
        $this->shippingDebugLog('generateLabel.shipment', [
            'order_code'       => $orderId,
            'parcel_id'        => $parcelId,
            'shipment'         => $shipment,
            'waybill'          => $waybill,
            'tracking_number'  => $trackingNumber,
        ]);

        // Zapisz do bazy
        $this->saveParcelToDb($orderId, $parcelId, $waybill);

        // Pobierz etykietę
        $labelContent = $this->fetchLabel($parcelId, $token);
        $fileToken    = $this->saveLabel($labelContent, $orderId);
        $this->shippingDebugLog('generateLabel.labelSaved', [
            'order_code'          => $orderId,
            'parcel_id'           => $parcelId,
            'label_length'        => strlen($labelContent),
            'file_token'          => $fileToken,
        ]);

        return [
            'tracking_number'      => $trackingNumber,
            'external_shipment_id' => $parcelId,
            'label_format'         => 'zpl',
            'label_status'         => 'ok',
            'file_token'           => $fileToken,
            'file_path'            => $fileToken,
            'raw_response'         => $shipment,
        ];
    }

    // -------------------------------------------------------------------------

    private function fetchExistingParcelId(string $shopOrderId, string $token): string
    {
        $data = $this->apiGet('/order/checkout-forms/' . $shopOrderId . '/shipments', $token);
        foreach ($data['shipments'] ?? [] as $s) {
            $id = (string)($s['id'] ?? '');
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                return $id;
            }
        }
        return '';
    }

    private function createShipment(string $orderId, array $form, string $token): string
    {
        $this->shippingDebugLog('createShipment.start', [
            'order_code' => $orderId,
            'form'       => $form,
        ]);

        $addr     = $form['delivery']['address'] ?? [];
        $buyer    = $form['buyer'] ?? [];
        $pickup   = $form['delivery']['pickupPoint'] ?? null;
        $methodId = (string)($form['delivery']['method']['id'] ?? '');
        $isCod    = ($form['payment']['type'] ?? '') === 'CASH_ON_DELIVERY';
        $total    = (float)($form['summary']['totalToPay']['amount'] ?? 0);

        if (empty($methodId)) {
            throw new \RuntimeException('AllegroAdapter: brak delivery.method.id dla zamówienia ' . $orderId);
        }

        $receiver = [
            'name'        => trim(($addr['firstName'] ?? '') . ' ' . ($addr['lastName'] ?? '')),
            'street'      => $addr['street'] ?? '',
            'postalCode'  => $addr['zipCode'] ?? '',
            'city'        => $addr['city'] ?? '',
            'countryCode' => $addr['countryCode'] ?? 'PL',
            'email'       => $buyer['email'] ?? '',
            'phone'       => preg_replace('/\s+/', '', $addr['phoneNumber'] ?? $buyer['phoneNumber'] ?? ''),
        ];

        if (!empty($addr['companyName'])) {
            $receiver['company'] = $addr['companyName'];
        }

        if ($pickup) {
            $receiver['point'] = (string)$pickup['id'];
        }

        // Pobierz waluty COD i insurance z delivery-services dla tej konkretnej metody
        $deliveryService = $this->fetchDeliveryService($methodId, $token);
        $this->shippingDebugLog('createShipment.deliveryService', [
            'order_code'       => $orderId,
            'method_id'        => $methodId,
            'delivery_service' => $deliveryService,
            'pickup'           => $pickup,
            'receiver'         => $receiver,
        ]);
        $codCurrency     = (string)($deliveryService['cashOnDelivery']['currency'] ?? 'PLN');
        $insCurrency     = (string)($deliveryService['insurance']['currency'] ?? 'PLN');
        $insLimit        = (float)($deliveryService['insurance']['limit'] ?? 0);

        $commandId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

        $input = [
            'deliveryMethodId' => $methodId,
            'labelFormat'      => 'ZPL',
            'referenceNumber'  => $orderId,
            'sender'           => self::SENDER,
            'receiver'         => $receiver,
            'packages'         => [[
                'type'   => 'PACKAGE',
                'weight' => ['value' => 1,  'unit' => 'KILOGRAMS'],
                'length' => ['value' => 20, 'unit' => 'CENTIMETER'],
                'width'  => ['value' => 20, 'unit' => 'CENTIMETER'],
                'height' => ['value' => 10, 'unit' => 'CENTIMETER'],
            ]],
        ];

        if ($isCod && $total > 0) {
            // COD i insurance mogą mieć różne waluty (np. COD w HUF, insurance w PLN)
            $input['cashOnDelivery'] = ['amount' => $total, 'currency' => $codCurrency];
            $insAmount = ($insLimit > 0) ? min($total, $insLimit) : $total;
            $input['insurance'] = ['amount' => $insAmount, 'currency' => $insCurrency];
        }

        // Przesyłki zagraniczne bez COD też wymagają insurance
        if (!isset($input['insurance']) && $insLimit > 0 && $this->isInternationalMethod($form)) {
            $input['insurance'] = ['amount' => min(100.00, $insLimit), 'currency' => $insCurrency];
        }

        $this->shippingDebugLog('createShipment.command', [
            'order_code' => $orderId,
            'command_id' => $commandId,
            'input'      => $input,
        ]);

        $this->apiPost('/shipment-management/shipments/create-commands', [
            'commandId' => $commandId,
            'input'     => $input,
        ], $token);

        // Polling — max 10 prób co 2s
        for ($i = 0; $i < 10; $i++) {
            sleep(2);
            $result = $this->apiGet('/shipment-management/shipments/create-commands/' . $commandId, $token);
            $status = $result['status'] ?? '';

            $this->shippingDebugLog('createShipment.poll', [
                'order_code' => $orderId,
                'command_id' => $commandId,
                'attempt'    => $i + 1,
                'result'     => $result,
            ]);

            if ($status === 'SUCCESS') {
                $shipmentId = (string)($result['shipmentId'] ?? '');
                if (empty($shipmentId)) {
                    throw new \RuntimeException('AllegroAdapter: SUCCESS ale brak shipmentId');
                }
                return $shipmentId;
            }

            if ($status === 'ERROR') {
                $errors = json_encode($result['errors'] ?? $result, JSON_UNESCAPED_UNICODE);
                $this->shippingDebugLog('createShipment.error', [
                    'order_code' => $orderId,
                    'command_id' => $commandId,
                    'errors'     => $result['errors'] ?? $result,
                    'errors_json'=> $errors,
                ]);
                throw new \RuntimeException($this->translateAllegroError($result['errors'] ?? []));
            }
        }

        throw new \RuntimeException('AllegroAdapter: timeout tworzenia przesyłki (commandId: ' . $commandId . ')');
    }

    private function translateAllegroError(array $errors): string
    {
        $messages = [];
        foreach ($errors as $e) {
            $code    = $e["code"]    ?? "";
            $path    = $e["path"]    ?? "";
            $details = $e["details"] ?? "";
            $msg     = $e["userMessage"] ?? $e["message"] ?? "";

            if (strpos($path, "receiver.point") !== false) {
                $messages[] = "Nieprawidłowy punkt odbioru: " . $details;
            } elseif (strpos($path, "receiver.email") !== false || strpos($msg, "email") !== false) {
                $messages[] = "Nieprawidłowy email odbiorcy";
            } elseif (strpos($path, "receiver.phone") !== false || strpos($msg, "phone") !== false) {
                $messages[] = "Nieprawidłowy numer telefonu odbiorcy";
            } elseif (strpos($path, "receiver.postalCode") !== false) {
                $messages[] = "Nieprawidłowy kod pocztowy: " . $details;
            } elseif (strpos($path, "receiver.street") !== false) {
                $messages[] = "Nieprawidłowy adres (za długi lub błędny format)";
            } elseif (strpos($msg, "Pick-up point") !== false || strpos($msg, "punkt odbioru") !== false) {
                $messages[] = "Wymagany punkt odbioru dla tej metody dostawy";
            } elseif (strpos($path, "insurance") !== false || strpos($msg, "insurance") !== false) {
                $messages[] = "Błąd ubezpieczenia: " . $msg;
            } elseif (strpos($path, "cashOnDelivery") !== false) {
                $messages[] = "Błąd kwoty pobrania: " . $msg;
            } elseif ($code === "DELIVERY_METHOD_NOT_AVAILABLE" || strpos($msg, "DELIVERY_METHOD_NOT_AVAILABLE") !== false) {
                $messages[] = "Metoda dostawy niedostępna dla tego zamówienia";
            } elseif (strpos($msg, "kod pocztowy nadawcy") !== false || strpos($msg, "poza obszarem") !== false) {
                $messages[] = "Kod pocztowy nadawcy poza obszarem dostawy One Kuriera";
            }
        }

        return empty($messages)
            ? "AllegroAdapter: nieznany błąd tworzenia przesyłki"
            : "Allegro: " . implode("; ", $messages);
    }

    private function fetchDeliveryService(string $methodId, string $token): array
    {
        static $cache = [];
        if (isset($cache[$methodId])) {
            return $cache[$methodId];
        }
        $data = $this->apiGet('/shipment-management/delivery-services', $token);
        foreach ($data['services'] ?? [] as $s) {
            if (($s['id']['deliveryMethodId'] ?? '') === $methodId) {
                $cache[$methodId] = $s;
                return $s;
            }
        }
        return [];
    }

    private function isInternationalMethod(array $form): bool
    {
        $name = strtolower($form['delivery']['method']['name'] ?? '');
        $keywords = ['international', 'czechy', 'słowacja', 'węgry', 'austria',
            'niemcy', 'francja', 'włochy', 'belgia', 'holandia', 'rumunia',
            'bułgaria', 'chorwacja', 'dania', 'finlandia', 'grecja', 'hiszpania',
            'irlandia', 'litwa', 'łotwa', 'estonia', 'luksemburg', 'portugalia',
            'słowenia', 'szwecja'];
        foreach ($keywords as $kw) {
            if (strpos($name, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private function fetchLabel(string $parcelId, string $token): string
    {
        $ch = curl_init(self::BASE_URL . '/shipment-management/label');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['shipmentIds' => [$parcelId]]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/octet-stream',
                'Content-Type: application/vnd.allegro.public.v1+json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
        ]);
        $raw     = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (curl_errno($ch)) throw new \RuntimeException('AllegroAdapter fetchLabel cURL: ' . curl_error($ch));
        curl_close($ch);
        $body = substr($raw, $headLen);
        if ($code !== 200 || empty($body)) {
            throw new \RuntimeException('AllegroAdapter: błąd etykiety HTTP ' . $code . ': ' . substr($body, 0, 300));
        }
        return $body;
    }

    private function saveLabel(string $content, string $orderCode): string
    {
        $dir = BASE_PATH . '/storage/labels';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'allegro_' . $orderCode . '_' . date('Ymd_His') . '.zpl';
        file_put_contents($dir . '/' . $filename, $content);
        return $filename;
    }

    private function saveParcelToDb(string $orderCode, string $parcelId, string $waybill): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pak_orders SET allegro_parcel_id = ?, nr_nadania = ? WHERE order_code = ?'
        );
        $stmt->execute([$parcelId, $waybill, $orderCode]);
    }

    private function apiGet(string $path, string $token): array
    {
        $this->shippingDebugLog('apiGet.request', [
            'path' => $path,
        ]);

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.allegro.public.v1+json',
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) throw new \RuntimeException('AllegroAdapter GET cURL: ' . curl_error($ch));
        curl_close($ch);
        $this->shippingDebugLog('apiGet.response', [
            'path' => $path,
            'http_code' => $code,
            'raw' => $raw,
        ]);

        if ($code >= 400) {
            throw new \RuntimeException('AllegroAdapter GET HTTP ' . $code . ' ' . $path . ': ' . substr($raw, 0, 500));
        }
        return json_decode($raw, true) ?? [];
    }

    private function apiPost(string $path, array $payload, string $token): array
    {
        $this->shippingDebugLog('apiPost.request', [
            'path'    => $path,
            'payload' => $payload,
        ]);

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.allegro.public.v1+json',
                'Content-Type: application/vnd.allegro.public.v1+json',
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) throw new \RuntimeException('AllegroAdapter POST cURL: ' . curl_error($ch));
        curl_close($ch);
        $this->shippingDebugLog('apiPost.response', [
            'path'      => $path,
            'http_code' => $code,
            'raw'       => $raw,
        ]);

        if ($code >= 400) {
            throw new \RuntimeException('AllegroAdapter POST HTTP ' . $code . ' ' . $path . ': ' . substr($raw, 0, 500));
        }
        return json_decode($raw, true) ?? [];
    }
}
