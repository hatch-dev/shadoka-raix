<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Who changed what, across every app.
 *
 * Research data that anyone can quietly edit is not research data. Every admin
 * write — deleting a submission, editing the instrument, running the seeder,
 * exporting the dataset — lands here with the app it belongs to.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('audit_log', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `admin_user_id` INT UNSIGNED NULL COMMENT 'null for system/CLI actions',
            `actor_label` VARCHAR(120) NOT NULL COMMENT 'name captured at write time, so the log survives a user being deleted',
            `app` VARCHAR(64) NULL COMMENT 'app slug, or null for platform-level actions',
            `action` VARCHAR(100) NOT NULL,
            `subject_type` VARCHAR(100) NULL,
            `subject_id` VARCHAR(64) NULL,
            `meta` JSON NULL,
            `ip_hash` CHAR(64) NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `audit_log_admin_user_id` (`admin_user_id`),
            KEY `audit_log_app_action` (`app`, `action`),
            KEY `audit_log_subject` (`subject_type`, `subject_id`),
            KEY `audit_log_created_at` (`created_at`),
            CONSTRAINT `audit_log_admin_user_fk`
                FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('audit_log');
    }
};
