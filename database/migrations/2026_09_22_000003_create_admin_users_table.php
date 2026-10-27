<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Replaces the client-side PIN.
 *
 * Only staff have accounts — directors submit anonymously and reopen their own
 * result with a token on the submission row, so no personal login exists to be
 * breached.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('admin_users', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `role_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `email` VARCHAR(191) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
            `failed_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `locked_until` DATETIME NULL,
            `last_login_at` DATETIME NULL,
            `last_login_ip_hash` CHAR(64) NULL COMMENT 'hashed, never the raw address',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `admin_users_email` (`email`),
            KEY `admin_users_role_id` (`role_id`),
            CONSTRAINT `admin_users_role_fk`
                FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('admin_users');
    }
};
