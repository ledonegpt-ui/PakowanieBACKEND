<?php
declare(strict_types=1);

final class PackingRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // SESSIONS
    // -------------------------------------------------------------------------

    public function findOpenSessionForUser(int $userId): ?array
    {
        $st = $this->db->prepare("
            SELECT id, session_code, order_code, picking_batch_id,
                   user_id, station_id, status,
                   started_at, completed_at, cancelled_at, last_seen_at
            FROM packing_sessions
            WHERE user_id = :user_id
              AND status = 'open'
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $st->execute([':user_id' => $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findOpenSessionForUserForUpdate(int $userId): ?array
    {
        $st = $this->db->prepare("
            SELECT id, session_code, order_code, picking_batch_id,
                   user_id, station_id, status,
                   started_at, completed_at, cancelled_at, last_seen_at
            FROM packing_sessions
            WHERE user_id = :user_id
              AND status = 'open'
            ORDER BY started_at DESC
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([':user_id' => $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findOpenSessionForOrder(string $orderCode): ?array
    {
        $st = $this->db->prepare("
            SELECT id, session_code, order_code, picking_batch_id,
                   user_id, station_id, status,
                   started_at, completed_at, cancelled_at, last_seen_at
            FROM packing_sessions
            WHERE order_code = :order_code
              AND status = 'open'
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([':order_code' => $orderCode]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findSessionByOrderCode(string $orderCode): ?array
    {
        $st = $this->db->prepare("
            SELECT id, session_code, order_code, picking_batch_id,
                   user_id, station_id, status,
                   started_at, completed_at, cancelled_at, last_seen_at
            FROM packing_sessions
            WHERE order_code = :order_code
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $st->execute([':order_code' => $orderCode]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createSession(
        string $sessionCode,
        string $orderCode,
        ?int $pickingBatchId,
        int $userId,
        int $stationId
    ): int {
        $st = $this->db->prepare("
            INSERT INTO packing_sessions
                (session_code, order_code, picking_batch_id, user_id, station_id,
                 status, started_at, created_at, updated_at)
            VALUES
                (:session_code, :order_code, :picking_batch_id, :user_id, :station_id,
                 'open', NOW(), NOW(), NOW())
        ");
        $st->execute([
            ':session_code'     => $sessionCode,
            ':order_code'       => $orderCode,
            ':picking_batch_id' => $pickingBatchId,
            ':user_id'          => $userId,
            ':station_id'       => $stationId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function closeSession(int $sessionId): void
    {
        $st = $this->db->prepare("
            UPDATE packing_sessions
            SET status = 'completed', completed_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ");
        $st->execute([':id' => $sessionId]);
    }

    public function cancelSession(int $sessionId): void
    {
        $st = $this->db->prepare("
            UPDATE packing_sessions
            SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ");
        $st->execute([':id' => $sessionId]);
    }

    public function abandonSession(int $sessionId): void
    {
        $st = $this->db->prepare("
            UPDATE packing_sessions
            SET status = 'abandoned', cancelled_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ");
        $st->execute([':id' => $sessionId]);
    }

    public function updateHeartbeat(int $sessionId): void
    {
        $st = $this->db->prepare("
            UPDATE packing_sessions
            SET last_seen_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ");
        $st->execute([':id' => $sessionId]);
    }

    // -------------------------------------------------------------------------
    // SESSION ITEMS
    // -------------------------------------------------------------------------

    public function insertSessionItem(
        int $sessionId,
        int $pakOrderItemId,
        string $productCode,
        string $productName,
        float $expectedQty
    ): void {
        $st = $this->db->prepare("
            INSERT INTO packing_session_items
                (packing_session_id, pak_order_item_id, product_code, product_name,
                 expected_qty, packed_qty, created_at, updated_at)
            VALUES
                (:session_id, :pak_order_item_id, :product_code, :product_name,
                 :expected_qty, 0, NOW(), NOW())
        ");
        $st->execute([
            ':session_id'        => $sessionId,
            ':pak_order_item_id' => $pakOrderItemId,
            ':product_code'      => $productCode,
            ':product_name'      => $productName,
            ':expected_qty'      => $expectedQty,
        ]);
    }

    public function getSessionItems(int $sessionId): array
    {
        $st = $this->db->prepare("
            SELECT id, pak_order_item_id, product_code, product_name,
                   expected_qty, packed_qty
            FROM packing_session_items
            WHERE packing_session_id = :session_id
            ORDER BY product_name ASC
        ");
        $st->execute([':session_id' => $sessionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAllItemsPacked(int $sessionId): void
    {
        $st = $this->db->prepare("
            UPDATE packing_session_items
            SET packed_qty = expected_qty, updated_at = NOW()
            WHERE packing_session_id = :session_id
        ");
        $st->execute([':session_id' => $sessionId]);
    }

    // -------------------------------------------------------------------------
    // ORDER
    // -------------------------------------------------------------------------

    public function findOrder(string $orderCode): ?array
    {
        $st = $this->db->prepare("
            SELECT order_code, status, delivery_method, carrier_code, courier_code,
                   delivery_fullname, delivery_city, delivery_postcode,
                   delivery_address, phone, email, pickup_point_id, pickup_point_name, pickup_point_address,
                   pack_started_at, pack_ended_at, packer, station,
                   label_source, printed_at, printed_by, print_count, nr_nadania, cod_amount, cod_currency,
                   bl_order_id, bl_package_id, allegro_parcel_id, shop_order_id
            FROM pak_orders
            WHERE order_code = :order_code
            LIMIT 1
        ");
        $st->execute([':order_code' => $orderCode]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findOrderItems(string $orderCode): array
    {
        $st = $this->db->prepare("
            SELECT item_id, order_code, sku, name, subiekt_desc,
                   quantity, image_url
            FROM pak_order_items
            WHERE order_code = :order_code
        ");
        $st->execute([':order_code' => $orderCode]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCompletedPickingBatchOrder(string $orderCode): ?array
    {
        $st = $this->db->prepare("
            SELECT pbo.id, pbo.batch_id, pbo.status
            FROM picking_batch_orders pbo
            INNER JOIN picking_batches pb ON pb.id = pbo.batch_id
            WHERE pbo.order_code = :order_code
              AND pbo.status = 'picked'
            ORDER BY pbo.id DESC
            LIMIT 1
        ");
        $st->execute([':order_code' => $orderCode]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateOrderPackingFinished(
        string $orderCode,
        string $packerLogin,
        string $stationCode,
        string $carrierCode,
        string $labelSource,
        ?string $nrNadania,
        ?string $courierCode
    ): void {
        $st = $this->db->prepare("
            UPDATE pak_orders
            SET pack_ended_at = NOW(),
                packer        = :packer,
                station       = :station,
                carrier_code  = :carrier_code,
                label_source  = :label_source,
                nr_nadania    = :nr_nadania,
                courier_code  = :courier_code,
                updated_at    = NOW()
            WHERE order_code = :order_code
        ");
        $st->execute([
            ':order_code'   => $orderCode,
            ':packer'       => $packerLogin,
            ':station'      => $stationCode,
            ':carrier_code' => $carrierCode,
            ':label_source' => $labelSource,
            ':nr_nadania'   => $nrNadania,
            ':courier_code' => $courierCode,
        ]);
    }

    public function updateOrderPackingStarted(string $orderCode): void
    {
        $st = $this->db->prepare("
            UPDATE pak_orders
            SET pack_started_at = NOW(), updated_at = NOW()
            WHERE order_code = :order_code
              AND pack_started_at IS NULL
        ");
        $st->execute([':order_code' => $orderCode]);
    }

    // -------------------------------------------------------------------------
    // PACKAGES
    // -------------------------------------------------------------------------

    public function findPackageBySession(int $sessionId): ?array
    {
        $st = $this->db->prepare("
            SELECT id, packing_session_id, package_no, provider_id,
                   service_code, package_size_code, tracking_number,
                   external_shipment_id, status
            FROM packages
            WHERE packing_session_id = :session_id
            ORDER BY package_no ASC
            LIMIT 1
        ");
        $st->execute([':session_id' => $sessionId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createPackage(
        int $sessionId,
        int $packageNo,
        ?int $providerId,
        ?string $serviceCode
    ): int {
        $st = $this->db->prepare("
            INSERT INTO packages
                (packing_session_id, package_no, provider_id, service_code,
                 status, created_at, updated_at)
            VALUES
                (:session_id, :package_no, :provider_id, :service_code,
                 'pending', NOW(), NOW())
        ");
        $st->execute([
            ':session_id'  => $sessionId,
            ':package_no'  => $packageNo,
            ':provider_id' => $providerId,
            ':service_code'=> $serviceCode,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updatePackageLabel(
        int $packageId,
        string $trackingNumber,
        ?string $externalShipmentId,
        string $status
    ): void {
        $st = $this->db->prepare("
            UPDATE packages
            SET tracking_number       = :tracking_number,
                external_shipment_id  = :external_shipment_id,
                status                = :status,
                updated_at            = NOW()
            WHERE id = :id
        ");
        $st->execute([
            ':tracking_number'      => $trackingNumber,
            ':external_shipment_id' => $externalShipmentId,
            ':status'               => $status,
            ':id'                   => $packageId,
        ]);
    }

    // -------------------------------------------------------------------------
    // PACKAGE LABELS
    // -------------------------------------------------------------------------

    public function findLabelByPackage(int $packageId): ?array
    {
        $st = $this->db->prepare("
            SELECT id, package_id, label_format, label_status,
                   file_path, file_token, raw_response_json, created_at
            FROM package_labels
            WHERE package_id = :package_id
              AND label_status = 'ok'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $st->execute([':package_id' => $packageId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createLabel(
        int $packageId,
        string $labelFormat,
        string $labelStatus,
        ?string $filePath,
        ?string $fileToken,
        ?string $rawResponseJson
    ): int {
        $st = $this->db->prepare("
            INSERT INTO package_labels
                (package_id, label_format, label_status,
                 file_path, file_token, raw_response_json, created_at)
            VALUES
                (:package_id, :label_format, :label_status,
                 :file_path, :file_token, :raw_response_json, NOW())
        ");
        $st->execute([
            ':package_id'        => $packageId,
            ':label_format'      => $labelFormat,
            ':label_status'      => $labelStatus,
            ':file_path'         => $filePath,
            ':file_token'        => $fileToken,
            ':raw_response_json' => $rawResponseJson,
        ]);
        return (int)$this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // SHIPPING PROVIDERS
    // -------------------------------------------------------------------------

    public function findProviderByCode(string $code): ?array
    {
        $st = $this->db->prepare("
            SELECT id, provider_code, provider_name, is_active, config_json
            FROM shipping_providers
            WHERE provider_code = :code
              AND is_active = 1
            LIMIT 1
        ");
        $st->execute([':code' => $code]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // -------------------------------------------------------------------------
    // EVENTS
    // -------------------------------------------------------------------------

    public function logEvent(
        int $sessionId,
        string $eventType,
        ?string $message = null,
        ?array $payload = null,
        ?int $userId = null
    ): void {
        $st = $this->db->prepare("
            INSERT INTO packing_events
                (packing_session_id, event_type, event_message, payload_json,
                 created_by_user_id, created_at)
            VALUES
                (:session_id, :event_type, :message, :payload,
                 :user_id, NOW())
        ");
        $st->execute([
            ':session_id' => $sessionId,
            ':event_type' => $eventType,
            ':message'    => $message,
            ':payload'    => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ':user_id'    => $userId,
        ]);
    }

    // -------------------------------------------------------------------------
    // TRANSACTIONS
    // -------------------------------------------------------------------------

    public function beginTransaction(): void { $this->db->beginTransaction(); }
    public function commit(): void           { $this->db->commit(); }
    public function rollback(): void         { if ($this->db->inTransaction()) $this->db->rollBack(); }

    // -------------------------------------------------------------------------
    // BATCH NAVIGATION
    // -------------------------------------------------------------------------

    public function findNextBatchOrder(int $batchId, string $currentOrderCode): ?array
    {
        $st = $this->db->prepare("
            SELECT pbo.order_code, pbo.id as batch_order_id
            FROM picking_batch_orders pbo
            WHERE pbo.batch_id    = :batch_id
              AND pbo.status      = 'picked'
              AND pbo.order_code != :current_order_code
              AND NOT EXISTS (
                  SELECT 1 FROM packing_sessions ps
                  WHERE ps.order_code = pbo.order_code
                    AND ps.status     = 'completed'
              )
            ORDER BY pbo.id ASC
            LIMIT 1
        ");
        $st->execute([
            ':batch_id'           => $batchId,
            ':current_order_code' => $currentOrderCode,
        ]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findBatchCarrierKey(int $batchId): ?string
    {
        $st = $this->db->prepare("
            SELECT carrier_key FROM picking_batches
            WHERE id = :batch_id
            LIMIT 1
        ");
        $st->execute([':batch_id' => $batchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)($row['carrier_key'] ?? '') : null;
    }
}

