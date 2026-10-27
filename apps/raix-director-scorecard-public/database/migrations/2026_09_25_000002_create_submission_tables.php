<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Submissions and their raw marks.
 *
 * Rules the schema enforces rather than trusts:
 *
 *  1. The client posts marks only. floor/mean/level are derived server-side by
 *     Services\Scoring\Scorer and written here.
 *  2. `is_synthetic` is a first-class indexed column, so excluding demo data is
 *     a query-layer default rather than something each report re-implements.
 *  3. The director's identity is REQUIRED in this edition — name, email, phone
 *     — and stored on the submission. The board itself is resolved onto the
 *     platform's shared `organisations` table as in the sibling app.
 *  4. Consent is a stored fact: when the terms were accepted and which terms,
 *     so a later revision of them cannot be read back onto an old acceptance.
 *
 * The identity columns are NOT NULL: nothing may write a record without them,
 * synthetic ones included (those get deliberately unreachable addresses).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('submissions', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_token` CHAR(32) NOT NULL COMMENT 'lets a director reopen their own result; no account needed',
            `instrument_id` INT UNSIGNED NOT NULL COMMENT 'the version this was scored against, pinned forever',
            `organisation_id` INT UNSIGNED NULL COMMENT 'platform-level board record',

            `director_name` VARCHAR(120) NOT NULL COMMENT 'required; self-asserted and never verified',
            `director_email` VARCHAR(254) NOT NULL COMMENT 'required; format-checked only',
            `director_phone` VARCHAR(40) NOT NULL COMMENT 'required; 7-15 digits, format-checked only',
            `board_name` VARCHAR(191) NOT NULL COMMENT 'as typed; organisations holds the deduplicated row',

            `seat_role` VARCHAR(100) NULL,
            `tenure` VARCHAR(50) NULL,
            `sector` VARCHAR(100) NULL COMMENT 'sector as this director reported it; the board\'s own is on organisations',
            `company_size` VARCHAR(50) NOT NULL COMMENT 'required; one of the instrument size bands',
            `assessment_year` SMALLINT UNSIGNED NOT NULL,
            `systems_seen` SMALLINT UNSIGNED NULL COMMENT 'AI systems the director is aware of',
            `board_seats` SMALLINT UNSIGNED NULL,
            `agenda_meetings` SMALLINT UNSIGNED NULL COMMENT 'meetings with AI as a DECISION item',
            `education_hours` DECIMAL(5,1) NULL,
            `remit` JSON NULL COMMENT 'competency codes inside the director\'s committee remit; context only, never an exemption',

            `consent_agreed_at` DATETIME NULL COMMENT 'when the director accepted the terms shown before the questionnaire',
            `consent_terms_url` VARCHAR(255) NULL COMMENT 'the terms they were shown',

            `status` ENUM('in_progress','complete') NOT NULL DEFAULT 'in_progress',
            `floor_score` DECIMAL(4,2) NULL,
            `floor_competency_id` INT UNSIGNED NULL,
            `mean_score` DECIMAL(4,2) NULL,
            `level_value` TINYINT UNSIGNED NULL COMMENT 'rung value derived from floor_score',
            `is_synthetic` TINYINT(1) NOT NULL DEFAULT 0,
            `archetype` VARCHAR(40) NULL COMMENT 'synthetic records only',
            `seed_run_id` INT UNSIGNED NULL,
            `ip_hash` CHAR(64) NULL COMMENT 'hashed, for rate limiting only',
            `submitted_at` DATETIME NULL,
            `confirmed_at` DATETIME NULL COMMENT 'when the director explicitly accepted this scorecard on the results page',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submissions_public_token` (`public_token`),
            KEY `submissions_instrument_id` (`instrument_id`),
            KEY `submissions_organisation_id` (`organisation_id`),
            KEY `submissions_is_synthetic` (`is_synthetic`),
            KEY `submissions_year` (`assessment_year`),
            KEY `submissions_status` (`status`),
            KEY `submissions_floor_competency_id` (`floor_competency_id`),
            KEY `submissions_email` (`director_email`),
            CONSTRAINT `dsp_submissions_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `dsp_submissions_organisation_fk`
                FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `dsp_submissions_floor_competency_fk`
                FOREIGN KEY (`floor_competency_id`) REFERENCES `{$this->table('competencies')}` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        $this->create('submission_responses', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `mark` TINYINT UNSIGNED NOT NULL COMMENT '1-4',
            `evidence_note` VARCHAR(255) NULL COMMENT 'optional "when, and where?"; never verified',
            `answered_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_responses_unique` (`submission_id`, `item_id`),
            KEY `submission_responses_item_id` (`item_id`),
            CONSTRAINT `dsp_submission_responses_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `dsp_submission_responses_item_fk`
                FOREIGN KEY (`item_id`) REFERENCES `{$this->table('items')}` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        SQL);

        $this->create('submission_scores', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `competency_id` INT UNSIGNED NOT NULL,
            `score` DECIMAL(4,2) NOT NULL COMMENT 'unweighted mean of the competency\'s answered items',
            `rung_value` TINYINT UNSIGNED NOT NULL,
            `items_answered` TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_scores_unique` (`submission_id`, `competency_id`),
            KEY `submission_scores_competency_id` (`competency_id`),
            CONSTRAINT `dsp_submission_scores_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `dsp_submission_scores_competency_fk`
                FOREIGN KEY (`competency_id`) REFERENCES `{$this->table('competencies')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('submission_commitments', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `commitment_key` VARCHAR(40) NOT NULL,
            `is_checked` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `submission_commitments_unique` (`submission_id`, `commitment_key`),
            CONSTRAINT `dsp_submission_commitments_submission_fk`
                FOREIGN KEY (`submission_id`) REFERENCES `{$this->table('submissions')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('submission_commitments');
        $this->drop('submission_scores');
        $this->drop('submission_responses');
        $this->drop('submissions');
    }
};
