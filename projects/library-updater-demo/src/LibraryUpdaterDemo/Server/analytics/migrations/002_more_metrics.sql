ALTER TABLE demo_update_events
    ADD COLUMN status VARCHAR(32) NULL AFTER operation,
    ADD COLUMN error_message TEXT NULL AFTER status,
    ADD COLUMN metadata_json JSON NULL AFTER download_url,
    ADD INDEX idx_operation_status (operation, status),
    ADD INDEX idx_app_version (app_version),
    ADD INDEX idx_ip_address (ip_address);
