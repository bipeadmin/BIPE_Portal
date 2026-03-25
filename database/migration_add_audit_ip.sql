ALTER TABLE audit_logs
    ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL AFTER user_identifier,
    ADD INDEX idx_audit_ip_time (ip_address, created_at);
