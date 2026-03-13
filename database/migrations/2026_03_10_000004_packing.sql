CREATE TABLE IF NOT EXISTS packing_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_code VARCHAR(64) NOT NULL,
    order_code VARCHAR(32) NOT NULL,
    picking_batch_id BIGINT UNSIGNED DEFAULT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    station_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_packing_sessions_code (session_code),
    KEY idx_packing_sessions_order_status (order_code, status),
    KEY idx_packing_sessions_user_status (user_id, status),
    CONSTRAINT fk_packing_sessions_order
        FOREIGN KEY (order_code) REFERENCES pak_orders(order_code)
        ON DELETE CASCADE,
    CONSTRAINT fk_packing_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_packing_sessions_station
        FOREIGN KEY (station_id) REFERENCES stations(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_packing_sessions_batch
        FOREIGN KEY (picking_batch_id) REFERENCES picking_batches(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packing_session_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    packing_session_id BIGINT UNSIGNED NOT NULL,
    pak_order_item_id BIGINT UNSIGNED NOT NULL,
    product_code VARCHAR(128) DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    expected_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    packed_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_packing_session_item (packing_session_id, pak_order_item_id),
    KEY idx_packing_session_items_product_code (product_code),
    CONSTRAINT fk_packing_session_items_session
        FOREIGN KEY (packing_session_id) REFERENCES packing_sessions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_packing_session_items_pak_item
        FOREIGN KEY (pak_order_item_id) REFERENCES pak_order_items(item_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    packing_session_id BIGINT UNSIGNED NOT NULL,
    package_no INT NOT NULL DEFAULT 1,
    provider_id BIGINT UNSIGNED DEFAULT NULL,
    service_code VARCHAR(64) DEFAULT NULL,
    package_size_code VARCHAR(32) DEFAULT NULL,
    tracking_number VARCHAR(128) DEFAULT NULL,
    external_shipment_id VARCHAR(128) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'not_requested',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_packages_session (packing_session_id),
    KEY idx_packages_tracking_number (tracking_number),
    CONSTRAINT fk_packages_session
        FOREIGN KEY (packing_session_id) REFERENCES packing_sessions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_packages_provider
        FOREIGN KEY (provider_id) REFERENCES shipping_providers(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_labels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_id BIGINT UNSIGNED NOT NULL,
    label_format VARCHAR(32) NOT NULL DEFAULT 'pdf',
    label_status VARCHAR(32) NOT NULL DEFAULT 'generated',
    file_path VARCHAR(255) DEFAULT NULL,
    file_token VARCHAR(128) DEFAULT NULL,
    raw_response_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_package_labels_package (package_id),
    CONSTRAINT fk_package_labels_package
        FOREIGN KEY (package_id) REFERENCES packages(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packing_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    packing_session_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    event_message VARCHAR(255) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_packing_events_session (packing_session_id),
    KEY idx_packing_events_type (event_type),
    CONSTRAINT fk_packing_events_session
        FOREIGN KEY (packing_session_id) REFERENCES packing_sessions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_packing_events_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
