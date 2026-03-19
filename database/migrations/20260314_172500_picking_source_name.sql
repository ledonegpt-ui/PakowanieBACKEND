ALTER TABLE picking_order_items
    ADD COLUMN source_name VARCHAR(255) NULL AFTER subiekt_desc;

ALTER TABLE picking_batch_items
    ADD COLUMN source_name VARCHAR(255) NULL AFTER subiekt_desc;
