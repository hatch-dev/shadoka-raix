<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * The instrument, versioned.
 *
 * This is the decision that is expensive to retrofit. The legacy single-file
 * build hard-coded the competencies in JavaScript and carried a LEVEL_ALIAS map
 * to patch records written before the rungs were renamed. Once an admin can
 * edit an item through a UI, an unversioned schema silently rewrites the
 * meaning of every score ever taken.
 *
 * So: a submission pins to an instrument row, published instruments are
 * immutable, and editing produces a new draft version.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('instruments', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `version` VARCHAR(20) NOT NULL COMMENT 'e.g. 1.0',
            `name` VARCHAR(191) NOT NULL,
            `subtitle` VARCHAR(191) NULL,
            `scale` VARCHAR(50) NOT NULL DEFAULT '4 levels',
            `scoring_rule` VARCHAR(50) NOT NULL DEFAULT 'floor',
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
            `value` TINYINT UNSIGNED NOT NULL COMMENT '1-4',
            `name` VARCHAR(50) NOT NULL,
            `description` VARCHAR(191) NOT NULL,
            `colour_hex` CHAR(7) NOT NULL,
            `band_min` DECIMAL(4,2) NULL COMMENT 'inclusive; null = open-ended below',
            `band_max` DECIMAL(4,2) NULL COMMENT 'exclusive; null = open-ended above',
            PRIMARY KEY (`id`),
            UNIQUE KEY `rungs_instrument_value` (`instrument_id`, `value`),
            CONSTRAINT `rungs_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('competencies', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instrument_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL,
            `code` CHAR(3) NOT NULL COMMENT 'GOV DAT EXP FAI RIS HUM AUT',
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NOT NULL,
            `board_question` VARCHAR(255) NOT NULL COMMENT 'the question a board should be able to press',
            `failure_line` VARCHAR(255) NOT NULL COMMENT 'what failing this competency looks like',
            `guide_what` TEXT NULL,
            `guide_why` TEXT NULL,
            `guide_how` TEXT NULL,
            `guide_example` TEXT NULL,
            `floor_narrative` TEXT NULL COMMENT 'shown when this competency sets the level; {floor} is substituted',
            PRIMARY KEY (`id`),
            UNIQUE KEY `competencies_instrument_code` (`instrument_id`, `code`),
            KEY `competencies_position` (`instrument_id`, `position`),
            CONSTRAINT `competencies_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `competency_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL COMMENT '1-5 within its competency',
            `statement` TEXT NOT NULL COMMENT 'something a director does, marked against their own conduct',
            PRIMARY KEY (`id`),
            UNIQUE KEY `items_competency_position` (`competency_id`, `position`),
            CONSTRAINT `items_competency_fk`
                FOREIGN KEY (`competency_id`) REFERENCES `{$this->table('competencies')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // Placeholder phrases offered as examples of "when, and where?".
        $this->create('evidence_prompts', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `competency_id` INT UNSIGNED NOT NULL,
            `position` TINYINT UNSIGNED NOT NULL,
            `template` VARCHAR(191) NOT NULL COMMENT '{q} = quarter, {n} = minute number',
            PRIMARY KEY (`id`),
            UNIQUE KEY `evidence_prompts_competency_position` (`competency_id`, `position`),
            CONSTRAINT `evidence_prompts_competency_fk`
                FOREIGN KEY (`competency_id`) REFERENCES `{$this->table('competencies')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // Sector / size / role / tenure dropdowns. Editable without a deploy,
        // and versioned with the instrument they belong to.
        $this->create('reference_options', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instrument_id` INT UNSIGNED NOT NULL,
            `group_key` VARCHAR(40) NOT NULL COMMENT 'sectors | company_sizes | roles | tenures',
            `position` SMALLINT UNSIGNED NOT NULL,
            `value` VARCHAR(191) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `reference_options_unique` (`instrument_id`, `group_key`, `value`),
            KEY `reference_options_group` (`instrument_id`, `group_key`, `position`),
            CONSTRAINT `reference_options_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('reference_options');
        $this->drop('evidence_prompts');
        $this->drop('items');
        $this->drop('competencies');
        $this->drop('rungs');
        $this->drop('instruments');
    }
};
