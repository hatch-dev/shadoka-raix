<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * The Human Edge Scorecard instrument, versioned.
 *
 * The same shape as the other scorecards' instrument tables, for the same
 * reason: a submission pins to an instrument row, published instruments are
 * immutable, and editing produces a new version. Without it, rewording one
 * question silently rewrites the meaning of every score already taken.
 *
 * What is particular to this instrument:
 *
 *  - Six skills, each with a sub-title ("Judgment · Critical Thinking"), which
 *    every screen prints beside the name.
 *  - Three questions per skill, each with its own written-out ends for 1 and 5
 *    and its own NEXT STEP: the advice the results page gives when that is one
 *    of the respondent's lowest-rated questions. The console's Practices pane
 *    counts how often each one is suggested, so it belongs to the item.
 *  - Five rungs (Rare to Habitual), banded on 1.00–5.00 in steps of 0.80.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('instruments', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `version` VARCHAR(20) NOT NULL COMMENT 'e.g. 1.0',
            `name` VARCHAR(191) NOT NULL,
            `subtitle` VARCHAR(191) NULL,
            `scale` VARCHAR(50) NOT NULL DEFAULT '5 levels',
            `scoring_rule` VARCHAR(50) NOT NULL DEFAULT 'mean' COMMENT 'level = band of the overall average of all answers',
            `rule_text` VARCHAR(255) NULL COMMENT 'plain-language rule, printed in exports and the codebook',
            `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            `published_at` DATETIME NULL,
            `notes` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `instruments_version` (`version`),
            KEY `instruments_status` (`status`)
        SQL);

        // Rungs carry their own band cut points, so a published version keeps
        // scoring the way it did even if config/scoring.php later changes.
        $this->create('rungs', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instrument_id` INT UNSIGNED NOT NULL,
            `value` TINYINT UNSIGNED NOT NULL COMMENT '1-5',
            `name` VARCHAR(50) NOT NULL,
            `description` VARCHAR(191) NOT NULL,
            `colour_hex` CHAR(7) NOT NULL,
            `band_min` DECIMAL(4,2) NULL COMMENT 'inclusive; null = open-ended below',
            `band_max` DECIMAL(4,2) NULL COMMENT 'exclusive; null = open-ended above',
            PRIMARY KEY (`id`),
            UNIQUE KEY `rungs_instrument_value` (`instrument_id`, `value`),
            CONSTRAINT `he_rungs_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('dimensions', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instrument_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL,
            `code` CHAR(3) NOT NULL COMMENT 'JDG ADP EMP CON IMG AUT',
            `name` VARCHAR(100) NOT NULL,
            `subtitle` VARCHAR(100) NOT NULL COMMENT 'printed beside the name, e.g. Critical Thinking',
            `description` TEXT NOT NULL,
            `pressing_question` VARCHAR(255) NOT NULL COMMENT 'the question to ask yourself on this skill',
            `summary_line` VARCHAR(255) NOT NULL COMMENT 'one line, shown in the home page list of what is measured',
            `failure_line` VARCHAR(255) NOT NULL COMMENT 'what weak practice on this skill looks like',
            `guide_what` TEXT NULL,
            `guide_why` TEXT NULL,
            `guide_how` TEXT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `dimensions_instrument_code` (`instrument_id`, `code`),
            KEY `dimensions_position` (`instrument_id`, `position`),
            CONSTRAINT `he_dimensions_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dimension_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL COMMENT '1-3 within its skill',
            `label` VARCHAR(100) NOT NULL COMMENT 'short name, e.g. "Checking for bias"',
            `question` TEXT NOT NULL,
            `anchor_low_label` VARCHAR(60) NOT NULL COMMENT 'the words printed under 1, e.g. "Rarely"',
            `anchor_low_text` VARCHAR(255) NOT NULL COMMENT 'what a 1 means on this question',
            `anchor_high_label` VARCHAR(60) NOT NULL COMMENT 'the words printed under 5',
            `anchor_high_text` VARCHAR(255) NOT NULL COMMENT 'what a 5 means on this question',
            `next_step` TEXT NOT NULL COMMENT 'the suggestion shown when this is one of the lowest-rated questions',
            PRIMARY KEY (`id`),
            UNIQUE KEY `items_dimension_position` (`dimension_id`, `position`),
            CONSTRAINT `he_items_dimension_fk`
                FOREIGN KEY (`dimension_id`) REFERENCES `{$this->table('dimensions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // Sector / size / role / tenure dropdowns, versioned with the instrument.
        $this->create('reference_options', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instrument_id` INT UNSIGNED NOT NULL,
            `group_key` VARCHAR(40) NOT NULL COMMENT 'sectors | organisation_sizes | roles | tenures',
            `position` SMALLINT UNSIGNED NOT NULL,
            `value` VARCHAR(191) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `reference_options_unique` (`instrument_id`, `group_key`, `value`),
            KEY `reference_options_group` (`instrument_id`, `group_key`, `position`),
            CONSTRAINT `he_reference_options_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('reference_options');
        $this->drop('items');
        $this->drop('dimensions');
        $this->drop('rungs');
        $this->drop('instruments');
    }
};
