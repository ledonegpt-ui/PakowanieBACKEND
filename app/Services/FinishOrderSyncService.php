<?php
declare(strict_types=1);

require_once BASE_PATH . '/app/Lib/Db.php';

final class FinishOrderSyncService
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function resolveOperatorLabel(array $session): string
    {
        $displayName = '';
        if (isset($session['display_name'])) {
            $displayName = trim((string)$session['display_name']);
        }

        if ($displayName === '' && !empty($session['user_id'])) {
            $db = Db::mysql($this->cfg);
            $st = $db->prepare("SELECT login, display_name FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => (int)$session['user_id']]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $displayName = trim((string)($row['display_name'] ?? ''));
                if ($displayName === '') {
                    $displayName = trim((string)($row['login'] ?? ''));
                }
            }
        }

        if ($displayName === '') {
            if (isset($session['login'])) {
                $displayName = trim((string)$session['login']);
            } elseif (isset($session['user_login'])) {
                $displayName = trim((string)$session['user_login']);
            } else {
                $displayName = 'user#' . (int)($session['user_id'] ?? 0);
            }
        }

        return strtolower($displayName);
    }

    public function syncAfterFinish(string $orderCode, array $session, ?string $trackingNumber): array
    {
        $result = [
            'firebird' => [
                'attempted' => false,
                'ok' => false,
                'skipped' => false,
                'message' => '',
            ],
            'baselinker' => [
                'attempted' => false,
                'ok' => false,
                'skipped' => false,
                'message' => '',
            ],
        ];

        $order = $this->loadOrder($orderCode);
        if (!$order) {
            $result['firebird']['skipped'] = true;
            $result['firebird']['message'] = 'order not found in pak_orders';
            $result['baselinker']['skipped'] = true;
            $result['baselinker']['message'] = 'order not found in pak_orders';
            return $result;
        }

        $operator = $this->resolveOperatorLabel($session);
        $stationCode = trim((string)(
            isset($session['station_code']) ? $session['station_code']
                : (isset($session['station_id']) ? $session['station_id'] : '')
        ));
        $timestamp = date('Y-m-d H:i:s');
        $noteLine = trim($operator . ' ' . $timestamp . ($stationCode !== '' ? ' ' . $stationCode : '')) . "\n";

        $tracking = trim((string)($trackingNumber !== null && $trackingNumber !== '' ? $trackingNumber : ($order['nr_nadania'] ?? '')));

        try {
            $result['firebird']['attempted'] = true;
            $fbOrderId = $this->resolveFirebirdOrderId($order);
            if ($fbOrderId === null || $fbOrderId <= 0) {
                $result['firebird']['ok'] = true;
                $result['firebird']['skipped'] = true;
                $result['firebird']['message'] = 'no linked firebird order';
            } else {
                $this->syncFirebird($fbOrderId, $noteLine, $tracking);
                $result['firebird']['ok'] = true;
                $result['firebird']['message'] = 'synced for ID_TRANS=' . $fbOrderId;
            }
        } catch (\Throwable $e) {
            $result['firebird']['message'] = $e->getMessage();
        }

        try {
            $blOrderId = (int)($order['bl_order_id'] ?? 0);
            if ($blOrderId > 0) {
                $result['baselinker']['attempted'] = true;
                $this->syncBaseLinker($blOrderId, $noteLine);
                $result['baselinker']['ok'] = true;
                $result['baselinker']['message'] = 'synced for order_id=' . $blOrderId;
            } else {
                $result['baselinker']['ok'] = true;
                $result['baselinker']['skipped'] = true;
                $result['baselinker']['message'] = 'no bl_order_id';
            }
        } catch (\Throwable $e) {
            $result['baselinker']['attempted'] = true;
            $result['baselinker']['message'] = $e->getMessage();
        }

        return $result;
    }

    private function loadOrder(string $orderCode): ?array
    {
        $db = Db::mysql($this->cfg);
        $st = $db->prepare("
            SELECT order_code, source, eu_main_id, bl_order_id, shop_order_id, nr_nadania
            FROM pak_orders
            WHERE order_code = :order_code
            LIMIT 1
        ");
        $st->execute([':order_code' => $orderCode]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function resolveFirebirdOrderId(array $order): ?int
    {
        $source = trim((string)($order['source'] ?? ''));

        if ($source === 'U' && !empty($order['eu_main_id'])) {
            return (int)$order['eu_main_id'];
        }

        if ($source === 'B') {
            $shopOrderId = trim((string)($order['shop_order_id'] ?? ''));
            if ($shopOrderId === '') {
                return null;
            }

            $fb = Db::firebird($this->cfg);
            $st = $fb->prepare("
                SELECT T.ID
                FROM TRANSAKCJE T
                JOIN TRANS_KLIENCI TK ON T.ID_KLIENT = TK.ID_KLIENT
                WHERE TK.KL_LOGIN = 'LEDONE.PL #' || :shop_order_id
            ");
            $st->execute([':shop_order_id' => $shopOrderId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);

            if ($row && isset($row['ID'])) {
                return (int)$row['ID'];
            }

            return null;
        }

        if (!empty($order['eu_main_id'])) {
            return (int)$order['eu_main_id'];
        }

        return null;
    }

    private function syncFirebird(int $fbOrderId, string $noteLine, string $tracking): void
    {
        $fb = Db::firebird($this->cfg);

        $sectionPacked = '';
        if (isset($this->cfg['firebird']['section_packed'])) {
            $sectionPacked = trim((string)$this->cfg['firebird']['section_packed']);
        }
        if ($sectionPacked === '') {
            $envVal = getenv('FB_SECTION_PACKED');
            $sectionPacked = $envVal !== false ? trim((string)$envVal) : '';
        }
        if ($sectionPacked === '') {
            $sectionPacked = '47';
        }

        $st = $fb->prepare("UPDATE TRANSAKCJE SET ID_SEKCJA = :section WHERE ID = :id");
        $st->execute([
            ':section' => $sectionPacked,
            ':id' => $fbOrderId,
        ]);

        if ($tracking !== '') {
            $st = $fb->prepare("UPDATE TRANS_WYSYLKA SET NR_NADANIA = :nr_nadania WHERE ID_TRANS = :id");
            $st->execute([
                ':nr_nadania' => $tracking,
                ':id' => $fbOrderId,
            ]);
        }

        $st = $fb->prepare("SELECT NOTATKI FROM TRANS_WIADOM WHERE ID_TRANS = :id");
        $st->execute([':id' => $fbOrderId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            $existing = (string)($row['NOTATKI'] ?? '');
            if (strpos($existing, $noteLine) === false) {
                $updated = $existing . $noteLine;
                $up = $fb->prepare("UPDATE TRANS_WIADOM SET NOTATKI = :notatki WHERE ID_TRANS = :id");
                $up->execute([
                    ':notatki' => $updated,
                    ':id' => $fbOrderId,
                ]);
            }
        }
    }

    private function syncBaseLinker(int $blOrderId, string $noteLine): void
    {
        $token = trim((string)($this->cfg['baselinker']['token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('BASELINKER_TOKEN is empty');
        }

        $statusPacked = 0;
        if (isset($this->cfg['baselinker']['status_packed'])) {
            $statusPacked = (int)$this->cfg['baselinker']['status_packed'];
        }
        if ($statusPacked <= 0) {
            $envVal = getenv('BASELINKER_STATUS_PACKED');
            $statusPacked = $envVal !== false ? (int)$envVal : 0;
        }
        if ($statusPacked <= 0) {
            $statusPacked = 16060;
        }

        $orderData = $this->apiCall($token, 'getOrders', [
            'order_id' => $blOrderId,
        ]);

        $existingComments = '';
        if (isset($orderData['orders'][0]['admin_comments'])) {
            $existingComments = (string)$orderData['orders'][0]['admin_comments'];
        }

        if (strpos($existingComments, $noteLine) === false) {
            $this->apiCall($token, 'setOrderFields', [
                'order_id' => $blOrderId,
                'admin_comments' => $existingComments . $noteLine,
            ]);
        }

        $this->apiCall($token, 'setOrderStatus', [
            'order_id' => $blOrderId,
            'status_id' => $statusPacked,
        ]);
    }

    private function apiCall(string $token, string $method, array $params): array
    {
        $ch = curl_init('https://api.baselinker.com/connector.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'method' => $method,
                'parameters' => json_encode($params),
            ],
            CURLOPT_HTTPHEADER => ['X-BLToken: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        if (curl_errno($ch)) {
            $msg = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('BaseLinker cURL error: ' . $msg);
        }
        curl_close($ch);

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('BaseLinker invalid JSON response');
        }

        if (isset($data['status']) && $data['status'] === 'ERROR') {
            throw new \RuntimeException('BaseLinker API error [' . $method . ']: ' . (string)($data['error_message'] ?? 'unknown'));
        }

        return $data;
    }
}
