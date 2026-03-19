CREATE TABLE IF NOT EXISTS shipping_providers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_code VARCHAR(64) NOT NULL,
    provider_name VARCHAR(128) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    config_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shipping_providers_code (provider_code),
    KEY idx_shipping_providers_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipping_rule_sets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    set_code VARCHAR(64) NOT NULL,
    set_name VARCHAR(128) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shipping_rule_sets_code (set_code),
    KEY idx_shipping_rule_sets_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipping_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_set_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    rule_name VARCHAR(128) NOT NULL,
    source_name VARCHAR(64) DEFAULT NULL,
    match_type VARCHAR(32) NOT NULL,
    match_value VARCHAR(255) NOT NULL,
    service_code VARCHAR(64) DEFAULT NULL,
    requires_size TINYINT(1) NOT NULL DEFAULT 0,
    priority INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    extra_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_shipping_rules_set_active (rule_set_id, is_active),
    KEY idx_shipping_rules_provider (provider_id),
    KEY idx_shipping_rules_priority (priority),
    CONSTRAINT fk_shipping_rules_rule_set
        FOREIGN KEY (rule_set_id) REFERENCES shipping_rule_sets(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_shipping_rules_provider
        FOREIGN KEY (provider_id) REFERENCES shipping_providers(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
