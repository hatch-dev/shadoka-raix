<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * dsp_records — one row per scorecard, as the v13 page sent it.
 *
 * Directive_Privacy_DEV_Public_Director_Scorecard_v13_28Sep2026 (Part B) makes
 * the page the owner of the record's shape: the server must "store the object
 * exactly as received". So `payload` is the request body, byte for byte, and
 * every other column is derived from it only so the server can filter without
 * parsing JSON:
 *
 *   synthetic     B1 refuses true, B4 refuses anything else, B3 returns these
 *   restricted    C4: kept but excluded from B2, B3 and every export
 *   email         C4: find a person's record (userEmail, else org.email), lowercased
 *   floor         B2: the only figure the benchmark reads
 *   completed_at  C3: the 24-month clock. The earlier of the record's own
 *                 timestamp and the moment it arrived, so a future-dated
 *                 timestamp cannot keep a record past the limit.
 *
 * The old dsp_submissions family is left in place and no longer written to.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('records', "
            `id`           VARCHAR(64)  NOT NULL COMMENT 'the page''s own record id, e.g. raixsc-mul6w9agvusz',
            `synthetic`    TINYINT(1)   NOT NULL DEFAULT 0,
            `restricted`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'C4 restriction: excluded from benchmark, /records and exports',
            `email`        VARCHAR(254) NULL COMMENT 'lowercased userEmail or org.email, for requests from individuals',
            `floor`        DECIMAL(4,2) NULL,
            `completed_at` DATETIME     NOT NULL COMMENT 'retention clock (UTC)',
            `payload`      LONGTEXT     NOT NULL COMMENT 'the JSON object exactly as received',
            `received_at`  DATETIME     NOT NULL,
            `updated_at`   DATETIME     NULL COMMENT 'set when staff correct or restrict the record',
            PRIMARY KEY (`id`),
            KEY `dsp_records_synthetic_restricted` (`synthetic`, `restricted`),
            KEY `dsp_records_email` (`email`),
            KEY `dsp_records_completed_at` (`completed_at`)
        ");
    }

    public function down(): void
    {
        $this->drop('records');
    }
};
