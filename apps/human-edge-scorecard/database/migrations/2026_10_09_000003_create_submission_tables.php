<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * The admin console's relational copy of each scorecard.
 *
 * he_records holds every scorecard exactly as the page sent it, and stays the
 * source of truth. Services/RecordMirror writes one row here per record
 * (tagged by `record_id`), its raw marks, and the scores the console's Scorer
 * derives from them — the same family of tables every other RAIX scorecard
 * keeps, so the console's screens and exports read relational rows rather than
 * re-parsing JSON.
 *
 *  1. Marks are copied; mean_score, level_value, floor_score and
 *     floor_dimension_id are derived server-side by Services\Scoring\Scorer.
 *  2. `is_synthetic` is a first-class column, so excluding demo data is a
 *     query-layer default.
 *  3. The respondent's identity is stored on the submission, not merged into
 *     the organisation: two people from one organisation are two respondents.
 *     The organisation name is optional on this page, so it may be NULL.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('submissions', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_token` CHAR(32) NOT NULL COMMENT 'random; the page addresses scorecards by record_id',
            `record_id` VARCHAR(64) NULL COMMENT 'the he_records id this row mirrors',
            `instrument_id` INT UNSIGNED NOT NULL COMMENT 'the version this was scored against, pinned forever',
            `organisation_id` INT UNSIGNED NULL COMMENT 'platform-level organisation record; NULL when no name was given',

            `respondent_name` VARCHAR(120) NOT NULL COMMENT 'self-asserted and never verified',
            `respondent_email` VARCHAR(254) NOT NULL COMMENT 'format-checked only',
            `respondent_phone` VARCHAR(40) NULL,
            `organisation_name` VARCHAR(191) NULL COMMENT 'as typed; optional on this page',
            `organisation_size` VARCHAR(50) NULL COMMENT 'one of the instrument size bands',

            `role` VARCHAR(100) NULL,
            `tenure` VARCHAR(50) NULL COMMENT 'years in role',
            `sector` VARCHAR(100) NULL COMMENT 'as this respondent reported it; the organisation row has its own',
            `assessment_year` SMALLINT UNSIGNED NOT NULL,

            `ai_hours_per_week` DECIMAL(5,1) NULL COMMENT 'hours a week using AI tools',
            `education_hours` DECIMAL(5,1) NULL COMMENT 'hours of AI education in the last 12 months',

            `consent_notice` VARCHAR(40) NULL COMMENT 'the privacy notice version the respondent was shown',
            `consent_agreed_at` DATETIME NULL,
            `consent_terms_url` VARCHAR(255) NULL,
            `consent_research` TINYINT(1) NULL COMMENT 'research use agreed; required for a real record to be stored at all',

            `status` ENUM('in_progress','complete') NOT NULL DEFAULT 'complete',

            `mean_score` DECIMAL(4,2) NULL COMMENT 'the overall average; this is what sets the level',
            `level_value` TINYINT UNSIGNED NULL COMMENT 'rung value derived from mean_score',
            `floor_score` DECIMAL(4,2) NULL COMMENT 'the weakest skill score; reported, not enforced',
            `floor_dimension_id` INT UNSIGNED NULL,

            `is_synthetic` TINYINT(1) NOT NULL DEFAULT 0,
            `archetype` VARCHAR(40) NULL COMMENT 'synthetic records only',
            `seed_run_id` INT UNSIGNED NULL,
            `submitted_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submissions_public_token` (`public_token`),
            UNIQUE KEY `submissions_record_id` (`record_id`),
            KEY `submissions_instrument_id` (`instrument_id`),
            KEY `submissions_organisation_id` (`organisation_id`),
            KEY `submissions_is_synthetic` (`is_synthetic`),
            KEY `submissions_year` (`assessment_year`),
            KEY `submissions_status` (`status`),
            KEY `submissions_floor_dimension_id` (`floor_dimension_id`),
            KEY `submissions_level_value` (`level_value`),
            KEY `submissions_submitted_at` (`submitted_at`),
            KEY `submissions_email` (`respondent_email`),
            CONSTRAINT `he_submissions_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `he_submissions_organisation_fk`
                FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `he_submissions_floor_dimension_fk`
                FOREIGN KEY (`floor_dimension_id`) REFERENCES `{$this->table('dimensions')}` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // One row per question answered. A skill left unscored has no rows,
        // and is left out of the average rather than counted as zero.
        $this->create('submission_responses', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `mark` TINYINT UNSIGNED NOT NULL COMMENT '1-5',
            `answered_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_responses_unique` (`submission_id`, `item_id`),
            KEY `submission_responses_item_id` (`item_id`),
            CONSTRAINT `he_submission_responses_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `he_submission_responses_item_fk`
                FOREIGN KEY (`item_id`) REFERENCES `{$this->table('items')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        SQL);

        // Derived and stored, rewritten by the Scorer, never posted.
        $this->create('submission_scores', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `dimension_id` INT UNSIGNED NOT NULL,
            `score` DECIMAL(4,2) NOT NULL COMMENT 'unweighted mean of the skill\'s answered items',
            `rung_value` TINYINT UNSIGNED NOT NULL,
            `items_answered` TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_scores_unique` (`submission_id`, `dimension_id`),
            KEY `submission_scores_dimension_id` (`dimension_id`),
            CONSTRAINT `he_submission_scores_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `he_submission_scores_dimension_fk`
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
