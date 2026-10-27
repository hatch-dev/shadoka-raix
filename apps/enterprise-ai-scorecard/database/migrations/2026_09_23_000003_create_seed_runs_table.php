<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Every synthetic dataset generation, recorded.
 *
 * Demo records are the most dangerous rows in a research database: they look
 * exactly like real ones. Recording who generated which batch, with what
 * options, means a batch can be identified and removed in full later.
 *
 * Note for whoever revisits the generator: the reference build did NOT derive a
 * synthetic record's `agenda` or `edu` figures at all — it left them absent, so
 * the correlation panes honestly reported that nobody had given a number. That
 * restraint is deliberate and must be kept. Generating those two fields from a
 * record's own score is what would make the correlation screen report a
 * relationship the generator had manufactured.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('seed_runs', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `seed` BIGINT NOT NULL COMMENT 'PRNG seed; the run is reproducible from this plus options',
            `options` JSON NOT NULL COMMENT 'count, years, respondents, spread, archetype mix',
            `records_created` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `organisations_created` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `instrument_id` INT UNSIGNED NOT NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `seed_runs_created_by` (`created_by`),
            CONSTRAINT `es_seed_runs_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `es_seed_runs_created_by_fk`
                FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // Wired here rather than in the submissions migration so the foreign
        // key can point at a table that now exists.
        $this->exec(sprintf(
            'ALTER TABLE `%s`
                ADD CONSTRAINT `es_submissions_seed_run_fk`
                FOREIGN KEY (`seed_run_id`) REFERENCES `%s` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE',
            $this->table('submissions'),
            $this->table('seed_runs')
        ));
    }

    public function down(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s` DROP FOREIGN KEY `es_submissions_seed_run_fk`',
            $this->table('submissions')
        ));
        $this->drop('seed_runs');
    }
};
