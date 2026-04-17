ALTER TABLE pairing_tokens
    ADD INDEX idx_pairing_tokens_expires_at (expires_at),
    ADD INDEX idx_pairing_tokens_server_id (server_id),
    ADD CONSTRAINT fk_pairing_tokens_server
        FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL;

ALTER TABLE sites
    ADD INDEX idx_sites_status (status),
    ADD INDEX idx_sites_server_id (server_id),
    ADD INDEX idx_sites_last_heartbeat_at (last_heartbeat_at),
    ADD CONSTRAINT fk_sites_server
        FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE RESTRICT;

ALTER TABLE telemetry
    ADD INDEX idx_telemetry_site_received (site_id, received_at),
    ADD CONSTRAINT fk_telemetry_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE;

ALTER TABLE jobs
    ADD INDEX idx_jobs_queue (status, priority, scheduled_at, created_at),
    ADD INDEX idx_jobs_site_created (site_id, created_at),
    ADD INDEX idx_jobs_type (type),
    ADD CONSTRAINT fk_jobs_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE;

ALTER TABLE activity_log
    ADD INDEX idx_activity_log_site_created (site_id, created_at),
    ADD INDEX idx_activity_log_action_created (action, created_at),
    ADD CONSTRAINT fk_activity_log_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_activity_log_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL;
