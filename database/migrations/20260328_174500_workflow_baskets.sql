CREATE TABLE workflow_baskets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    basket_no INT UNSIGNED NOT NULL,
    package_mode ENUM('small','large') NOT NULL,
    status ENUM('empty','reserved','picked_ready','packing_in_progress') NOT NULL DEFAULT 'empty',
    reserved_batch_id BIGINT UNSIGNED NULL,
    reserved_by_user_id BIGINT UNSIGNED NULL,
    reserved_at DATETIME NULL,
    picked_ready_at DATETIME NULL,
    packing_started_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workflow_baskets_no_mode (basket_no, package_mode),
    KEY idx_workflow_baskets_status_mode (status, package_mode),
    KEY idx_workflow_baskets_batch (reserved_batch_id),
    KEY idx_workflow_baskets_user (reserved_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
