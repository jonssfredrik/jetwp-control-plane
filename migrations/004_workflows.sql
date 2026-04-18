CREATE TABLE IF NOT EXISTS workflows (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_nodes (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    node_key VARCHAR(64) NOT NULL,
    type VARCHAR(60) NOT NULL,
    label VARCHAR(120) NOT NULL,
    config_json JSON DEFAULT NULL,
    position_x INT NOT NULL DEFAULT 0,
    position_y INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workflow_node_key (workflow_id, node_key),
    CONSTRAINT fk_workflow_nodes_workflow
        FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_edges (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    from_node_key VARCHAR(64) NOT NULL,
    to_node_key VARCHAR(64) NOT NULL,
    edge_type ENUM('next','true','false') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workflow_edge_unique (workflow_id, from_node_key, edge_type),
    CONSTRAINT fk_workflow_edges_workflow
        FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_runs (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    site_id CHAR(36) NOT NULL,
    status ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    current_node_key VARCHAR(64) DEFAULT NULL,
    context_json JSON DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_workflow_runs_workflow
        FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_run_steps (
    id CHAR(36) PRIMARY KEY,
    workflow_run_id CHAR(36) NOT NULL,
    node_key VARCHAR(64) NOT NULL,
    node_type VARCHAR(60) NOT NULL,
    status ENUM('running','completed','failed','skipped') NOT NULL,
    job_id CHAR(36) DEFAULT NULL,
    input_json JSON DEFAULT NULL,
    output_json JSON DEFAULT NULL,
    error_output TEXT DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_workflow_run_steps_run
        FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE workflows
    ADD INDEX idx_workflows_status (status),
    ADD INDEX idx_workflows_created_by (created_by),
    ADD CONSTRAINT fk_workflows_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE workflow_nodes
    ADD INDEX idx_workflow_nodes_workflow (workflow_id),
    ADD INDEX idx_workflow_nodes_type (type);

ALTER TABLE workflow_edges
    ADD INDEX idx_workflow_edges_workflow (workflow_id),
    ADD INDEX idx_workflow_edges_from (workflow_id, from_node_key);

ALTER TABLE workflow_runs
    ADD INDEX idx_workflow_runs_workflow_created (workflow_id, created_at),
    ADD INDEX idx_workflow_runs_site_created (site_id, created_at),
    ADD INDEX idx_workflow_runs_status (status);

ALTER TABLE workflow_run_steps
    ADD INDEX idx_workflow_run_steps_run_created (workflow_run_id, created_at),
    ADD INDEX idx_workflow_run_steps_node (workflow_run_id, node_key);
