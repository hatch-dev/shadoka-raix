<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * The AI Executive Readiness Scorecard instrument, versioned.
 *
 * Same decision as the sibling apps', for the same reason: a submission pins to
 * an instrument row, published instruments are immutable, and editing produces
 * a new version. Without it, rewording one question silently rewrites the
 * meaning of every score already taken against it.
 *
 * What is different from the sibling schemas, and is the whole reason this app
 * has its own tables:
 *
 *  - Every question is rated TWICE — once by the respondent about themselves
 *    ("self") and once about their organization's leaders collectively
 *    ("leadership"). The two short end labels printed beside the scale are
 *    shared, but each rating has its OWN written meaning for a 1 and a 5
 *    ("You struggle to name…" against "Leaders struggle to name…"). Those four
 *    texts are what a respondent actually reads to choose a number, so each
 *    has a column rather than being derived from the other.
 *  - Dimensions carry no "question to press" and no failure line: this
 *    instrument's dimensions are a name, a description, a one-line summary and
 *    the three guide panels.
 *  - Five rungs, banded on a 1.00–5.00 scale in five equal steps of 0.80.
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
            `scoring_rule` VARCHAR(50) NOT NULL DEFAULT 'paired-mean' COMMENT 'each scope: level = band of its overall mean',
            `rule_text` VARCHAR(255) NULL COMMENT 'plain-language rule, printed in exports and the codebook',
            `instructions` TEXT NULL COMMENT 'how to answer: rate yourself, then the leaders',
            `comparison_note` TEXT NULL COMMENT 'how to read the self-minus-leadership difference',
            `disclaimer` TEXT NULL COMMENT 'the self-assessment disclaimer printed on every result',
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
            CONSTRAINT `exr_rungs_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('dimensions', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instrument_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL,
            `code` CHAR(3) NOT NULL COMMENT 'JDG FLU STR CHG',
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NOT NULL,
            `summary_line` VARCHAR(255) NOT NULL COMMENT 'one line, shown in the hero list of what is measured',
            `guide_what` TEXT NULL,
            `guide_why` TEXT NULL,
            `guide_how` TEXT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `dimensions_instrument_code` (`instrument_id`, `code`),
            KEY `dimensions_position` (`instrument_id`, `position`),
            CONSTRAINT `exr_dimensions_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        /*
         * One row per question. It is answered twice (see submission_responses),
         * so it carries both ratings' written ends.
         */
        $this->create('items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dimension_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL COMMENT '1-3 within its dimension',
            `label` VARCHAR(100) NOT NULL COMMENT 'short name, e.g. "Business problems"',
            `question` TEXT NOT NULL COMMENT 'written once for both ratings: "you / your organization’s leaders"',
            `anchor_low_label` VARCHAR(80) NOT NULL COMMENT 'the words printed beside 1, shared by both ratings',
            `anchor_high_label` VARCHAR(80) NOT NULL COMMENT 'the words printed beside 5, shared by both ratings',
            `self_low_text` VARCHAR(400) NOT NULL COMMENT 'what a 1 means when rating yourself',
            `self_high_text` VARCHAR(400) NOT NULL COMMENT 'what a 5 means when rating yourself',
            `leadership_low_text` VARCHAR(400) NOT NULL COMMENT 'what a 1 means when rating the leaders',
            `leadership_high_text` VARCHAR(400) NOT NULL COMMENT 'what a 5 means when rating the leaders',
            PRIMARY KEY (`id`),
            UNIQUE KEY `items_dimension_position` (`dimension_id`, `position`),
            CONSTRAINT `exr_items_dimension_fk`
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
            CONSTRAINT `exr_reference_options_instrument_fk`
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
