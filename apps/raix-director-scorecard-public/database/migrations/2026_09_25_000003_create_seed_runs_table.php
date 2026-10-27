<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Every synthetic dataset generation, recorded, so a batch can be identified
 * and removed in full later.
 *
 * As in the sibling app, the generator must never derive a synthetic
 * director's `agenda` or `education` figures from that record's own score:
 * doing so manufactures the correlation the analytics screen then reports.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('seed_runs', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `seed` BIGINT NOT NULL COMMENT 'PRNG seed; the run is reproducible from this plus options',
            `options` JSON NOT NULL COMMENT 'count, years, raters, spread, archetype mix',
            `records_created` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `organisations_created` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `instrument_id` INT UNSIGNED NOT NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `seed_runs_created_by` (`created_by`),
            CONSTRAINT `dsp_seed_runs_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `dsp_seed_runs_created_by_fk`
                FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // Wired here rather than in the submissions migration so the foreign
        // key can point at a table that now exists.
        $this->exec(sprintf(
            'ALTER TABLE `%s`
                ADD CONSTRAINT `dsp_submissions_seed_run_fk`
                FOREIGN KEY (`seed_run_id`) REFERENCES `%s` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE',
            $this->table('submissions'),
            $this->table('seed_runs')
        ));
    }

    public function down(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s` DROP FOREIGN KEY `dsp_submissions_seed_run_fk`',
            $this->table('submissions')
        ));
        $this->drop('seed_runs');
    }
};
