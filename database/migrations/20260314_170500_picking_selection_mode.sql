ALTER TABLE picking_batches
    ADD COLUMN selection_mode VARCHAR(32) NOT NULL DEFAULT 'cutoff' AFTER workflow_mode;

ALTER TABLE picking_batches
    ADD KEY idx_picking_batches_selection_mode (selection_mode);
