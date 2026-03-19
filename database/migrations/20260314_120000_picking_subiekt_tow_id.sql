ALTER TABLE picking_order_items
    ADD COLUMN subiekt_tow_id BIGINT UNSIGNED NULL AFTER pak_order_item_id,
    ADD COLUMN uom VARCHAR(32) NULL AFTER product_name,
    ADD COLUMN is_unmapped TINYINT(1) NOT NULL DEFAULT 0 AFTER uom;

ALTER TABLE picking_order_items
    ADD KEY idx_picking_order_items_subiekt_tow_id (subiekt_tow_id),
    ADD KEY idx_picking_order_items_subiekt_uom (subiekt_tow_id, uom);

ALTER TABLE picking_batch_items
    ADD COLUMN subiekt_tow_id BIGINT UNSIGNED NULL AFTER batch_id,
    ADD COLUMN uom VARCHAR(32) NULL AFTER product_name,
    ADD COLUMN is_unmapped TINYINT(1) NOT NULL DEFAULT 0 AFTER uom,
    ADD COLUMN total_missing_qty DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER total_picked_qty,
    ADD COLUMN remaining_qty DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER total_missing_qty,
    ADD COLUMN qty_breakdown_json JSON DEFAULT NULL AFTER remaining_qty,
    ADD COLUMN qty_breakdown_label TEXT DEFAULT NULL AFTER qty_breakdown_json,
    ADD COLUMN order_breakdown_json JSON DEFAULT NULL AFTER qty_breakdown_label;

ALTER TABLE picking_batch_items
    ADD KEY idx_picking_batch_items_subiekt_tow_id (subiekt_tow_id),
    ADD KEY idx_picking_batch_items_batch_subiekt_uom (batch_id, subiekt_tow_id, uom);
