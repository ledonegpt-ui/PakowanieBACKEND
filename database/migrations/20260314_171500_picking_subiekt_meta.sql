ALTER TABLE picking_order_items
    ADD COLUMN subiekt_symbol VARCHAR(128) NULL AFTER subiekt_tow_id,
    ADD COLUMN subiekt_desc TEXT NULL AFTER subiekt_symbol;

ALTER TABLE picking_batch_items
    ADD COLUMN subiekt_symbol VARCHAR(128) NULL AFTER subiekt_tow_id,
    ADD COLUMN subiekt_desc TEXT NULL AFTER subiekt_symbol;
