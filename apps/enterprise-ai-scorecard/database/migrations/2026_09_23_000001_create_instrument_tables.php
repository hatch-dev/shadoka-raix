<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * The Enterprise Scorecard instrument, versioned.
 *
 * Same decision as the Director Scorecard's, for the same reason: a submission
 * pins to an instrument row, published instruments are immutable, and editing
 * produces a new version. Without it, rewording one question silently rewrites
 * the meaning of every score already taken against it — and nobody finds out,
 * because the old records keep rendering perfectly.
 *
 * What is different from the sibling app's schema, and is the whole reason this
 * has its own tables rather than reusing ds_*:
 *
 *  - An item here is a QUESTION with its own two written-out ends. The Director
 *    Scorecard's items are statements marked against one ladder shared by every
 *    item, so a single `statement` column was enough. Here each of the thirty
 *    questions carries its own anchor wording for 1 and for 5, and that wording
 *    is what a respondent actually reads to choose a number.
 *  - Five rungs, banded on a 1.00–5.00 scale in five equal steps of 0.80.
 *  - No evidence prompts: nothing on this instrument asks for a citation.
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
            `scoring_rule` VARCHAR(50) NOT NULL DEFAULT 'mean' COMMENT 'level = band of the overall average; the floor is reported, not enforced',
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
            CONSTRAINT `es_rungs_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('dimensions', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instrument_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL,
            `code` CHAR(3) NOT NULL COMMENT 'PIP GOV EXE ARC CUL WRK',
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NOT NULL,
            `pressing_question` VARCHAR(255) NOT NULL COMMENT 'the question an executive should be able to press on this dimension',
            `summary_line` VARCHAR(255) NOT NULL COMMENT 'one line, shown in the hero list of what is measured',
            `failure_line` VARCHAR(255) NOT NULL COMMENT 'what failing this dimension looks like',
            `guide_what` TEXT NULL,
            `guide_why` TEXT NULL,
            `guide_how` TEXT NULL COMMENT 'contains one outbound link; rendered as trusted instrument HTML, never respondent input',
            `floor_narrative` TEXT NULL COMMENT 'shown when this dimension is the weakest; {floor} is substituted',
            PRIMARY KEY (`id`),
            UNIQUE KEY `dimensions_instrument_code` (`instrument_id`, `code`),
            KEY `dimensions_position` (`instrument_id`, `position`),
            CONSTRAINT `es_dimensions_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        /*
         * One row per question.
         *
         * `anchor_low_*` and `anchor_high_*` are what make this instrument
         * answerable: the number a respondent picks means nothing without the
         * two ends written out for that specific question. `label` is the short
         * name the analytics item breakdown lists them by.
         */
        $this->create('items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dimension_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL COMMENT '1-5 within its dimension',
            `label` VARCHAR(100) NOT NULL COMMENT 'short name, e.g. "Decision rights"',
            `question` TEXT NOT NULL,
            `anchor_low_label` VARCHAR(60) NOT NULL COMMENT 'the word printed under 1, e.g. "Rarely"',
            `anchor_low_text` VARCHAR(255) NOT NULL COMMENT 'what a 1 means on this question',
            `anchor_high_label` VARCHAR(60) NOT NULL COMMENT 'the word printed under 5',
            `anchor_high_text` VARCHAR(255) NOT NULL COMMENT 'what a 5 means on this question',
            PRIMARY KEY (`id`),
            UNIQUE KEY `items_dimension_position` (`dimension_id`, `position`),
            CONSTRAINT `es_items_dimension_fk`
                FOREIGN KEY (`dimension_id`) REFERENCES `{$this->table('dimensions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // Sector / size / role / tenure dropdowns. Editable without a deploy,
        // and versioned with the instrument they belong to.
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
            CONSTRAINT `es_reference_options_instrument_fk`
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
