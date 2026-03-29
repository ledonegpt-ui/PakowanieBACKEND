ALTER TABLE user_station_sessions
    ADD COLUMN work_mode VARCHAR(16) NOT NULL DEFAULT 'picker'
    AFTER workflow_mode;
