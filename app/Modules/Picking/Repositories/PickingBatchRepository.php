<?php
declare(strict_types=1);

final class PickingBatchRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // BATCH
    // -------------------------------------------------------------------------

    public function findOpenBatchForUser(int $userId): ?array
    {
        $sql = "
            SELECT id, batch_code, carrier_key, package_mode, user_id, station_id,
                   status, workflow_mode, selection_mode, target_orders_count,
                   started_at, completed_at, abandoned_at
            FROM picking_batches
            WHERE user_id = :user_id
              AND status = 'open'
            ORDER BY started_at DESC
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':user_id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findOpenBatchForUserForUpdate(int $userId): ?array
    {
        $sql = "
            SELECT id, batch_code, carrier_key, package_mode, user_id, station_id,
                   status, workflow_mode, selection_mode, target_orders_count,
                   started_at, completed_at, abandoned_at
            FROM picking_batches
            WHERE user_id = :user_id
              AND status = 'open'
            ORDER BY started_at DESC
            LIMIT 1
            FOR UPDATE
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':user_id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBatchById(int $batchId): ?array
    {
        $sql = "
            SELECT id, batch_code, carrier_key, package_mode, user_id, station_id,
                   status, workflow_mode, selection_mode, target_orders_count,
                   started_at, completed_at, abandoned_at
            FROM picking_batches
            WHERE id = :id
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':id' => $batchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBatchByIdForUpdate(int $batchId): ?array
    {
        $sql = "
            SELECT id, batch_code, carrier_key, package_mode, user_id, station_id,
                   status, workflow_mode, selection_mode, target_orders_count,
                   started_at, completed_at, abandoned_at
            FROM picking_batches
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':id' => $batchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getBatchStats(int $batchId): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) AS assigned_count,
                SUM(CASE WHEN status = 'picked' THEN 1 ELSE 0 END) AS picked_count,
                SUM(CASE WHEN status = 'dropped' THEN 1 ELSE 0 END) AS dropped_count
            FROM picking_batch_orders
            WHERE batch_id = :batch_id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['total' => 0, 'assigned_count' => 0, 'picked_count' => 0, 'dropped_count' => 0];
    }

    public function createBatch(
        string $batchCode,
        string $carrierKey,
        string $packageMode,
        int $userId,
        int $stationId,
        string $workflowMode,
        string $selectionMode,
        int $targetOrdersCount
    ): int {
        $sql = "
            INSERT INTO picking_batches
                (batch_code, carrier_key, package_mode, user_id, station_id, status,
                 workflow_mode, selection_mode, target_orders_count, started_at, created_at, updated_at)
            VALUES
                (:batch_code, :carrier_key, :package_mode, :user_id, :station_id, 'open',
                 :workflow_mode, :selection_mode, :target_orders_count, NOW(), NOW(), NOW())
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_code'          => $batchCode,
            ':carrier_key'         => $carrierKey,
            ':package_mode'        => $packageMode,
            ':user_id'             => $userId,
            ':station_id'          => $stationId,
            ':workflow_mode'       => $workflowMode,
            ':selection_mode'      => $selectionMode,
            ':target_orders_count' => $targetOrdersCount,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function closeBatch(int $batchId): void
    {
        $sql = "
            UPDATE picking_batches
            SET status = 'completed',
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':id' => $batchId]);
    }

    public function updateBatchSelectionMode(int $batchId, string $selectionMode): void
    {
        $sql = "
            UPDATE picking_batches
            SET selection_mode = :selection_mode,
                updated_at = NOW()
            WHERE id = :id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':id' => $batchId,
            ':selection_mode' => $selectionMode,
        ]);
    }

    public function countActiveBatchOrders(int $batchId): int
    {
        $sql = "
            SELECT COUNT(*) FROM picking_batch_orders
            WHERE batch_id = :batch_id
              AND status NOT IN ('dropped')
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId]);
        return (int)$st->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // ORDER SELECTION
    // -------------------------------------------------------------------------

    public function getOrderCodesInOpenBatches(): array
    {
        $sql = "
            SELECT DISTINCT pbo.order_code
            FROM picking_batch_orders pbo
            INNER JOIN picking_batches pb ON pb.id = pbo.batch_id
            WHERE pb.status = 'open'
              AND pbo.status NOT IN ('dropped')
        ";
        $st = $this->db->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    }

    public function getAllOrderCodesInBatch(int $batchId): array
    {
        $sql = "
            SELECT order_code
            FROM picking_batch_orders
            WHERE batch_id = :batch_id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    }

    public function findAvailableOrdersForGroup(
        string $carrierKey,
        string $packageMode,
        array $excludeOrderCodes,
        int $limit,
        array $mapCfg
    ): array {
        $margin = max($limit * 5, 100);

        $excludeClause = '';
        $params = [];

        if (!empty($excludeOrderCodes)) {
            $excPlaceholders = implode(',', array_fill(0, count($excludeOrderCodes), '?'));
            $excludeClause = "AND order_code NOT IN ($excPlaceholders)";
            $params = $excludeOrderCodes;
        }

        $sql = "
            SELECT order_code, delivery_method, carrier_code, courier_code
            FROM pak_orders
            WHERE status = 10
              AND NOT EXISTS (
                  SELECT 1
                  FROM order_backlog_holds obh
                  WHERE obh.order_code = pak_orders.order_code
                    AND obh.status = 'open'
              )
              $excludeClause
            ORDER BY imported_at ASC, order_code ASC
            LIMIT " . (int)$margin . "
        ";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            return array();
        }

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($mapCfg);

        $candidateOrderCodes = array();
        foreach ($candidates as $candidate) {
            $candidateOrderCodes[] = (string)$candidate['order_code'];
        }

        $orderPackageModes = $this->getOrderPackageModes($candidateOrderCodes);
        $result = [];

        foreach ($candidates as $row) {
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($row['delivery_method'] ?? ''),
                'carrier_code'    => (string)($row['carrier_code'] ?? ''),
                'courier_code'    => (string)($row['courier_code'] ?? ''),
            ]);

            $orderCode = (string)$row['order_code'];
            $orderPackageMode = isset($orderPackageModes[$orderCode])
                ? (string)$orderPackageModes[$orderCode]
                : 'unknown';

            if ((string)$resolved['menu_group'] !== $carrierKey) {
                continue;
            }

            if ($orderPackageMode !== $packageMode) {
                continue;
            }

            $result[] = $row;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function findAvailableOrdersForGroupEmergencySingle(
        string $carrierKey,
        string $packageMode,
        array $excludeOrderCodes,
        array $mapCfg
    ): array {
        $margin = 200;

        $excludeClause = '';
        $params = [];

        if (!empty($excludeOrderCodes)) {
            $excPlaceholders = implode(',', array_fill(0, count($excludeOrderCodes), '?'));
            $excludeClause = "AND order_code NOT IN ($excPlaceholders)";
            $params = $excludeOrderCodes;
        }

        $sql = "
            SELECT order_code, delivery_method, carrier_code, courier_code, courier_priority, imported_at
            FROM pak_orders
            WHERE status = 10
              AND NOT EXISTS (
                  SELECT 1
                  FROM order_backlog_holds obh
                  WHERE obh.order_code = pak_orders.order_code
                    AND obh.status = 'open'
              )
              $excludeClause
            ORDER BY courier_priority DESC, imported_at ASC, order_code ASC
            LIMIT " . (int)$margin . "
        ";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            return array();
        }

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($mapCfg);

        $candidateOrderCodes = array();
        foreach ($candidates as $candidate) {
            $candidateOrderCodes[] = (string)$candidate['order_code'];
        }

        $orderPackageModes = $this->getOrderPackageModes($candidateOrderCodes);

        foreach ($candidates as $row) {
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($row['delivery_method'] ?? ''),
                'carrier_code'    => (string)($row['carrier_code'] ?? ''),
                'courier_code'    => (string)($row['courier_code'] ?? ''),
            ]);

            $orderCode = (string)$row['order_code'];
            $orderPackageMode = isset($orderPackageModes[$orderCode])
                ? (string)$orderPackageModes[$orderCode]
                : 'unknown';

            if ((string)$resolved['menu_group'] !== $carrierKey) {
                continue;
            }

            if ($orderPackageMode !== $packageMode) {
                continue;
            }

            return array($row);
        }

        return array();
    }

    public function getOrderPackageModes(array $orderCodes): array
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
            $orderCode = (string)($row['order_code'] ?? '');
            if ($orderCode === '') {
                continue;
            }

            $packageMode = isset($row['package_mode']) ? trim((string)$row['package_mode']) : 'unknown';
            if (!in_array($packageMode, array('small', 'large', 'unknown'), true)) {
                $packageMode = 'unknown';
            }

            $result[$orderCode] = $packageMode;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // BATCH ORDERS
    // -------------------------------------------------------------------------

    public function insertBatchOrder(int $batchId, string $orderCode): int
    {
        $sql = "
            INSERT INTO picking_batch_orders
                (batch_id, order_code, status, assigned_at)
            VALUES
                (:batch_id, :order_code, 'assigned', NOW())
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_id'   => $batchId,
            ':order_code' => $orderCode,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function findBatchOrder(int $batchId, string $orderCode): ?array
    {
        $sql = "
            SELECT id, batch_id, order_code, status, drop_reason, assigned_at, removed_at
            FROM picking_batch_orders
            WHERE batch_id = :batch_id
              AND order_code = :order_code
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId, ':order_code' => $orderCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBatchOrderById(int $batchId, int $batchOrderId): ?array
    {
        $sql = "
            SELECT id, batch_id, order_code, status, drop_reason, assigned_at, removed_at
            FROM picking_batch_orders
            WHERE batch_id = :batch_id
              AND id = :id
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId, ':id' => $batchOrderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getBatchOrders(int $batchId): array
    {
        $sql = "
        SELECT
            pbo.id,
            pbo.order_code,
            pbo.status,
            pbo.drop_reason,
            pbo.assigned_at,
            pbo.removed_at,
            po.delivery_method,
            po.carrier_code,
            po.courier_code
        FROM picking_batch_orders pbo
        INNER JOIN pak_orders po ON po.order_code = pbo.order_code
        WHERE pbo.batch_id = :batch_id
          AND pbo.status NOT IN ('dropped')
        ORDER BY pbo.assigned_at ASC, pbo.id ASC
    ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            return [];
        }

        $batchOrderIds = array_map(static function (array $row): int {
            return (int)$row['id'];
        }, $orders);

        $placeholders = implode(',', array_fill(0, count($batchOrderIds), '?'));

        $sqlItems = "
        SELECT
            poi.id,
            poi.batch_order_id,
            poi.pak_order_item_id,
            poi.subiekt_tow_id,
            poi.subiekt_symbol,
            poi.subiekt_desc,
            poi.source_name,
            poi.product_code,
            poi.product_name,
            poi.uom,
            poi.is_unmapped,
            poi.expected_qty,
            poi.picked_qty,
            poi.status,
            poi.missing_reason
        FROM picking_order_items poi
        WHERE poi.batch_order_id IN ($placeholders)
        ORDER BY poi.batch_order_id ASC, poi.id ASC
    ";
        $stItems = $this->db->prepare($sqlItems);
        $stItems->execute($batchOrderIds);
        $items = $stItems->fetchAll(PDO::FETCH_ASSOC);

        $itemsByBatchOrderId = [];
        foreach ($items as $item) {
            $subiektTowId = isset($item['subiekt_tow_id']) && $item['subiekt_tow_id'] !== null
                ? (int)$item['subiekt_tow_id']
                : null;

            $itemsByBatchOrderId[(int)$item['batch_order_id']][] = [
                'id'               => (int)$item['id'],
                'pak_order_item_id'=> (int)$item['pak_order_item_id'],
                'subiekt_tow_id'   => $subiektTowId,
                'subiekt_symbol'   => $item['subiekt_symbol'] !== null ? (string)$item['subiekt_symbol'] : null,
                'subiekt_desc'     => $item['subiekt_desc'] !== null ? (string)$item['subiekt_desc'] : null,
                'source_name'      => $item['source_name'] !== null ? (string)$item['source_name'] : null,
                'product_code'     => (string)$item['product_code'],
                'product_name'     => (string)$item['product_name'],
                'uom'              => $item['uom'] !== null ? (string)$item['uom'] : null,
                'is_unmapped'      => (bool)$item['is_unmapped'],
                'expected_qty'     => (float)$item['expected_qty'],
                'picked_qty'       => (float)$item['picked_qty'],
                'status'           => (string)$item['status'],
                'missing_reason'   => $item['missing_reason'],
            ];
        }

        foreach ($orders as &$order) {
            $order['items'] = $itemsByBatchOrderId[(int)$order['id']] ?? [];
        }
        unset($order);

        return $orders;
    }


    public function dropBatchOrder(int $batchOrderId, string $reason): void
    {
        $sql = "
            UPDATE picking_batch_orders
            SET status = 'dropped',
                drop_reason = :reason,
                removed_at = NOW()
            WHERE id = :id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':id' => $batchOrderId, ':reason' => $reason]);
    }

    public function markBatchOrderPicked(int $batchOrderId): void
    {
        $sql = "
            UPDATE picking_batch_orders
            SET status = 'picked'
            WHERE id = :id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':id' => $batchOrderId]);
    }

    public function countPendingItemsForBatchOrder(int $batchOrderId): int
    {
        $sql = "
            SELECT COUNT(*) FROM picking_order_items
            WHERE batch_order_id = :batch_order_id
              AND status NOT IN ('picked', 'missing')
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_order_id' => $batchOrderId]);
        return (int)$st->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // ORDER ITEMS
    // -------------------------------------------------------------------------

    public function getOrderItems(string $orderCode): array
    {
        $sql = "
        SELECT item_id, order_code, offer_id, subiekt_tow_id, subiekt_symbol, sku, name, subiekt_desc,
               quantity, image_url, NULL AS uom
        FROM pak_order_items
        WHERE order_code = :order_code
    ";
        $st = $this->db->prepare($sql);
        $st->execute([':order_code' => $orderCode]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOrderItemsForOrderCodes(array $orderCodes): array
    {
        if (empty($orderCodes)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($orderCodes), '?'));

        $sql = "
        SELECT item_id, order_code, offer_id, subiekt_tow_id, subiekt_symbol, sku, name, subiekt_desc,
               quantity, image_url, NULL AS uom
        FROM pak_order_items
        WHERE order_code IN ($placeholders)
        ORDER BY order_code ASC, item_id ASC
    ";
        $st = $this->db->prepare($sql);
        $st->execute(array_values($orderCodes));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }


    public function insertPickingOrderItem(
        int $batchOrderId,
        int $pakOrderItemId,
        ?int $subiektTowId,
        ?string $subiektSymbol,
        ?string $subiektDesc,
        ?string $sourceName,
        string $productCode,
        string $productName,
        ?string $uom,
        bool $isUnmapped,
        float $expectedQty,
        string $status = 'pending'
    ): int {
        $pickedQty = $status === 'picked' ? $expectedQty : 0;

        $sql = "
        INSERT INTO picking_order_items
            (batch_order_id, pak_order_item_id, subiekt_tow_id, subiekt_symbol, subiekt_desc, source_name, product_code, product_name,
             uom, is_unmapped, expected_qty, picked_qty, status, created_at, updated_at)
        VALUES
            (:batch_order_id, :pak_order_item_id, :subiekt_tow_id, :subiekt_symbol, :subiekt_desc, :source_name, :product_code, :product_name,
             :uom, :is_unmapped, :expected_qty, :picked_qty, :status, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            subiekt_tow_id = VALUES(subiekt_tow_id),
            subiekt_symbol = VALUES(subiekt_symbol),
            subiekt_desc = VALUES(subiekt_desc),
            source_name = VALUES(source_name),
            product_code = VALUES(product_code),
            product_name = VALUES(product_name),
            uom = VALUES(uom),
            is_unmapped = VALUES(is_unmapped),
            expected_qty = VALUES(expected_qty),
            updated_at = NOW()
    ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_order_id'    => $batchOrderId,
            ':pak_order_item_id' => $pakOrderItemId,
            ':subiekt_tow_id'    => $subiektTowId,
            ':subiekt_symbol'    => $subiektSymbol,
            ':subiekt_desc'      => $subiektDesc,
            ':source_name'       => $sourceName,
            ':product_code'      => $productCode,
            ':product_name'      => $productName,
            ':uom'               => $uom,
            ':is_unmapped'       => $isUnmapped ? 1 : 0,
            ':expected_qty'      => $expectedQty,
            ':picked_qty'        => $pickedQty,
            ':status'            => $status,
        ]);
        return (int)$this->db->lastInsertId();
    }


    public function findPickingOrderItem(int $batchOrderId, int $pakOrderItemId): ?array
    {
        $sql = "
        SELECT id, batch_order_id, pak_order_item_id, subiekt_tow_id, subiekt_symbol, subiekt_desc, source_name, product_code,
               product_name, uom, is_unmapped, expected_qty, picked_qty, status, missing_reason
        FROM picking_order_items
        WHERE batch_order_id = :batch_order_id
          AND pak_order_item_id = :pak_order_item_id
        LIMIT 1
    ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_order_id'    => $batchOrderId,
            ':pak_order_item_id' => $pakOrderItemId,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }


    public function findPickingOrderItemById(int $batchOrderId, int $itemId): ?array
    {
        $sql = "
        SELECT id, batch_order_id, pak_order_item_id, subiekt_tow_id, subiekt_symbol, subiekt_desc, source_name, product_code,
               product_name, uom, is_unmapped, expected_qty, picked_qty, status, missing_reason
        FROM picking_order_items
        WHERE batch_order_id = :batch_order_id
          AND id = :id
        LIMIT 1
    ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_order_id' => $batchOrderId,
            ':id'             => $itemId,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }


    public function markItemPicked(int $itemId, float $pickedQty, int $userId): void
    {
        $sql = "
            UPDATE picking_order_items
            SET picked_qty = :picked_qty,
                status = 'picked',
                updated_by_user_id = :user_id,
                updated_at = NOW()
            WHERE id = :id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':picked_qty' => $pickedQty,
            ':user_id'    => $userId,
            ':id'         => $itemId,
        ]);
    }

    public function markItemMissing(int $itemId, string $reason, int $userId): void
    {
        $sql = "
            UPDATE picking_order_items
            SET status = 'missing',
                missing_reason = :reason,
                updated_by_user_id = :user_id,
                updated_at = NOW()
            WHERE id = :id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':reason'  => $reason,
            ':user_id' => $userId,
            ':id'      => $itemId,
        ]);
    }

    public function createBacklogHold(
        string $orderCode,
        int $pakOrderItemId,
        ?int $subiektTowId,
        ?string $productCode,
        string $productName,
        float $missingQty,
        string $holdType,
        ?string $holdReason,
        int $userId
    ): void {
        $check = $this->db->prepare("
            SELECT id
            FROM order_backlog_holds
            WHERE order_code = :order_code
              AND pak_order_item_id = :pak_order_item_id
              AND status = 'open'
            LIMIT 1
        ");
        $check->execute([
            ':order_code'       => $orderCode,
            ':pak_order_item_id'=> $pakOrderItemId,
        ]);

        if ($check->fetch(PDO::FETCH_ASSOC)) {
            return;
        }

        $sql = "
            INSERT INTO order_backlog_holds (
                order_code,
                pak_order_item_id,
                subiekt_tow_id,
                product_code,
                product_name,
                missing_qty,
                hold_type,
                hold_reason,
                status,
                created_by_user_id,
                created_at,
                updated_at
            ) VALUES (
                :order_code,
                :pak_order_item_id,
                :subiekt_tow_id,
                :product_code,
                :product_name,
                :missing_qty,
                :hold_type,
                :hold_reason,
                'open',
                :user_id,
                NOW(),
                NOW()
            )
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':order_code'        => $orderCode,
            ':pak_order_item_id' => $pakOrderItemId,
            ':subiekt_tow_id'    => $subiektTowId,
            ':product_code'      => $productCode,
            ':product_name'      => $productName,
            ':missing_qty'       => $missingQty,
            ':hold_type'         => $holdType,
            ':hold_reason'       => $holdReason,
            ':user_id'           => $userId,
        ]);
    }

    // -------------------------------------------------------------------------
    // BATCH ITEMS (agregaty)
    // -------------------------------------------------------------------------

    public function rebuildBatchItems(int $batchId): void
    {
        $sqlDelete = "DELETE FROM picking_batch_items WHERE batch_id = :batch_id";
        $stDelete = $this->db->prepare($sqlDelete);
        $stDelete->execute([':batch_id' => $batchId]);

        $sql = "
        SELECT
            pbo.id AS batch_order_id,
            pbo.order_code,
            pbo.assigned_at,
            poi.id,
            poi.pak_order_item_id,
            poi.subiekt_tow_id,
            poi.subiekt_symbol,
            poi.subiekt_desc,
            poi.source_name,
            poi.product_code,
            poi.product_name,
            poi.uom,
            poi.is_unmapped,
            poi.expected_qty,
            poi.picked_qty,
            poi.status
        FROM picking_batch_orders pbo
        INNER JOIN picking_order_items poi ON poi.batch_order_id = pbo.id
        WHERE pbo.batch_id = :batch_id
          AND pbo.status NOT IN ('dropped')
        ORDER BY pbo.assigned_at ASC, pbo.id ASC, poi.id ASC
    ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return;
        }

        $aggregates = [];

        foreach ($rows as $row) {
            $subiektTowId = isset($row['subiekt_tow_id']) && $row['subiekt_tow_id'] !== null
                ? (int)$row['subiekt_tow_id']
                : null;
            $uom = $row['uom'] !== null ? trim((string)$row['uom']) : null;
            if ($uom === '') {
                $uom = null;
            }

            $isUnmapped = (bool)$row['is_unmapped'] || $subiektTowId === null || $subiektTowId <= 0;
            $aggregateKey = $this->buildBatchAggregateKey(
                $subiektTowId,
                $uom,
                $isUnmapped,
                (int)$row['pak_order_item_id']
            );

            if (!isset($aggregates[$aggregateKey])) {
                $aggregates[$aggregateKey] = [
                    'batch_id'            => $batchId,
                    'subiekt_tow_id'      => $isUnmapped ? null : $subiektTowId,
                    'subiekt_symbol'      => $row['subiekt_symbol'] !== null ? (string)$row['subiekt_symbol'] : null,
                    'subiekt_desc'        => $row['subiekt_desc'] !== null ? (string)$row['subiekt_desc'] : null,
                    'source_name'         => $row['source_name'] !== null ? (string)$row['source_name'] : null,
                    'product_code'        => (string)$row['product_code'],
                    'product_name'        => (string)$row['product_name'],
                    'uom'                 => $uom,
                    'is_unmapped'         => $isUnmapped,
                    'total_expected_qty'  => 0.0,
                    'total_picked_qty'    => 0.0,
                    'total_missing_qty'   => 0.0,
                    'remaining_qty'       => 0.0,
                    'status_counts'       => [],
                    'qty_breakdown'       => [],
                    'order_breakdown_map' => [],
                ];
            }

            $expectedQty = (float)$row['expected_qty'];
            $pickedQty   = (float)$row['picked_qty'];
            $itemStatus  = (string)$row['status'];

            $aggregates[$aggregateKey]['total_expected_qty'] += $expectedQty;
            $aggregates[$aggregateKey]['total_picked_qty'] += $pickedQty;
            if ($itemStatus === 'missing') {
                $aggregates[$aggregateKey]['total_missing_qty'] += $expectedQty;
            }
            $aggregates[$aggregateKey]['status_counts'][$itemStatus] =
                ($aggregates[$aggregateKey]['status_counts'][$itemStatus] ?? 0) + 1;
            $aggregates[$aggregateKey]['qty_breakdown'][] = $this->castQtyForPayload($expectedQty);

            $orderCode = (string)$row['order_code'];
            if (!isset($aggregates[$aggregateKey]['order_breakdown_map'][$orderCode])) {
                $aggregates[$aggregateKey]['order_breakdown_map'][$orderCode] = [
                    'order_code'    => $orderCode,
                    'qty'           => 0.0,
                    'item_ids'      => [],
                    'item_count'    => 0,
                    'status_counts' => [],
                ];
            }

            $aggregates[$aggregateKey]['order_breakdown_map'][$orderCode]['qty'] += $expectedQty;
            $aggregates[$aggregateKey]['order_breakdown_map'][$orderCode]['item_ids'][] = (int)$row['id'];
            $aggregates[$aggregateKey]['order_breakdown_map'][$orderCode]['item_count']++;
            $aggregates[$aggregateKey]['order_breakdown_map'][$orderCode]['status_counts'][$itemStatus] =
                ($aggregates[$aggregateKey]['order_breakdown_map'][$orderCode]['status_counts'][$itemStatus] ?? 0) + 1;
        }

        $sqlInsert = "
        INSERT INTO picking_batch_items
            (batch_id, subiekt_tow_id, subiekt_symbol, subiekt_desc, source_name, product_code, product_name, uom, is_unmapped,
             total_expected_qty, total_picked_qty, total_missing_qty, remaining_qty, status,
             qty_breakdown_json, qty_breakdown_label, order_breakdown_json, created_at, updated_at)
        VALUES
            (:batch_id, :subiekt_tow_id, :subiekt_symbol, :subiekt_desc, :source_name, :product_code, :product_name, :uom, :is_unmapped,
             :total_expected_qty, :total_picked_qty, :total_missing_qty, :remaining_qty, :status,
             :qty_breakdown_json, :qty_breakdown_label, :order_breakdown_json, NOW(), NOW())
    ";
        $stInsert = $this->db->prepare($sqlInsert);

        foreach ($aggregates as $aggregate) {
            $aggregate['remaining_qty'] = $aggregate['total_expected_qty']
                - $aggregate['total_picked_qty']
                - $aggregate['total_missing_qty'];
            $aggregate['status'] = $this->determineBreakdownStatus($aggregate['status_counts']);

            $qtyBreakdown = array_values($aggregate['qty_breakdown']);
            $qtyBreakdownLabel = implode('+', array_map([$this, 'formatQtyLabelPart'], $qtyBreakdown));

            $orderBreakdown = [];
            foreach ($aggregate['order_breakdown_map'] as $orderRow) {
                $orderBreakdown[] = [
                    'order_code'     => (string)$orderRow['order_code'],
                    'qty'            => $this->castQtyForPayload((float)$orderRow['qty']),
                    'item_ids'       => array_values(array_map('intval', $orderRow['item_ids'])),
                    'item_count'     => (int)$orderRow['item_count'],
                    'status_summary' => $this->determineBreakdownStatus($orderRow['status_counts']),
                ];
            }

            $stInsert->execute([
                ':batch_id'             => (int)$aggregate['batch_id'],
                ':subiekt_tow_id'       => $aggregate['subiekt_tow_id'],
                ':subiekt_symbol'       => $aggregate['subiekt_symbol'],
                ':subiekt_desc'         => $aggregate['subiekt_desc'],
                ':source_name'          => $aggregate['source_name'],
                ':product_code'         => (string)$aggregate['product_code'],
                ':product_name'         => (string)$aggregate['product_name'],
                ':uom'                  => $aggregate['uom'],
                ':is_unmapped'          => $aggregate['is_unmapped'] ? 1 : 0,
                ':total_expected_qty'   => (float)$aggregate['total_expected_qty'],
                ':total_picked_qty'     => (float)$aggregate['total_picked_qty'],
                ':total_missing_qty'    => (float)$aggregate['total_missing_qty'],
                ':remaining_qty'        => (float)$aggregate['remaining_qty'],
                ':status'               => (string)$aggregate['status'],
                ':qty_breakdown_json'   => json_encode($qtyBreakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':qty_breakdown_label'  => $qtyBreakdownLabel,
                ':order_breakdown_json' => json_encode($orderBreakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }


    public function getBatchItems(int $batchId): array
    {
        $sql = "
        SELECT id, subiekt_tow_id, subiekt_symbol, subiekt_desc, source_name, product_code, product_name, uom, is_unmapped,
               total_expected_qty, total_picked_qty, total_missing_qty, remaining_qty,
               status, qty_breakdown_json, qty_breakdown_label, order_breakdown_json
        FROM picking_batch_items
        WHERE batch_id = :batch_id
        ORDER BY product_name ASC, product_code ASC
    ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['subiekt_tow_id'] = $row['subiekt_tow_id'] !== null ? (string)$row['subiekt_tow_id'] : null;
            $row['subiekt_symbol'] = $row['subiekt_symbol'] !== null ? (string)$row['subiekt_symbol'] : null;
            $row['subiekt_desc'] = $row['subiekt_desc'] !== null ? (string)$row['subiekt_desc'] : null;
            $row['source_name'] = $row['source_name'] !== null ? (string)$row['source_name'] : null;
            $row['uom'] = $row['uom'] !== null ? (string)$row['uom'] : null;
            $row['is_unmapped'] = (bool)$row['is_unmapped'];
            $row['total_expected_qty'] = (float)$row['total_expected_qty'];
            $row['total_picked_qty'] = (float)$row['total_picked_qty'];
            $row['total_missing_qty'] = (float)$row['total_missing_qty'];
            $row['remaining_qty'] = (float)$row['remaining_qty'];
            $row['qty_breakdown'] = $this->decodeJsonArray($row['qty_breakdown_json']);
            unset($row['qty_breakdown_json']);
            $row['qty_breakdown_label'] = (string)($row['qty_breakdown_label'] ?? '');
            $row['order_breakdown'] = $this->decodeJsonArray($row['order_breakdown_json']);
            unset($row['order_breakdown_json']);
        }
        unset($row);

        return $rows;
    }


    public function logEvent(
        int $batchId,
        string $eventType,
        ?int $batchOrderId = null,
        ?int $orderItemId = null,
        ?string $message = null,
        ?array $payload = null,
        ?int $userId = null
    ): void {
        $sql = "
            INSERT INTO picking_events
                (batch_id, batch_order_id, order_item_id, event_type,
                 event_message, payload_json, created_by_user_id, created_at)
            VALUES
                (:batch_id, :batch_order_id, :order_item_id, :event_type,
                 :event_message, :payload_json, :user_id, NOW())
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_id'       => $batchId,
            ':batch_order_id' => $batchOrderId,
            ':order_item_id'  => $orderItemId,
            ':event_type'     => $eventType,
            ':event_message'  => $message,
            ':payload_json'   => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ':user_id'        => $userId,
        ]);
    }

// -------------------------------------------------------------------------
// HELPERS
// -------------------------------------------------------------------------

    private function buildBatchAggregateKey(?int $subiektTowId, ?string $uom, bool $isUnmapped, int $pakOrderItemId): string
    {
        if ($isUnmapped || $subiektTowId === null || $subiektTowId <= 0) {
            return 'legacy:' . $pakOrderItemId;
        }

        return 'subiekt:' . $subiektTowId . '|uom:' . $this->normalizeUom($uom);
    }

    private function normalizeUom(?string $uom): string
    {
        $uom = $uom !== null ? trim((string)$uom) : '';
        return $uom === '' ? '' : strtolower($uom);
    }

    private function determineBreakdownStatus(array $statusCounts): string
    {
        $pendingCount = (int)($statusCounts['pending'] ?? 0);
        $pickedCount  = (int)($statusCounts['picked'] ?? 0);
        $missingCount = (int)($statusCounts['missing'] ?? 0);

        if ($pendingCount > 0 && $pickedCount === 0 && $missingCount === 0) {
            return 'pending';
        }

        if ($pickedCount > 0 && $pendingCount === 0 && $missingCount === 0) {
            return 'picked';
        }

        return 'partial';
    }

    private function castQtyForPayload(float $qty)
    {
        $rounded = round($qty, 3);
        if (abs($rounded - round($rounded)) < 0.00001) {
            return (int)round($rounded);
        }

        return $rounded;
    }

    private function formatQtyLabelPart($qty): string
    {
        if (is_int($qty)) {
            return (string)$qty;
        }

        $formatted = number_format((float)$qty, 3, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function decodeJsonArray($json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : [];
    }

    // -------------------------------------------------------------------------
    // TRANSACTIONS
    // -------------------------------------------------------------------------

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
    /**
     * Zwraca ostatni batch usera ze statusem 'completed',
     * który ma co najmniej jedno zamówienie (picked) bez zakończonej sesji packingu.
     *
     * Używane przez WorkflowController do wykrycia sytuacji:
     * picking zamknięty → packing jeszcze nie ruszył.
     */
    public function findCompletedBatchWithPendingPacking(int $userId): ?array
    {
        $sql = "
            SELECT pb.id, pb.batch_code, pb.carrier_key, pb.package_mode,
                   pb.completed_at
            FROM picking_batches pb
            WHERE pb.user_id = :user_id
              AND pb.status  = 'completed'
              AND EXISTS (
                  SELECT 1
                  FROM picking_batch_orders pbo
                  WHERE pbo.batch_id = pb.id
                    AND pbo.status   = 'picked'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM packing_sessions ps
                        WHERE ps.order_code = pbo.order_code
                          AND ps.status     = 'completed'
                    )
              )
            ORDER BY pb.completed_at DESC
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':user_id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function abandonBatch(int $batchId): void
    {
        $sql = "
            UPDATE picking_batches
            SET status = 'abandoned',
                abandoned_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':id' => $batchId]);
    }
}