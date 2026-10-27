<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * es_records — one row per scorecard, as the v32 page sends it.
 *
 * Directive_Privacy_DEV_Enterprise_Scorecard_v32_28Sep2026 (Part B) makes the
 * page the owner of the record's shape: the server must "store the object
 * exactly as received". So `payload` is the request body, byte for byte, and
 * every other column is derived from it only so the server can filter without
 * parsing JSON:
 *
 *   synthetic     B1 refuses true, B3 refuses anything else, B2 returns these
 *   restricted    C4: kept but excluded from B2 and every export
 *   email         C4: find a person's record (userEmail, else org.email), lowercased
 *   completed_at  C3: the 24-month clock. The earliest of the record's own
 *                 timestamp, the moment it first arrived and any earlier copy's
 *                 clock, so neither a future-dated timestamp nor a re-save can
 *                 keep a record past the limit.
 *
 * The es_submissions family is untouched: the admin screens still read it.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('records', <<<SQL
            `id`           VARCHAR(64)  NOT NULL COMMENT 'the page''s own record id, e.g. raixsc-mulbroh9yjpo',
            `synthetic`    TINYINT(1)   NOT NULL DEFAULT 0,
            `restricted`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'C4 restriction: excluded from /records and exports',
            `email`        VARCHAR(254) NULL COMMENT 'lowercased userEmail or org.email, for requests from individuals',
            `completed_at` DATETIME     NOT NULL COMMENT 'retention clock (UTC)',
            `payload`      LONGTEXT     NOT NULL COMMENT 'the JSON object exactly as received',
            `received_at`  DATETIME     NOT NULL COMMENT 'first arrival (UTC)',
            `updated_at`   DATETIME     NULL COMMENT 'set when the person re-saves, or staff correct or restrict it',
            PRIMARY KEY (`id`),
            KEY `es_records_synthetic_restricted` (`synthetic`, `restricted`),
            KEY `es_records_email` (`email`),
            KEY `es_records_completed_at` (`completed_at`)
        SQL);
    }

    public function down(): void
    {
        $this->drop('records');
    }
};
