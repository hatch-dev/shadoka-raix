<?php

declare(strict_types=1);

use Platform\Core\Database;
use Platform\Core\Migration;

/**
 * "Board Candidate" becomes the first option in "Your role on this board".
 *
 * Requested on 25 Sep 2026. Only the display ORDER changes (the `position`
 * column). The values are what submissions store, so none is renamed, added or
 * removed, and every existing record reads exactly as before.
 *
 * Applied to every instrument version of this app, and idempotent: a version
 * where it is already first is left alone, so a fresh install seeded from the
 * updated instrument-v1.json passes through untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table('reference_options');

        foreach (Database::select(sprintf(
            "SELECT `instrument_id`, `position` FROM `%s` WHERE `group_key` = 'roles' AND `value` = 'Board Candidate'",
            $table
        )) as $row) {
            $instrument = (int) $row['instrument_id'];
            $current    = (int) $row['position'];

            if ($current <= 1) {
                continue;
            }

            // Everything that was above it moves down one place...
            Database::statement(sprintf(
                "UPDATE `%s` SET `position` = `position` + 1
                  WHERE `instrument_id` = ? AND `group_key` = 'roles' AND `position` < ?",
                $table
            ), [$instrument, $current]);

            // ...and it takes the top.
            Database::statement(sprintf(
                "UPDATE `%s` SET `position` = 1
                  WHERE `instrument_id` = ? AND `group_key` = 'roles' AND `value` = 'Board Candidate'",
                $table
            ), [$instrument]);
        }
    }
};
