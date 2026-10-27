<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * The sector and company size the director themselves reported.
 *
 * These were only ever stored on the `organisations` row, and
 * OrganisationResolver::resolve() returns null for a blank board name — so a
 * director who chose a sector but did not name their board had that answer
 * accepted by the form, validated against the reference list, and then thrown
 * away. Nothing recorded it and nothing reported it: the Sector filter on the
 * analytics screen simply had no options.
 *
 * They belong here as well as on the organisation because they are two
 * different facts. The organisation's sector is what the BOARD is; these are
 * what this DIRECTOR said, which is the self-report the instrument collects and
 * the only version that survives when no board is named.
 *
 * Nullable, because both fields are genuinely optional on the form.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s`
                ADD COLUMN `sector` VARCHAR(100) NULL
                    COMMENT %s
                AFTER `tenure`,
                ADD COLUMN `company_size` VARCHAR(50) NULL
                    COMMENT %s
                AFTER `sector`',
            $this->table('submissions'),
            "'sector as this director reported it; the board\\'s own is on organisations'",
            "'company size as this director reported it'"
        ));
    }

    public function down(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN `sector`, DROP COLUMN `company_size`',
            $this->table('submissions')
        ));
    }
};
