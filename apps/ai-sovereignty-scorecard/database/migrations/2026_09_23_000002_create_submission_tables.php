<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Submissions and their raw marks.
 *
 * Rules the schema enforces rather than trusts:
 *
 *  1. The client posts marks only. mean_score, level_value, floor_score and
 *     floor_dimension_id are derived server-side by Services\Scoring\Scorer and
 *     written here — the browser cannot assert its own score, which it could in
 *     the reference build, where the record it stored included the level it had
 *     computed for itself.
 *  2. `is_synthetic` is a first-class column with its own index, so excluding
 *     demo data is a query-layer default rather than something each report
 *     re-implements and occasionally forgets.
 *  3. The respondent's identity is REQUIRED on this instrument and is stored on
 *     the submission, not merged into the organisation. Two executives of one
 *     organisation are two respondents with two scorecards, and the admin
 *     console's whole purpose is to be able to find and contact each of them.
 *
 * The organisation itself lives in the platform's shared `organisations` table,
 * so the same company scored here and by the Director Scorecard is one row.
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
            `sector` VARCHAR(100) NULL COMMENT 'as this respondent reported it; the organisation row has its own',
            `assessment_year` SMALLINT UNSIGNED NOT NULL,

            `systems_inventory` SMALLINT UNSIGNED NULL COMMENT 'AI systems on an inventory the respondent has seen; blank means no such list exists',
            `agenda_meetings` SMALLINT UNSIGNED NULL COMMENT 'executive meetings with AI as a DECISION item',
            `education_hours` DECIMAL(5,1) NULL,

            `consent_agreed_at` DATETIME NULL COMMENT 'when the respondent accepted the terms shown before the questionnaire',
            `consent_terms_url` VARCHAR(255) NULL COMMENT 'the terms they were shown, so a later revision cannot be read back onto an old consent',

            `status` ENUM('in_progress','complete') NOT NULL DEFAULT 'in_progress',

            `mean_score` DECIMAL(4,2) NULL COMMENT 'the overall average; this is what sets the level',
            `level_value` TINYINT UNSIGNED NULL COMMENT 'rung value derived from mean_score',
            `floor_score` DECIMAL(4,2) NULL COMMENT 'the weakest dimension score; reported, not enforced',
            `floor_dimension_id` INT UNSIGNED NULL,

            `is_synthetic` TINYINT(1) NOT NULL DEFAULT 0,
            `archetype` VARCHAR(40) NULL COMMENT 'synthetic records only',
            `seed_run_id` INT UNSIGNED NULL,
            `ip_hash` CHAR(64) NULL COMMENT 'hashed, for rate limiting only',
            `submitted_at` DATETIME NULL,
            `confirmed_at` DATETIME NULL COMMENT 'when the respondent explicitly accepted this scorecard on the results page',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submissions_public_token` (`public_token`),
            KEY `submissions_instrument_id` (`instrument_id`),
            KEY `submissions_organisation_id` (`organisation_id`),
            KEY `submissions_is_synthetic` (`is_synthetic`),
            KEY `submissions_year` (`assessment_year`),
            KEY `submissions_status` (`status`),
            KEY `submissions_floor_dimension_id` (`floor_dimension_id`),
            KEY `submissions_level_value` (`level_value`),
            KEY `submissions_submitted_at` (`submitted_at`),
            KEY `submissions_email` (`respondent_email`),
            CONSTRAINT `sov_submissions_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `sov_submissions_organisation_fk`
                FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `sov_submissions_floor_dimension_fk`
                FOREIGN KEY (`floor_dimension_id`) REFERENCES `{$this->table('dimensions')}` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // One row per question answered. A dimension left unscored simply has no
        // rows, and an unscored dimension is left out of the average rather than
        // counted as zero.
        $this->create('submission_responses', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `mark` TINYINT UNSIGNED NOT NULL COMMENT '1-5',
            `answered_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_responses_unique` (`submission_id`, `item_id`),
            KEY `submission_responses_item_id` (`item_id`),
            CONSTRAINT `sov_submission_responses_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `sov_submission_responses_item_fk`
                FOREIGN KEY (`item_id`) REFERENCES `{$this->table('items')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        SQL);

        // Derived, stored so analytics does not recompute sixteen marks per
        // record on every query. Rewritten by the Scorer, never posted.
        $this->create('submission_scores', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `dimension_id` INT UNSIGNED NOT NULL,
            `score` DECIMAL(4,2) NOT NULL COMMENT 'unweighted mean of the dimension\'s answered items',
            `rung_value` TINYINT UNSIGNED NOT NULL,
            `items_answered` TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_scores_unique` (`submission_id`, `dimension_id`),
            KEY `submission_scores_dimension_id` (`dimension_id`),
            CONSTRAINT `sov_submission_scores_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `sov_submission_scores_dimension_fk`
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
