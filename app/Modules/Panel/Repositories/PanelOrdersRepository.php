<?php
declare(strict_types=1);

final class PanelOrdersRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function searchOrders(string $query, string $status, string $carrierKey, int $limit, array $mapCfg): array
    {
        $limit = max(1, min(200, $limit));

        $where = array();
        $params = array();

        if ($query !== '') {
            $phoneDigits = preg_replace('/\D+/', '', $query);
            $queryLike = '%' . $query . '%';

            $parts = array(
                'po.order_code LIKE :q_order_code',
                'po.delivery_fullname LIKE :q_delivery_fullname',
                'po.email LIKE :q_email',
                'po.delivery_city LIKE :q_delivery_city',
                'po.subiekt_doc_no LIKE :q_subiekt_doc_no',
                'po.tracking_number LIKE :q_tracking_number',
                'po.nr_nadania LIKE :q_nr_nadania'
            );

            $params[':q_order_code'] = $queryLike;
            $params[':q_delivery_fullname'] = $queryLike;
            $params[':q_email'] = $queryLike;
            $params[':q_delivery_city'] = $queryLike;
            $params[':q_subiekt_doc_no'] = $queryLike;
            $params[':q_tracking_number'] = $queryLike;
            $params[':q_nr_nadania'] = $queryLike;

            if ($phoneDigits !== '') {
                $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(po.phone, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE :phone_q";
                $params[':phone_q'] = '%' . $phoneDigits . '%';
            }

            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        if ($status !== '') {
            $where[] = 'po.status = :status';
            $params[':status'] = $status;
        }

        $sql = "
            SELECT
                po.order_code,
                po.source,
                po.delivery_method,
                po.tracking_number,
                po.status,
                po.delivery_fullname,
                po.delivery_address,
                po.delivery_city,
                po.delivery_postcode,
                po.phone,
                po.email,
                po.payment_done,
                po.payment_method,
                po.delivery_price,
                po.subiekt_doc_no,
                po.imported_at,
                po.updated_at,
                po.pack_started_at,
                po.pack_ended_at,
                po.packer,
                po.station,
                po.carrier_code,
                po.courier_code,
                po.nr_nadania,
                po.cod_amount,
                po.cod_currency
            FROM pak_orders po
        ";

        if (!empty($where)) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }

        $sql .= "\nORDER BY po.imported_at DESC, po.order_code DESC LIMIT " . (int)$limit;

        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return array();
        }

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($mapCfg);

        $orderCodes = array();
        foreach ($rows as $row) {
            $orderCodes[] = (string)$row['order_code'];
        }

        $packageModes = $this->getOrderPackageModes($orderCodes);
        $batchInfo = $this->getLatestBatchInfoForOrders($orderCodes);

        $result = array();

        foreach ($rows as $row) {
            $resolved = $resolver->resolve(array(
                'delivery_method' => (string)($row['delivery_method'] ?? ''),
                'carrier_code'    => (string)($row['carrier_code'] ?? ''),
                'courier_code'    => (string)($row['courier_code'] ?? ''),
            ));

            $orderCode = (string)$row['order_code'];
            $resolvedCarrierKey = (string)($resolved['menu_group'] ?? '');

            if ($carrierKey !== '' && $resolvedCarrierKey !== $carrierKey) {
                continue;
            }

            $result[] = array(
                'order_code'        => $orderCode,
                'external_order_no' => (string)($row['subiekt_doc_no'] ?? ''),
                'buyer_name'        => (string)($row['delivery_fullname'] ?? ''),
                'phone'             => (string)($row['phone'] ?? ''),
                'email'             => (string)($row['email'] ?? ''),
                'city'              => (string)($row['delivery_city'] ?? ''),
                'status'            => (string)($row['status'] ?? ''),
                'carrier_key'       => $resolvedCarrierKey,
                'delivery_method'   => (string)($row['delivery_method'] ?? ''),
                'package_mode'      => (string)($packageModes[$orderCode] ?? 'unknown'),
                'imported_at'       => (string)($row['imported_at'] ?? ''),
                'tracking_number'   => (string)($row['tracking_number'] ?? ''),
                'nr_nadania'        => (string)($row['nr_nadania'] ?? ''),
                'cod_amount'        => $row['cod_amount'] !== null ? (float)$row['cod_amount'] : null,
                'batch'             => $batchInfo[$orderCode] ?? null,
            );
        }

        return $result;
    }

    public function getOrderDetail(string $orderCode, array $mapCfg): ?array
    {
        $sql = "
            SELECT *
            FROM pak_orders
            WHERE order_code = :order_code
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':order_code' => $orderCode));
        $order = $st->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        $items = $this->getOrderItems($orderCode);
        $packageModes = $this->getOrderPackageModes(array($orderCode));
        $batchInfo = $this->getLatestBatchInfoForOrders(array($orderCode));
        $pickingEvents = $this->getPickingEventsForOrder($orderCode);
        $adminChanges = $this->getAdminChangesForOrder($orderCode);

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($mapCfg);

        $resolved = $resolver->resolve(array(
            'delivery_method' => (string)($order['delivery_method'] ?? ''),
            'carrier_code'    => (string)($order['carrier_code'] ?? ''),
            'courier_code'    => (string)($order['courier_code'] ?? ''),
        ));

        return array(
            'header' => $order,
            'carrier_key' => (string)($resolved['menu_group'] ?? ''),
            'package_mode' => (string)($packageModes[$orderCode] ?? 'unknown'),
            'items' => $items,
            'batch' => $batchInfo[$orderCode] ?? null,
            'picking_events' => $pickingEvents,
            'admin_changes' => $adminChanges,
            'shipping' => $this->getShippingDebugForOrder($orderCode, $resolved),
        );
    }

    public function updateEditableFields(string $orderCode, array $data, int $changedByUserId): array
    {
        $current = $this->getRawOrderForUpdate($orderCode);
        if (!$current) {
            throw new RuntimeException('Order not found');
        }

        $allowedFields = array(
            'delivery_fullname',
            'delivery_address',
            'delivery_city',
            'delivery_postcode',
            'phone',
            'email',
        );

        $changes = array();

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $newValue = trim((string)$data[$field]);
            $oldValue = isset($current[$field]) && $current[$field] !== null ? trim((string)$current[$field]) : '';

            if ($newValue === $oldValue) {
                continue;
            }

            $changes[$field] = array(
                'old' => $oldValue,
                'new' => $newValue,
            );
        }

        if (empty($changes)) {
            return array(
                'updated' => false,
                'changes' => array(),
            );
        }

        $this->db->beginTransaction();

        try {
            $setParts = array();
            $params = array(':order_code' => $orderCode);

            foreach ($changes as $field => $values) {
                $setParts[] = $field . ' = :' . $field;
                $params[':' . $field] = $values['new'];
            }

            $sql = "
                UPDATE pak_orders
                SET " . implode(",\n                    ", $setParts) . ",
                    updated_at = NOW()
                WHERE order_code = :order_code
            ";

            $st = $this->db->prepare($sql);
            $st->execute($params);

            foreach ($changes as $field => $values) {
                $this->insertAdminChangeLog(
                    $orderCode,
                    $changedByUserId,
                    $field,
                    $values['old'],
                    $values['new']
                );
            }

            $this->db->commit();

            return array(
                'updated' => true,
                'changes' => $changes,
            );
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function getRawOrderForUpdate(string $orderCode): ?array
    {
        $sql = "
            SELECT
                order_code,
                delivery_fullname,
                delivery_address,
                delivery_city,
                delivery_postcode,
                phone,
                email
            FROM pak_orders
            WHERE order_code = :order_code
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':order_code' => $orderCode));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function insertAdminChangeLog(string $orderCode, int $changedByUserId, string $fieldName, string $oldValue, string $newValue): void
    {
        $sql = "
            INSERT INTO order_admin_changes
                (order_code, changed_by_user_id, field_name, old_value, new_value, created_at)
            VALUES
                (:order_code, :changed_by_user_id, :field_name, :old_value, :new_value, NOW())
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(
            ':order_code' => $orderCode,
            ':changed_by_user_id' => $changedByUserId,
            ':field_name' => $fieldName,
            ':old_value' => $oldValue,
            ':new_value' => $newValue,
        ));
    }

    private function getOrderItems(string $orderCode): array
    {
        $sql = "
            SELECT
                item_id,
                order_code,
                offer_id,
                subiekt_tow_id,
                subiekt_symbol,
                sku,
                name,
                subiekt_desc,
                quantity,
                image_url
            FROM pak_order_items
            WHERE order_code = :order_code
            ORDER BY item_id ASC
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':order_code' => $orderCode));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getLatestBatchInfoForOrders(array $orderCodes): array
    {
        if (empty($orderCodes)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($orderCodes), '?'));

        $sql = "
            SELECT
                pbo.order_code,
                pb.id AS batch_id,
                pb.batch_code,
                pb.carrier_key,
                pb.package_mode,
                pb.status AS batch_status,
                pb.selection_mode,
                pbo.status AS order_status,
                pbo.drop_reason,
                pbo.assigned_at,
                pbo.removed_at
            FROM picking_batch_orders pbo
            INNER JOIN picking_batches pb ON pb.id = pbo.batch_id
            INNER JOIN (
                SELECT order_code, MAX(id) AS max_id
                FROM picking_batch_orders
                WHERE order_code IN ($placeholders)
                GROUP BY order_code
            ) x ON x.max_id = pbo.id
        ";

        $st = $this->db->prepare($sql);
        $st->execute(array_values($orderCodes));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $out = array();
        foreach ($rows as $row) {
            $out[(string)$row['order_code']] = $row;
        }

        return $out;
    }

    private function getPickingEventsForOrder(string $orderCode): array
    {
        $sql = "
            SELECT
                pe.id,
                pe.event_type,
                pe.event_message,
                pe.payload_json,
                pe.created_at,
                pe.created_by_user_id
            FROM picking_events pe
            INNER JOIN picking_batch_orders pbo ON pbo.id = pe.batch_order_id
            WHERE pbo.order_code = :order_code
            ORDER BY pe.created_at DESC, pe.id DESC
            LIMIT 200
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':order_code' => $orderCode));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getAdminChangesForOrder(string $orderCode): array
    {
        $sql = "
            SELECT
                oac.id,
                oac.field_name,
                oac.old_value,
                oac.new_value,
                oac.created_at,
                oac.changed_by_user_id,
                u.login,
                u.display_name
            FROM order_admin_changes oac
            LEFT JOIN users u ON u.id = oac.changed_by_user_id
            WHERE oac.order_code = :order_code
            ORDER BY oac.created_at DESC, oac.id DESC
            LIMIT 200
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':order_code' => $orderCode));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getOrderPackageModes(array $orderCodes): array
    {
        if (empty($orderCodes)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($orderCodes), '?'));

        $sql = "
            SELECT
                poi.order_code,
                CASE
                    WHEN SUM(
                        CASE
                            WHEN poi.subiekt_tow_id IS NULL
                                 OR psm.subiekt_tow_id IS NULL
                                 OR psm.size_status IS NULL
                            THEN 1 ELSE 0
                        END
                    ) > 0 THEN 'unknown'
                    WHEN SUM(CASE WHEN psm.size_status = 'large' THEN 1 ELSE 0 END) > 0 THEN 'large'
                    ELSE 'small'
                END AS package_mode
            FROM pak_order_items poi
            LEFT JOIN product_size_map psm
                ON psm.subiekt_tow_id = poi.subiekt_tow_id
            WHERE poi.order_code IN ($placeholders)
            GROUP BY poi.order_code
        ";

        $st = $this->db->prepare($sql);
        $st->execute(array_values($orderCodes));

        $result = array();
        foreach ($orderCodes as $orderCode) {
            $result[(string)$orderCode] = 'unknown';
        }

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $result[(string)$row['order_code']] = (string)$row['package_mode'];
        }

        return $result;
    }

    private function getShippingDebugForOrder(string $orderCode, array $resolved): array
    {
        $packingSession = $this->getLatestPackingSessionForOrder($orderCode);
        $package = null;
        $label = null;
        $events = array();

        if ($packingSession) {
            $package = $this->getPackageForSession((int)$packingSession['id']);
            if ($package) {
                $label = $this->getLatestLabelForPackage((int)$package['id']);
            }
            $events = $this->getShippingEventsForSession((int)$packingSession['id']);
        }

        return array(
            'resolved' => $resolved,
            'packing_session' => $packingSession,
            'package' => $package,
            'label' => $label,
            'latest_error' => $this->getLatestLabelFailureForOrder($orderCode),
            'events' => $events,
        );
    }

    private function getLatestPackingSessionForOrder(string $orderCode): ?array
    {
        $sql = "
            SELECT
                id,
                session_code,
                order_code,
                picking_batch_id,
                user_id,
                station_id,
                status,
                started_at,
                completed_at,
                cancelled_at,
                last_seen_at
            FROM packing_sessions
            WHERE order_code = :order_code
            ORDER BY started_at DESC, id DESC
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':order_code' => $orderCode));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function getPackageForSession(int $sessionId): ?array
    {
        $sql = "
            SELECT
                id,
                packing_session_id,
                package_no,
                provider_id,
                service_code,
                package_size_code,
                tracking_number,
                external_shipment_id,
                status,
                created_at,
                updated_at
            FROM packages
            WHERE packing_session_id = :session_id
            ORDER BY package_no ASC, id ASC
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':session_id' => $sessionId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function getLatestLabelForPackage(int $packageId): ?array
    {
        $sql = "
            SELECT
                id,
                package_id,
                label_format,
                label_status,
                file_path,
                file_token,
                raw_response_json,
                created_at
            FROM package_labels
            WHERE package_id = :package_id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':package_id' => $packageId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function getLatestLabelFailureForOrder(string $orderCode): ?array
    {
        $sql = "
            SELECT
                pe.id,
                pe.event_type,
                pe.event_message,
                pe.payload_json,
                pe.created_at,
                pe.created_by_user_id
            FROM packing_events pe
            INNER JOIN packing_sessions ps ON ps.id = pe.packing_session_id
            WHERE ps.order_code = :order_code
              AND pe.event_type = 'label_generation_failed'
            ORDER BY pe.created_at DESC, pe.id DESC
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':order_code' => $orderCode));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function getShippingEventsForSession(int $sessionId): array
    {
        $sql = "
            SELECT
                id,
                event_type,
                event_message,
                payload_json,
                created_at,
                created_by_user_id
            FROM packing_events
            WHERE packing_session_id = :session_id
              AND event_type IN ('label_generated', 'label_generation_failed', 'label_reprinted')
            ORDER BY created_at DESC, id DESC
            LIMIT 30
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array(':session_id' => $sessionId));

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }


    public function forceDeleteOrder(string $orderCode, int $changedByUserId, string $reason = ''): array
    {
        $orderCode = trim($orderCode);
        if ($orderCode === '') {
            throw new RuntimeException('Missing orderCode');
        }

        $this->db->beginTransaction();

        try {
            $st = $this->db->prepare("
                SELECT order_code
                FROM pak_orders
                WHERE order_code = :order_code
                LIMIT 1
                FOR UPDATE
            ");
            $st->execute(array(':order_code' => $orderCode));
            $order = $st->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new RuntimeException('Order not found');
            }

            $st = $this->db->prepare("
                SELECT id, batch_id, status
                FROM picking_batch_orders
                WHERE order_code = :order_code
                FOR UPDATE
            ");
            $st->execute(array(':order_code' => $orderCode));
            $batchOrderRows = $st->fetchAll(PDO::FETCH_ASSOC);

            $batchOrderIds = array();
            $affectedBatchIds = array();

            foreach ($batchOrderRows as $row) {
                $batchOrderIds[] = (int)$row['id'];
                if (!empty($row['batch_id'])) {
                    $affectedBatchIds[(int)$row['batch_id']] = (int)$row['batch_id'];
                }
            }

            $st = $this->db->prepare("
                SELECT id, picking_batch_id, status
                FROM packing_sessions
                WHERE order_code = :order_code
                FOR UPDATE
            ");
            $st->execute(array(':order_code' => $orderCode));
            $packingSessions = $st->fetchAll(PDO::FETCH_ASSOC);

            $sessionIds = array();
            foreach ($packingSessions as $row) {
                $sessionIds[] = (int)$row['id'];
                if (!empty($row['picking_batch_id'])) {
                    $affectedBatchIds[(int)$row['picking_batch_id']] = (int)$row['picking_batch_id'];
                }
            }

            $st = $this->db->prepare("
                SELECT item_id
                FROM pak_order_items
                WHERE order_code = :order_code
                FOR UPDATE
            ");
            $st->execute(array(':order_code' => $orderCode));
            $itemIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'item_id'));

            $packageIds = array();
            if (!empty($sessionIds)) {
                $in = $this->buildInClause($sessionIds, 'sid');
                $st = $this->db->prepare("
                    SELECT id
                    FROM packages
                    WHERE packing_session_id IN (" . $in['clause'] . ")
                    FOR UPDATE
                ");
                $st->execute($in['params']);
                $packageIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
            }

            $pickingOrderItemIds = array();
            if (!empty($batchOrderIds)) {
                $in = $this->buildInClause($batchOrderIds, 'boid');
                $st = $this->db->prepare("
                    SELECT id
                    FROM picking_order_items
                    WHERE batch_order_id IN (" . $in['clause'] . ")
                    FOR UPDATE
                ");
                $st->execute($in['params']);
                $pickingOrderItemIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
            }

            $deleted = array(
                'package_labels' => 0,
                'packing_events' => 0,
                'packing_session_items' => 0,
                'packages' => 0,
                'packing_sessions' => 0,
                'picking_events' => 0,
                'picking_order_items' => 0,
                'picking_batch_orders' => 0,
                'order_backlog_holds' => 0,
                'order_admin_changes' => 0,
                'order_events' => 0,
                'pak_events' => 0,
                'print_logs' => 0,
                'pak_order_items' => 0,
                'pak_orders' => 0,
            );

            if (!empty($packageIds)) {
                $in = $this->buildInClause($packageIds, 'pkg');
                $st = $this->db->prepare("
                    DELETE FROM package_labels
                    WHERE package_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['package_labels'] += $st->rowCount();
            }

            if (!empty($sessionIds)) {
                $in = $this->buildInClause($sessionIds, 'sid');
                $st = $this->db->prepare("
                    DELETE FROM packing_events
                    WHERE packing_session_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['packing_events'] += $st->rowCount();
            }

            if (!empty($itemIds)) {
                $in = $this->buildInClause($itemIds, 'itm');
                $st = $this->db->prepare("
                    DELETE FROM packing_session_items
                    WHERE pak_order_item_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['packing_session_items'] += $st->rowCount();
            }

            if (!empty($sessionIds)) {
                $in = $this->buildInClause($sessionIds, 'sid');
                $st = $this->db->prepare("
                    DELETE FROM packing_session_items
                    WHERE packing_session_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['packing_session_items'] += $st->rowCount();

                $st = $this->db->prepare("
                    DELETE FROM packages
                    WHERE packing_session_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['packages'] += $st->rowCount();
            }

            $st = $this->db->prepare("
                DELETE FROM packing_sessions
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['packing_sessions'] += $st->rowCount();

            if (!empty($pickingOrderItemIds)) {
                $in = $this->buildInClause($pickingOrderItemIds, 'poi');
                $st = $this->db->prepare("
                    DELETE FROM picking_events
                    WHERE order_item_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['picking_events'] += $st->rowCount();
            }

            if (!empty($batchOrderIds)) {
                $in = $this->buildInClause($batchOrderIds, 'boid');
                $st = $this->db->prepare("
                    DELETE FROM picking_events
                    WHERE batch_order_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['picking_events'] += $st->rowCount();

                $st = $this->db->prepare("
                    DELETE FROM picking_order_items
                    WHERE batch_order_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['picking_order_items'] += $st->rowCount();
            }

            $st = $this->db->prepare("
                DELETE FROM picking_batch_orders
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['picking_batch_orders'] += $st->rowCount();

            if (!empty($itemIds)) {
                $in = $this->buildInClause($itemIds, 'itm');
                $st = $this->db->prepare("
                    DELETE FROM order_backlog_holds
                    WHERE pak_order_item_id IN (" . $in['clause'] . ")
                ");
                $st->execute($in['params']);
                $deleted['order_backlog_holds'] += $st->rowCount();
            }

            $st = $this->db->prepare("
                DELETE FROM order_backlog_holds
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['order_backlog_holds'] += $st->rowCount();

            $st = $this->db->prepare("
                DELETE FROM order_admin_changes
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['order_admin_changes'] += $st->rowCount();

            $st = $this->db->prepare("
                DELETE FROM order_events
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['order_events'] += $st->rowCount();

            $st = $this->db->prepare("
                DELETE FROM pak_events
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['pak_events'] += $st->rowCount();

            $st = $this->db->prepare("
                DELETE FROM print_logs
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['print_logs'] += $st->rowCount();

            $st = $this->db->prepare("
                DELETE FROM pak_order_items
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['pak_order_items'] += $st->rowCount();

            $st = $this->db->prepare("
                DELETE FROM pak_orders
                WHERE order_code = :order_code
            ");
            $st->execute(array(':order_code' => $orderCode));
            $deleted['pak_orders'] += $st->rowCount();

            $affectedBatches = array();
            foreach (array_values($affectedBatchIds) as $batchId) {
                $affectedBatches[] = $this->reconcileBatchAfterForceDelete((int)$batchId);
            }

            $this->db->commit();

            return array(
                'order_code' => $orderCode,
                'deleted_by_user_id' => $changedByUserId,
                'reason' => $reason,
                'deleted' => $deleted,
                'affected_batches' => $affectedBatches,
            );
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function reconcileBatchAfterForceDelete(int $batchId): array
    {
        $st = $this->db->prepare("
            SELECT
                id,
                batch_code,
                status,
                workflow_mode,
                basket_id,
                packing_owner_user_id,
                packing_owner_station_id,
                packing_locked_at
            FROM picking_batches
            WHERE id = :batch_id
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute(array(':batch_id' => $batchId));
        $batch = $st->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            return array(
                'batch_id' => $batchId,
                'state' => 'missing',
            );
        }

        $st = $this->db->prepare("
            SELECT COUNT(DISTINCT po.order_code) AS live_orders_total
            FROM picking_batch_orders pbo
            INNER JOIN pak_orders po ON po.order_code = pbo.order_code
            WHERE pbo.batch_id = :batch_id
        ");
        $st->execute(array(':batch_id' => $batchId));
        $liveOrdersTotal = (int)$st->fetchColumn();

        $st = $this->db->prepare("
            SELECT COUNT(*) AS active_sessions_total
            FROM packing_sessions
            WHERE picking_batch_id = :batch_id
              AND status IN ('open', 'paused')
        ");
        $st->execute(array(':batch_id' => $batchId));
        $activeSessionsTotal = (int)$st->fetchColumn();

        $basketAction = null;
        $basketId = !empty($batch['basket_id']) ? (int)$batch['basket_id'] : 0;

        if ($liveOrdersTotal === 0) {
            if ($basketId > 0) {
                $basket = $this->fetchBasketForUpdate($basketId);
                if ($basket && (int)($basket['reserved_batch_id'] ?? 0) === $batchId) {
                    $st = $this->db->prepare("
                        UPDATE workflow_baskets
                        SET status = 'empty',
                            reserved_batch_id = NULL,
                            reserved_by_user_id = NULL,
                            reserved_at = NULL,
                            picked_ready_at = NULL,
                            packing_started_at = NULL,
                            updated_at = NOW()
                        WHERE id = :basket_id
                    ");
                    $st->execute(array(':basket_id' => $basketId));
                    $basketAction = 'emptied';
                }
            }

            $st = $this->db->prepare("
                UPDATE picking_batches
                SET basket_id = NULL,
                    packing_owner_user_id = NULL,
                    packing_owner_station_id = NULL,
                    packing_locked_at = NULL,
                    status = 'abandoned',
                    abandoned_at = COALESCE(abandoned_at, NOW()),
                    updated_at = NOW()
                WHERE id = :batch_id
            ");
            $st->execute(array(':batch_id' => $batchId));

            return array(
                'batch_id' => $batchId,
                'batch_code' => (string)$batch['batch_code'],
                'state' => 'abandoned',
                'live_orders_total' => $liveOrdersTotal,
                'active_sessions_total' => $activeSessionsTotal,
                'basket_action' => $basketAction,
            );
        }

        if ($activeSessionsTotal === 0) {
            $st = $this->db->prepare("
                UPDATE picking_batches
                SET packing_owner_user_id = NULL,
                    packing_owner_station_id = NULL,
                    packing_locked_at = NULL,
                    updated_at = NOW()
                WHERE id = :batch_id
            ");
            $st->execute(array(':batch_id' => $batchId));

            if ((string)($batch['workflow_mode'] ?? '') === 'split' && $basketId > 0) {
                $basket = $this->fetchBasketForUpdate($basketId);
                if ($basket
                    && (int)($basket['reserved_batch_id'] ?? 0) === $batchId
                    && (string)($basket['status'] ?? '') === 'packing_in_progress'
                ) {
                    $st = $this->db->prepare("
                        UPDATE workflow_baskets
                        SET status = 'picked_ready',
                            packing_started_at = NULL,
                            updated_at = NOW()
                        WHERE id = :basket_id
                    ");
                    $st->execute(array(':basket_id' => $basketId));
                    $basketAction = 'reverted_to_picked_ready';
                }
            }

            return array(
                'batch_id' => $batchId,
                'batch_code' => (string)$batch['batch_code'],
                'state' => 'released',
                'live_orders_total' => $liveOrdersTotal,
                'active_sessions_total' => $activeSessionsTotal,
                'basket_action' => $basketAction,
            );
        }

        return array(
            'batch_id' => $batchId,
            'batch_code' => (string)$batch['batch_code'],
            'state' => 'kept',
            'live_orders_total' => $liveOrdersTotal,
            'active_sessions_total' => $activeSessionsTotal,
            'basket_action' => $basketAction,
        );
    }

    private function fetchBasketForUpdate(int $basketId): ?array
    {
        $st = $this->db->prepare("
            SELECT
                id,
                basket_no,
                package_mode,
                status,
                reserved_batch_id,
                reserved_by_user_id,
                reserved_at,
                picked_ready_at,
                packing_started_at
            FROM workflow_baskets
            WHERE id = :basket_id
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute(array(':basket_id' => $basketId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function buildInClause(array $values, string $prefix): array
    {
        $params = array();
        $placeholders = array();
        $i = 0;

        foreach ($values as $value) {
            $key = ':' . $prefix . $i;
            $params[$key] = $value;
            $placeholders[] = $key;
            $i++;
        }

        return array(
            'clause' => implode(',', $placeholders),
            'params' => $params,
        );
    }

}
