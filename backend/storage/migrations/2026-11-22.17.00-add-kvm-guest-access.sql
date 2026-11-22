CREATE TABLE IF NOT EXISTS `featherpanel_vm_guest_passwords` (
    `instance_id` INT NOT NULL,
    `owner_uuid` VARCHAR(36) NOT NULL,
    `vmid` INT NOT NULL,
    `username` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `guest_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `guest_uid` INT UNSIGNED NOT NULL,
    `password_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `password_encrypted` TEXT NOT NULL,
    PRIMARY KEY (`instance_id`, `username`),
    CONSTRAINT `vm_guest_passwords_instance_fk` FOREIGN KEY (`instance_id`) REFERENCES `featherpanel_vm_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `featherpanel_vm_guest_setup` (
    `instance_id` INT NOT NULL,
    `owner_uuid` VARCHAR(36) NOT NULL,
    `vmid` INT NOT NULL,
    `username` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `retry_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`instance_id`),
    KEY `vm_guest_setup_retry` (`retry_at`),
    CONSTRAINT `vm_guest_setup_instance_fk` FOREIGN KEY (`instance_id`) REFERENCES `featherpanel_vm_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
