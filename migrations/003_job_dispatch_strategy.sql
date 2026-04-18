ALTER TABLE jobs
    ADD COLUMN execution_strategy ENUM('agent_only','agent_preferred','ssh_only') NOT NULL DEFAULT 'ssh_only' AFTER created_by,
    ADD COLUMN runner_type ENUM('agent','ssh') DEFAULT NULL AFTER execution_strategy,
    ADD COLUMN claimed_at DATETIME DEFAULT NULL AFTER runner_type,
    ADD COLUMN dispatch_reason VARCHAR(100) DEFAULT NULL AFTER claimed_at;
