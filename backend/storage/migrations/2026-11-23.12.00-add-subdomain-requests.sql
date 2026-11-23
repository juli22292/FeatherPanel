CREATE TABLE IF NOT EXISTS featherpanel_subdomain_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    server_id INT NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    requester_uuid VARCHAR(36) NOT NULL,
    reviewer_uuid VARCHAR(36) NULL,
    subdomain VARCHAR(63) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reason VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    pending_label VARCHAR(63) GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN subdomain ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY fp_subdomain_request_pending_label (domain_id, pending_label),
    KEY fp_subdomain_request_server_status (server_id, status),
    KEY fp_subdomain_request_status_id (status, id),
    CONSTRAINT fp_subdomain_request_server_fk FOREIGN KEY (server_id) REFERENCES featherpanel_servers (id) ON DELETE CASCADE,
    CONSTRAINT fp_subdomain_request_domain_fk FOREIGN KEY (domain_id) REFERENCES featherpanel_subdomain_manager_domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
