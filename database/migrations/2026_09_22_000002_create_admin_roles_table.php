<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Roles hold a capability list. Apps declare their own capabilities in their
 * manifest, so granting someone the Director Scorecard without the
 * organisation-level index is a row edit, not a code change.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('admin_roles', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `slug` VARCHAR(64) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `description` VARCHAR(255) NULL,
            `capabilities` JSON NOT NULL COMMENT 'list of capability strings; see AppRegistry::capabilities()',
            `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'system roles cannot be deleted through the UI',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `admin_roles_slug` (`slug`)
        SQL);
    }

    public function down(): void
    {
        $this->drop('admin_roles');
    }
};
