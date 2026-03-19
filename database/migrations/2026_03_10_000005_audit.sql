CREATE TABLE IF NOT EXISTS order_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_code VARCHAR(32) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    event_message VARCHAR(255) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_events_order (order_code),
    KEY idx_order_events_type (event_type),
    CONSTRAINT fk_order_events_order
        FOREIGN KEY (order_code) REFERENCES pak_orders(order_code)
        ON DELETE CASCADE,
    CONSTRAINT fk_order_events_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_request_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id CHAR(36) NOT NULL,
    method VARCHAR(16) NOT NULL,
    path VARCHAR(255) NOT NULL,
    http_status INT NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    station_id BIGINT UNSIGNED DEFAULT NULL,
    duration_ms INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_request_logs_request_id (request_id),
    KEY idx_api_request_logs_path (path),
    KEY idx_api_request_logs_created_at (created_at),
    CONSTRAINT fk_api_request_logs_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_api_request_logs_station
        FOREIGN KEY (station_id) REFERENCES stations(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_errors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_code VARCHAR(64) NOT NULL,
    reference_type VARCHAR(64) DEFAULT NULL,
    reference_id VARCHAR(128) DEFAULT NULL,
    error_code VARCHAR(64) DEFAULT NULL,
    error_message VARCHAR(255) NOT NULL,
    payload_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_workflow_errors_scope (scope_code),
    KEY idx_workflow_errors_reference (reference_type, reference_id),
    KEY idx_workflow_errors_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
