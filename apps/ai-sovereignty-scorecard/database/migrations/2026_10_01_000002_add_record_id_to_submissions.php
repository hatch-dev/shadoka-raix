<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * sov_submissions.record_id — the sov_records row a submission mirrors.
 *
 * Since the v6 page (directive v6, 1 Oct 2026) sov_records is where scorecards
 * are kept. The admin console's screens (Analytics, Research data, exports,
 * the organisation view) read the relational sov_submissions family, so every
 * record is mirrored into it by Services/RecordMirror and tagged here. A row
 * with no record_id is from the previous edition's own flow.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table('submissions');

        $this->exec(sprintf(
            "ALTER TABLE `%s`
               ADD COLUMN `record_id` VARCHAR(64) NULL COMMENT 'the sov_records id this row mirrors; NULL = previous edition' AFTER `public_token`,
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
