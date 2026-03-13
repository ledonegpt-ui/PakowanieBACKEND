<?php
declare(strict_types=1);

final class BaselinkerBatchReader
{
    /** @var string */
    private $token;

    /** @var callable */
    private $log;

    /** @var string */
    private $url = 'https://api.baselinker.com/connector.php';

    public function __construct(string $token, callable $logger)
    {
        $this->token = $token;
        $this->log   = $logger;
    }

    /**
     * @param array<int,string> $orderCodes  np. ["B123","E456"]
     * @return array<string,array<string,mixed>> map: order_code => payload
     */
    public function fetchByOrderCodes(array $orderCodes): array
    {
        $orderCodes = array_values(array_unique(array_filter($orderCodes, function ($v) {
            return is_string($v) && preg_match('/^[BE]\d+$/', $v);
        })));
        if (!$orderCodes) return [];

        $out = [];

        foreach (array_chunk($orderCodes, 40) as $chunk) {
            // 1) getOrders (równolegle)
            $ordersMap = $this->multiGetOrders($chunk, 10);

            // 2) getOrderPackages (równolegle) -> numer listu + courier_code + inner + package_id
            $codesOk = [];
            foreach ($ordersMap as $code => $ord) if (is_array($ord)) $codesOk[] = $code;
            $pkgMap = $codesOk ? $this->multiGetPackages($codesOk, 10) : [];

            foreach ($ordersMap as $code => $order) {
                if (!$order) continue;

                $prefix = $code[0]; // B/E
                $oid = (int)substr($code, 1);

                $pkg = $pkgMap[$code] ?? null;

                $nrNad = $pkg ? $this->cleanTracking((string)($pkg['courier_package_nr'] ?? '')) : '';
                $courierCode = $pkg ? $this->clean((string)($pkg['courier_code'] ?? '')) : '';
                $inner = $pkg ? $this->clean((string)($pkg['courier_inner_number'] ?? '')) : '';
                $pkgId = $pkg && isset($pkg['package_id']) ? (int)$pkg['package_id'] : null;

                // ZMIANA: pickup point z BaseLinker API getOrders
                // BL zwraca delivery_point_id dla paczkomatów / punktów odbioru
                $pickupPointId   = $this->clean((string)($order['delivery_point_id']      ?? ''));
                $pickupPointName = $this->clean((string)($order['delivery_point_name']     ?? ''));
                $pickupPointAddr = $this->clean((string)($order['delivery_point_address']  ?? ''));

                $items = [];
                if (isset($order['products']) && is_array($order['products'])) {
                    foreach ($order['products'] as $p) {
                        if (!is_array($p)) continue;

                        $opid = isset($p['order_product_id']) ? (int)$p['order_product_id'] : 0;
                        $lineKey = $opid > 0
                            ? ('BL-' . $opid)
                            : ('BL-' . $oid . '-' . substr(md5(json_encode($p)), 0, 10));

                        $qty = isset($p['quantity']) ? (int)$p['quantity'] : 1;
                        if ($qty < 1) $qty = 1;

                        $price = null;
                        if (isset($p['price_brutto'])) {
                            $price = (float)$p['price_brutto'];
                        } elseif (isset($p['price'])) {
                            $price = (float)$p['price'];
                        }

                        $items[] = [
                            'line_key' => $lineKey,
                            'offer_id' => (string)($p['auction_id'] ?? ($p['product_id'] ?? '')),
                            'sku' => $this->clean((string)($p['sku'] ?? '')),
                            'name' => $this->clean((string)($p['name'] ?? '')),
                            'quantity' => $qty,
                            'unit_price_brutto' => $price,
                        ];
                    }
                }

                $out[$code] = [
                    'source' => $prefix,
                    'order_code' => $code,
                    'eu_main_id' => null,
                    'bl_order_id' => $oid,
                    'shop_order_id' => (string)($order['shop_order_id'] ?? ''),
                    'header' => [
                        'delivery_method' => $this->clean((string)($order['delivery_method'] ?? '')),
                        'user_login' => $this->clean((string)($order['user_login'] ?? '')),
                        'delivery_fullname' => $this->clean((string)($order['delivery_fullname'] ?? '')),
                        'delivery_address' => $this->clean((string)($order['delivery_address'] ?? '')),
                        'delivery_city' => $this->clean((string)($order['delivery_city'] ?? '')),
                        'delivery_postcode' => $this->clean((string)($order['delivery_postcode'] ?? '')),
                        'phone' => $this->cleanPhone((string)($order['phone'] ?? '')),
                        'email' => $this->clean((string)($order['email'] ?? '')),
                        'payment_done' => isset($order['payment_done']) ? (float)$order['payment_done'] : null,
                        'payment_method' => $this->clean((string)($order['payment_method'] ?? '')),
                        'delivery_price' => isset($order['delivery_price']) ? (float)$order['delivery_price'] : null,
                        'want_invoice' => ((string)($order['want_invoice'] ?? '0') === '1') ? 1 : 0,
                        'invoice_company' => $this->clean((string)($order['invoice_company'] ?? '')),
                        'invoice_fullname' => $this->clean((string)($order['invoice_fullname'] ?? '')),
                        'invoice_address' => $this->clean((string)($order['invoice_address'] ?? '')),
                        'invoice_city' => $this->clean((string)($order['invoice_city'] ?? '')),
                        'invoice_postcode' => $this->clean((string)($order['invoice_postcode'] ?? '')),
                        'invoice_nip' => $this->clean((string)($order['invoice_nip'] ?? '')),

                        'nr_nadania' => $nrNad,
                        'courier_code' => ($courierCode !== '' ? $courierCode : null),
                        'courier_inner_number' => ($inner !== '' ? $inner : null),
                        'bl_package_id' => $pkgId,

                        // NOWE: dane punktu odbioru / paczkomatu
                        'pickup_point_id'      => ($pickupPointId !== '' ? $pickupPointId : null),
                        'pickup_point_name'    => ($pickupPointName !== '' ? $pickupPointName : null),
                        'pickup_point_address' => ($pickupPointAddr !== '' ? $pickupPointAddr : null),
                    ],
                    'items' => $items,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<int,string> $orderCodes
     * @return array<string,array|null> map: code => orderArray|null
     */
    private function multiGetOrders(array $orderCodes, int $concurrency): array
    {
        return $this->multiCallByCodes($orderCodes, $concurrency, function(string $code) {
            $oid = (int)substr($code, 1);
            return ['method' => 'getOrders', 'parameters' => ['order_id' => $oid]];
        }, function(string $raw, string $code) {
            $json = json_decode($raw, true);
            if (is_array($json) && ($json['status'] ?? '') === 'SUCCESS') {
                $orders = $json['orders'] ?? [];
                if (is_array($orders) && isset($orders[0]) && is_array($orders[0])) return $orders[0];
            }
            ($this->log)("BL: getOrders ERROR code={$code} resp=" . substr((string)$raw, 0, 180));
            return null;
        });
    }

    /**
     * getOrderPackages -> bierzemy pierwszą paczkę (jeśli jest)
     * @param array<int,string> $orderCodes
     * @return array<string,array|null> map: code => firstPackage|null
     */
    private function multiGetPackages(array $orderCodes, int $concurrency): array
    {
        return $this->multiCallByCodes($orderCodes, $concurrency, function(string $code) {
            $oid = (int)substr($code, 1);
            return ['method' => 'getOrderPackages', 'parameters' => ['order_id' => $oid]];
        }, function(string $raw, string $code) {
            $json = json_decode($raw, true);
            if (is_array($json) && ($json['status'] ?? '') === 'SUCCESS') {
                $pkgs = $json['packages'] ?? [];
                if (is_array($pkgs) && isset($pkgs[0]) && is_array($pkgs[0])) return $pkgs[0];
                return null;
            }
            ($this->log)("BL: getOrderPackages ERROR code={$code} resp=" . substr((string)$raw, 0, 180));
            return null;
        });
    }

    /**
     * Wspólny curl_multi runner
     * @param array<int,string> $codes
     * @return array<string,mixed>
     */
    private function multiCallByCodes(array $codes, int $concurrency, callable $makeReq, callable $parse)
    {
        $mh = curl_multi_init();
        if (!$mh) throw new \RuntimeException("BL: curl_multi_init failed");

        $queue = array_values($codes);
        $handles = [];
        $results = [];

        $addHandle = function (string $code) use (&$handles, $mh, $makeReq) {
            $req = $makeReq($code);
            $method = (string)$req['method'];
            $params = json_encode((array)$req['parameters'], JSON_UNESCAPED_UNICODE);

            $post = [
                'method' => $method,
                'parameters' => $params,
            ];

            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => http_build_query($post),
                CURLOPT_HTTPHEADER => ['X-BLToken: ' . $this->token],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 40,
            ]);

            $handles[(int)$ch] = ['ch' => $ch, 'code' => $code];
            curl_multi_add_handle($mh, $ch);
        };

        while ($queue && count($handles) < $concurrency) {
            $addHandle(array_shift($queue));
        }

        do {
            do { $status = curl_multi_exec($mh, $active); } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                $meta = $handles[$key] ?? null;

                if ($meta) {
                    $code = $meta['code'];
                    $raw = curl_multi_getcontent($ch);

                    $res = null;
                    if (is_string($raw) && $raw !== '') {
                        $res = $parse($raw, $code);
                    }
                    $results[$code] = $res;

                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($handles[$key]);

                    if ($queue) $addHandle(array_shift($queue));
                }
            }

            if ($active) curl_multi_select($mh, 0.5);
        } while ($active || $handles);

        curl_multi_close($mh);
        return $results;
    }

    private function clean(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s ? $s : '';
    }

    private function cleanPhone(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/[^\d+]/', '', $s);
        $s = ltrim((string)$s, '+');
        return $s === '' ? '' : ('+' . $s);
    }

    private function cleanTracking(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', '', $s);
        return $s ? $s : '';
    }
}