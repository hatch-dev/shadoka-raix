<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * dsp_submissions.record_id — the dsp_records row a submission mirrors.
 *
 * Since the v13 page (directive v13, 29 Sep 2026) dsp_records is where
 * scorecards are kept. The admin console's screens (Analytics, Research data,
 * exports, the board view) read the relational dsp_submissions family, so
 * every record is mirrored into it by Services/RecordMirror and tagged here —
 * as the AI Sovereignty and Enterprise Scorecards do. A row with no record_id
 * is from the previous edition's own flow.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table('submissions');

        $this->exec(sprintf(
            "ALTER TABLE `%s`
               ADD COLUMN `record_id` VARCHAR(64) NULL COMMENT 'the dsp_records id this row mirrors; NULL = previous edition' AFTER `public_token`,
               ADD UNIQUE KEY `submissions_record_id` (`record_id`)",
            $table
        ));
    }

    public function down(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s` DROP INDEX `submissions_record_id`, DROP COLUMN `record_id`',
            $this->table('submissions')
        ));
    }
};
