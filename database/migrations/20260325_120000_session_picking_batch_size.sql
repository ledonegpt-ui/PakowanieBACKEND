ALTER TABLE user_station_sessions
  ADD COLUMN picking_batch_size INT UNSIGNED NOT NULL DEFAULT 2 AFTER package_mode;

ALTER TABLE user_station_sessions
  ADD KEY idx_user_station_sessions_token_active_batch (session_token, is_active, picking_batch_size);
