ALTER TABLE stations
    ADD COLUMN package_mode_default ENUM('small','large') NOT NULL DEFAULT 'small' AFTER printer_name;

UPDATE stations
SET package_mode_default = CASE
    WHEN station_code IN ('7','8','9') THEN 'large'
    ELSE 'small'
END;

ALTER TABLE user_station_sessions
    ADD COLUMN package_mode ENUM('small','large') NOT NULL DEFAULT 'small' AFTER workflow_mode;

UPDATE user_station_sessions uss
INNER JOIN stations s ON s.id = uss.station_id
SET uss.package_mode = s.package_mode_default
WHERE uss.package_mode IS NOT NULL;

ALTER TABLE picking_batches
    ADD COLUMN package_mode ENUM('small','large') NOT NULL DEFAULT 'small' AFTER carrier_key;

UPDATE picking_batches pb
INNER JOIN stations s ON s.id = pb.station_id
SET pb.package_mode = COALESCE(
    (
        SELECT uss.package_mode
        FROM user_station_sessions uss
        WHERE uss.user_id = pb.user_id
          AND uss.station_id = pb.station_id
          AND uss.started_at <= pb.started_at
        ORDER BY uss.started_at DESC, uss.id DESC
        LIMIT 1
    ),
    s.package_mode_default,
    'small'
);

ALTER TABLE picking_batches
    ADD KEY idx_picking_batches_carrier_package_status (carrier_key, package_mode, status);

ALTER TABLE user_station_sessions
    ADD KEY idx_user_station_sessions_token_active (session_token, is_active);

ALTER TABLE stations
    ADD KEY idx_stations_package_mode_default (package_mode_default);
