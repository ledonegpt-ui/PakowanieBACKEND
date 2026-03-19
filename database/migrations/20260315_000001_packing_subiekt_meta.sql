ALTER TABLE packing_session_items
    ADD COLUMN subiekt_tow_id BIGINT UNSIGNED NULL AFTER pak_order_item_id,
    ADD COLUMN subiekt_symbol VARCHAR(128) NULL AFTER subiekt_tow_id,
    ADD COLUMN subiekt_desc TEXT NULL AFTER subiekt_symbol,
    ADD COLUMN source_name VARCHAR(255) NULL AFTER subiekt_desc,
    ADD COLUMN uom VARCHAR(32) NULL AFTER product_name,
    ADD COLUMN is_unmapped TINYINT(1) NOT NULL DEFAULT 0 AFTER uom;

ALTER TABLE packing_session_items
    ADD KEY idx_packing_session_items_subiekt_tow_id (subiekt_tow_id),
    ADD KEY idx_packing_session_items_session_subiekt (packing_session_id, subiekt_tow_id);
