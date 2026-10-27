<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Boards live at platform level, not inside an app.
 *
 * This is the payoff of the multi-app design: when the same board is scored by
 * the Director Scorecard and by an organisation-level instrument, both point at
 * this row. Give each app its own copy and that join is gone — you would be
 * matching boards by typed name forever.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('organisations', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `match_key` VARCHAR(191) NOT NULL COMMENT 'lowercased, punctuation-stripped name used to dedupe typed board names',
            `sector` VARCHAR(100) NULL,
            `size_band` VARCHAR(50) NULL,
            `is_synthetic` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `organisations_match_key` (`match_key`),
            KEY `organisations_sector` (`sector`),
            KEY `organisations_is_synthetic` (`is_synthetic`)
        SQL);
    }

    public function down(): void
    {
        $this->drop('organisations');
    }
};
