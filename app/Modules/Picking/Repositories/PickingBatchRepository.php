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
            SELECT id, batch_code, carrier_key, user_id, station_id,
                   status, workflow_mode, target_orders_count,
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
            SELECT id, batch_code, carrier_key, user_id, station_id,
                   status, workflow_mode, target_orders_count,
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
            SELECT id, batch_code, carrier_key, user_id, station_id,
                   status, workflow_mode, target_orders_count,
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
            SELECT id, batch_code, carrier_key, user_id, station_id,
                   status, workflow_mode, target_orders_count,
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
        int $userId,
        int $stationId,
        string $workflowMode,
        int $targetOrdersCount
    ): int {
        $sql = "
            INSERT INTO picking_batches
                (batch_code, carrier_key, user_id, station_id, status,
                 workflow_mode, target_orders_count, started_at, created_at, updated_at)
            VALUES
                (:batch_code, :carrier_key, :user_id, :station_id, 'open',
                 :workflow_mode, :target_orders_count, NOW(), NOW(), NOW())
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_code'          => $batchCode,
            ':carrier_key'         => $carrierKey,
            ':user_id'             => $userId,
            ':station_id'          => $stationId,
            ':workflow_mode'       => $workflowMode,
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

        $params[] = $margin;

        $sql = "
            SELECT order_code, delivery_method, carrier_code, courier_code
            FROM pak_orders
            WHERE status = 10
              $excludeClause
            ORDER BY imported_at ASC, order_code ASC
            LIMIT ?
        ";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC);

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($mapCfg);
        $result = [];

        foreach ($candidates as $row) {
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($row['delivery_method'] ?? ''),
                'carrier_code'    => (string)($row['carrier_code'] ?? ''),
                'courier_code'    => (string)($row['courier_code'] ?? ''),
            ]);

            if ((string)$resolved['menu_group'] === $carrierKey) {
                $result[] = $row;
                if (count($result) >= $limit) {
                    break;
                }
            }
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
            ORDER BY pbo.assigned_at ASC
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
                poi.product_code,
                poi.product_name,
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
            $itemsByBatchOrderId[(int)$item['batch_order_id']][] = [
                'id'               => (int)$item['id'],
                'pak_order_item_id'=> (int)$item['pak_order_item_id'],
                'product_code'     => (string)$item['product_code'],
                'product_name'     => (string)$item['product_name'],
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
            SELECT item_id, order_code, sku, name, subiekt_desc,
                   quantity, image_url
            FROM pak_order_items
            WHERE order_code = :order_code
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':order_code' => $orderCode]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertPickingOrderItem(
        int $batchOrderId,
        int $pakOrderItemId,
        string $productCode,
        string $productName,
        float $expectedQty
    ): int {
        $sql = "
            INSERT INTO picking_order_items
                (batch_order_id, pak_order_item_id, product_code, product_name,
                 expected_qty, picked_qty, status, created_at, updated_at)
            VALUES
                (:batch_order_id, :pak_order_item_id, :product_code, :product_name,
                 :expected_qty, 0, 'pending', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                expected_qty = VALUES(expected_qty),
                updated_at = NOW()
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':batch_order_id'    => $batchOrderId,
            ':pak_order_item_id' => $pakOrderItemId,
            ':product_code'      => $productCode,
            ':product_name'      => $productName,
            ':expected_qty'      => $expectedQty,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function findPickingOrderItem(int $batchOrderId, int $pakOrderItemId): ?array
    {
        $sql = "
            SELECT id, batch_order_id, pak_order_item_id, product_code,
                   product_name, expected_qty, picked_qty, status, missing_reason
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
            SELECT id, batch_order_id, pak_order_item_id, product_code,
                   product_name, expected_qty, picked_qty, status, missing_reason
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

    // -------------------------------------------------------------------------
    // BATCH ITEMS (agregaty)
    // -------------------------------------------------------------------------

    public function rebuildBatchItems(int $batchId): void
    {
        $sqlDelete = "DELETE FROM picking_batch_items WHERE batch_id = :batch_id";
        $st = $this->db->prepare($sqlDelete);
        $st->execute([':batch_id' => $batchId]);

        $sqlInsert = "
            INSERT INTO picking_batch_items
                (batch_id, product_code, product_name,
                 total_expected_qty, total_picked_qty, status,
                 created_at, updated_at)
            SELECT
                pb.id AS batch_id,
                poi.product_code,
                poi.product_name,
                SUM(poi.expected_qty) AS total_expected_qty,
                SUM(poi.picked_qty)   AS total_picked_qty,
                CASE
                    WHEN SUM(poi.expected_qty) <= SUM(poi.picked_qty) THEN 'picked'
                    WHEN SUM(poi.picked_qty) > 0 THEN 'partial'
                    ELSE 'pending'
                END AS status,
                NOW(),
                NOW()
            FROM picking_batch_orders pbo
            INNER JOIN picking_batches pb ON pb.id = pbo.batch_id
            INNER JOIN picking_order_items poi ON poi.batch_order_id = pbo.id
            WHERE pb.id = :batch_id
              AND pbo.status NOT IN ('dropped')
            GROUP BY pb.id, poi.product_code, poi.product_name
        ";
        $st = $this->db->prepare($sqlInsert);
        $st->execute([':batch_id' => $batchId]);
    }

    public function getBatchItems(int $batchId): array
    {
        $sql = "
            SELECT id, product_code, product_name,
                   total_expected_qty, total_picked_qty, status
            FROM picking_batch_items
            WHERE batch_id = :batch_id
            ORDER BY product_name ASC
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':batch_id' => $batchId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------------------
    // EVENTS
    // -------------------------------------------------------------------------

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
// placeholder
