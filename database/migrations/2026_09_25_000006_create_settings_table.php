<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Platform settings an administrator changes from the console.
 *
 * Deliberately small: configuration a deploy decides stays in config/ and
 * .env. This table holds only what an owner must be able to change without
 * touching the server — the first of which is the shared Admin PIN, stored as
 * a hash under `auth.pin_hash`.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('settings', <<<SQL
            `key` VARCHAR(100) NOT NULL,
            `value` TEXT NULL,
            `updated_by` BIGINT UNSIGNED NULL COMMENT 'admin_users.id, null when set from the CLI',
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`key`)
        SQL);
    }

    public function down(): void
    {
        $this->drop('settings');
    }
};
