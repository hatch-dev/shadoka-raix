<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Board name and company size become optional.
 *
 * Requested on 25 Sep 2026: the reference requires both, this edition asks for
 * them without insisting. A blank is stored as NULL — never as '' and never as
 * a label — so "not given" stays distinguishable in the data; the results page
 * and the PDF show an unnamed board as "Anonymous" on the way out.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->exec(sprintf(
            "ALTER TABLE `%s`
                MODIFY `board_name` VARCHAR(191) NULL COMMENT 'optional; as typed; organisations holds the deduplicated row',
                MODIFY `company_size` VARCHAR(50) NULL COMMENT 'optional; one of the instrument size bands when given'",
            $this->table('submissions')
        ));
    }

    public function down(): void
    {
        // Irreversible once a NULL has been written: NOT NULL would refuse it.
    }
};
