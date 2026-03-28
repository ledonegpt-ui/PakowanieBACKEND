CREATE TABLE IF NOT EXISTS order_backlog_holds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_code VARCHAR(32) NOT NULL,
    pak_order_item_id BIGINT UNSIGNED NOT NULL,
    subiekt_tow_id BIGINT UNSIGNED DEFAULT NULL,
    product_code VARCHAR(128) DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    missing_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    hold_type VARCHAR(32) NOT NULL DEFAULT 'other',
    hold_reason VARCHAR(255) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    resolved_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_backlog_holds_order_status (order_code, status),
    KEY idx_order_backlog_holds_item_status (pak_order_item_id, status),
    KEY idx_order_backlog_holds_tow_status (subiekt_tow_id, status),
    KEY idx_order_backlog_holds_product_status (product_code, status),
    KEY idx_order_backlog_holds_status (status),
    CONSTRAINT fk_order_backlog_holds_order
        FOREIGN KEY (order_code) REFERENCES pak_orders(order_code)
        ON DELETE CASCADE,
    CONSTRAINT fk_order_backlog_holds_pak_item
        FOREIGN KEY (pak_order_item_id) REFERENCES pak_order_items(item_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_order_backlog_holds_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_order_backlog_holds_resolved_by
        FOREIGN KEY (resolved_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
