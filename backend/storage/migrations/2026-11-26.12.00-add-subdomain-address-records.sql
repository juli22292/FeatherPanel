ALTER TABLE featherpanel_subdomain_manager_subdomains
    MODIFY COLUMN record_type ENUM('CNAME', 'SRV', 'A', 'AAAA') NOT NULL DEFAULT 'CNAME';
