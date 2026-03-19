SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'picking_batch_orders'
              AND COLUMN_NAME = 'pak_order_id'
        ),
        'ALTER TABLE picking_batch_orders DROP COLUMN pak_order_id',
        'SELECT "skip picking_batch_orders.drop_pak_order_id"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'picking_batch_orders'
              AND INDEX_NAME = 'idx_picking_batch_orders_order_code'
        ),
        'SELECT "skip picking_batch_orders.add_idx_order_code"',
        'ALTER TABLE picking_batch_orders ADD KEY idx_picking_batch_orders_order_code (order_code)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'packing_sessions'
              AND COLUMN_NAME = 'pak_order_id'
        ),
        'ALTER TABLE packing_sessions CHANGE pak_order_id order_code VARCHAR(32) NOT NULL',
        'SELECT "skip packing_sessions.change_order_code"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_events'
              AND COLUMN_NAME = 'pak_order_id'
        ),
        'ALTER TABLE order_events CHANGE pak_order_id order_code VARCHAR(32) NOT NULL',
        'SELECT "skip order_events.change_order_code"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
