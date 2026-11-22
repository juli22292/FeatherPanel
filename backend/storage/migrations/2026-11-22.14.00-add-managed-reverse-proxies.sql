ALTER TABLE featherpanel_allocations
    ADD UNIQUE INDEX IF NOT EXISTS managed_proxy_allocation_owner (id, server_id, ip, port);

CREATE TABLE IF NOT EXISTS featherpanel_managed_proxies (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    allocation_id INT NOT NULL,
    domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
    ip VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    port MEDIUMINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT managed_proxy_server FOREIGN KEY (server_id) REFERENCES featherpanel_servers (id),
    CONSTRAINT managed_proxy_allocation FOREIGN KEY (allocation_id, server_id, ip, port) REFERENCES featherpanel_allocations (id, server_id, ip, port)
) ENGINE=InnoDB;
