<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Submissions and their raw ratings.
 *
 * Rules the schema enforces rather than trusts:
 *
 *  1. The client posts ratings only. Every score, level, floor and difference
 *     is derived server-side by Services\Scoring\Scorer and written here — the
 *     browser cannot assert its own score, which it could in the reference
 *     build, where the record it stored carried the scores it had computed.
 *  2. `is_synthetic` is a first-class column with its own index, so excluding
 *     demo data is a query-layer default rather than something each report
 *     re-implements and occasionally forgets.
 *  3. The respondent's identity is REQUIRED on this instrument and is stored on
 *     the submission, not merged into the organisation.
 *
 * What is particular to this instrument: one questionnaire produces TWO
 * profiles from the same respondent — their rating of themselves and their
 * rating of the organization's leaders — plus the difference between the two.
 * Each profile has its own overall mean, level and floor, so the summary
 * columns come in pairs (`self_*`, `leadership_*`), and every rating and every
 * dimension score row carries the `scope` it belongs to. The two profiles are
 * never averaged together: the reference keeps them separate everywhere, and a
 * blended number would describe nobody.
 *
 * The organisation itself lives in the platform's shared `organisations` table,
 * so the same company scored here and by another app is one row.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('submissions', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_token` CHAR(32) NOT NULL COMMENT 'lets a respondent reopen their own result; no account needed',
            `instrument_id` INT UNSIGNED NOT NULL COMMENT 'the version this was scored against, pinned forever',
            `organisation_id` INT UNSIGNED NULL COMMENT 'platform-level organisation record',

            `respondent_name` VARCHAR(120) NOT NULL COMMENT 'required; self-asserted and never verified',
            `respondent_email` VARCHAR(254) NOT NULL COMMENT 'required; format-checked only',
            `respondent_phone` VARCHAR(40) NOT NULL COMMENT 'required; 7-15 digits, format-checked only',
            `organisation_name` VARCHAR(191) NOT NULL COMMENT 'as typed; organisations holds the deduplicated row',
            `organisation_size` VARCHAR(50) NOT NULL COMMENT 'required; one of the instrument size bands',

            `role` VARCHAR(100) NULL,
            `tenure` VARCHAR(50) NULL,
            `sector` VARCHAR(100) NULL COMMENT 'as this respondent reported it; NULL = not specified',
            `assessment_year` SMALLINT UNSIGNED NOT NULL,

            `systems_inventory` SMALLINT UNSIGNED NULL COMMENT 'AI systems on an inventory; blank means no such list exists',
            `agenda_meetings` SMALLINT UNSIGNED NULL COMMENT 'executive meetings with AI as a DECISION item',
            `education_hours` DECIMAL(5,1) NULL,

            `consent_agreed_at` DATETIME NULL COMMENT 'when the respondent accepted the terms shown before the questionnaire',
            `consent_terms_url` VARCHAR(255) NULL COMMENT 'the terms they were shown',

            `status` ENUM('in_progress','complete') NOT NULL DEFAULT 'in_progress',

            `self_mean` DECIMAL(6,4) NULL COMMENT 'mean of the 12 self-ratings; sets self_level',
            `self_level` TINYINT UNSIGNED NULL,
            `self_floor` DECIMAL(6,4) NULL COMMENT 'lowest self dimension score; reported, not enforced',
            `self_floor_dimension_id` INT UNSIGNED NULL,
            `leadership_mean` DECIMAL(6,4) NULL COMMENT 'mean of the 12 ratings of the leaders',
            `leadership_level` TINYINT UNSIGNED NULL,
            `leadership_floor` DECIMAL(6,4) NULL,
            `leadership_floor_dimension_id` INT UNSIGNED NULL,
            `difference_mean` DECIMAL(6,4) NULL COMMENT 'self_mean minus leadership_mean, before rounding',

            `is_synthetic` TINYINT(1) NOT NULL DEFAULT 0,
            `archetype` VARCHAR(40) NULL COMMENT 'synthetic records only',
            `seed_run_id` INT UNSIGNED NULL,
            `ip_hash` CHAR(64) NULL COMMENT 'hashed, for rate limiting only',
            `submitted_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submissions_public_token` (`public_token`),
            KEY `submissions_instrument_id` (`instrument_id`),
            KEY `submissions_organisation_id` (`organisation_id`),
            KEY `submissions_is_synthetic` (`is_synthetic`),
            KEY `submissions_year` (`assessment_year`),
            KEY `submissions_status` (`status`),
            KEY `submissions_submitted_at` (`submitted_at`),
            KEY `submissions_email` (`respondent_email`),
            CONSTRAINT `exr_submissions_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `exr_submissions_organisation_fk`
                FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `exr_submissions_self_floor_fk`
                FOREIGN KEY (`self_floor_dimension_id`) REFERENCES `{$this->table('dimensions')}` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `exr_submissions_leadership_floor_fk`
                FOREIGN KEY (`leadership_floor_dimension_id`) REFERENCES `{$this->table('dimensions')}` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        /*
         * One row per rating: a question answered for a scope. Twenty-four rows
         * make a complete questionnaire. A rating not given simply has no row.
         */
        $this->create('submission_responses', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `scope` ENUM('self','leadership') NOT NULL,
            `mark` TINYINT UNSIGNED NOT NULL COMMENT '1-5',
            `answered_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_responses_unique` (`submission_id`, `item_id`, `scope`),
            KEY `submission_responses_item_id` (`item_id`),
            CONSTRAINT `exr_submission_responses_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `exr_submission_responses_item_fk`
                FOREIGN KEY (`item_id`) REFERENCES `{$this->table('items')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        SQL);

        // Derived, one row per dimension per scope. Rewritten by the Scorer,
        // never posted. Stored unrounded: the reference assigns levels and
        // computes differences before display rounding.
        $this->create('submission_scores', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `dimension_id` INT UNSIGNED NOT NULL,
            `scope` ENUM('self','leadership') NOT NULL,
            `score` DECIMAL(6,4) NOT NULL COMMENT 'mean of the dimension\'s three ratings in this scope',
            `rung_value` TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_scores_unique` (`submission_id`, `dimension_id`, `scope`),
            KEY `submission_scores_dimension_id` (`dimension_id`),
            CONSTRAINT `exr_submission_scores_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `exr_submission_scores_dimension_fk`
                FOREIGN KEY (`dimension_id`) REFERENCES `{$this->table('dimensions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('submission_scores');
        $this->drop('submission_responses');
        $this->drop('submissions');
    }
};
