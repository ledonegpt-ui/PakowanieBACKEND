CREATE TABLE IF NOT EXISTS picking_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_code VARCHAR(64) NOT NULL,
    carrier_key VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    station_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    workflow_mode VARCHAR(32) NOT NULL DEFAULT 'integrated',
    target_orders_count INT NOT NULL DEFAULT 10,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    abandoned_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_picking_batches_code (batch_code),
    KEY idx_picking_batches_user_status (user_id, status),
    KEY idx_picking_batches_station_status (station_id, status),
    KEY idx_picking_batches_carrier_status (carrier_key, status),
    CONSTRAINT fk_picking_batches_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_picking_batches_station
        FOREIGN KEY (station_id) REFERENCES stations(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS picking_batch_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    order_code VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'assigned',
    drop_reason VARCHAR(255) DEFAULT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_picking_batch_order (batch_id, order_code),
    KEY idx_picking_batch_orders_order_code (order_code),
    KEY idx_picking_batch_orders_status (status),
    CONSTRAINT fk_picking_batch_orders_batch
        FOREIGN KEY (batch_id) REFERENCES picking_batches(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_picking_batch_orders_order
        FOREIGN KEY (order_code) REFERENCES pak_orders(order_code)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS picking_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_order_id BIGINT UNSIGNED NOT NULL,
    pak_order_item_id BIGINT UNSIGNED NOT NULL,
    product_code VARCHAR(128) DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    expected_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    picked_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    missing_reason VARCHAR(255) DEFAULT NULL,
    updated_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_picking_order_item (batch_order_id, pak_order_item_id),
    KEY idx_picking_order_items_status (status),
    KEY idx_picking_order_items_product_code (product_code),
    CONSTRAINT fk_picking_order_items_batch_order
        FOREIGN KEY (batch_order_id) REFERENCES picking_batch_orders(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_picking_order_items_pak_item
        FOREIGN KEY (pak_order_item_id) REFERENCES pak_order_items(item_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_picking_order_items_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS picking_batch_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    product_code VARCHAR(128) DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    total_expected_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    total_picked_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_picking_batch_items_batch (batch_id),
    KEY idx_picking_batch_items_status (status),
    KEY idx_picking_batch_items_product_code (product_code),
    CONSTRAINT fk_picking_batch_items_batch
        FOREIGN KEY (batch_id) REFERENCES picking_batches(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS picking_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    batch_order_id BIGINT UNSIGNED DEFAULT NULL,
    order_item_id BIGINT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(64) NOT NULL,
    event_message VARCHAR(255) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_picking_events_batch (batch_id),
    KEY idx_picking_events_type (event_type),
    CONSTRAINT fk_picking_events_batch
        FOREIGN KEY (batch_id) REFERENCES picking_batches(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_picking_events_batch_order
        FOREIGN KEY (batch_order_id) REFERENCES picking_batch_orders(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_picking_events_order_item
        FOREIGN KEY (order_item_id) REFERENCES picking_order_items(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_picking_events_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
