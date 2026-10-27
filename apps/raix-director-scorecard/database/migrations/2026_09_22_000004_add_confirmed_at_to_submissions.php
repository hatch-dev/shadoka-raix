<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * When the director accepted their own scorecard.
 *
 * The single-file build kept scorecards in browser storage, so "Save this
 * scorecard" was the moment the record came into existence. Here it already
 * exists — answers are written as they are given — which left the button with
 * nothing to do.
 *
 * Rather than drop it or let it lie, it records something the dataset did not
 * previously distinguish: reaching the results page is not the same as the
 * director looking at their result and accepting it. For a self-assessment
 * whose whole claim rests on the respondent standing behind it, that is worth
 * being able to tell apart.
 *
 * Nullable, and nothing filters on it yet: every complete submission still
 * counts in analytics exactly as before. It is provenance, not a gate.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s`
                ADD COLUMN `confirmed_at` DATETIME NULL
                    COMMENT %s
                AFTER `submitted_at`',
            $this->table('submissions'),
            "'when the director explicitly accepted this scorecard on the results page'"
        ));
    }

    public function down(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN `confirmed_at`',
            $this->table('submissions')
        ));
    }
};
