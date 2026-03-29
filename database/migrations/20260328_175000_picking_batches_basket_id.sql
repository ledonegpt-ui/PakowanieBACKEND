ALTER TABLE picking_batches
    ADD COLUMN basket_id BIGINT UNSIGNED NULL AFTER workflow_mode,
    ADD KEY idx_picking_batches_basket_id (basket_id);
