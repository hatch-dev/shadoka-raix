<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Every synthetic dataset generation, recorded.
 *
 * Demo records look exactly like real ones. Recording who generated which
 * batch, with what options, means a batch can be identified later. The Human
 * Edge seeder REPLACES the whole synthetic set (as the page's own seeder does),
 * so the latest run is the one whose records are in the dataset.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('seed_runs', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `seed` BIGINT NOT NULL COMMENT 'PRNG seed; the run is reproducible from this plus options',
            `options` JSON NOT NULL COMMENT 'count, respondents, share, spread, archetype mix',
            `records_created` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `organisations_created` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `instrument_id` INT UNSIGNED NOT NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `seed_runs_created_by` (`created_by`),
            CONSTRAINT `he_seed_runs_instrument_fk`
                FOREIGN KEY (`instrument_id`) REFERENCES `{$this->table('instruments')}` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `he_seed_runs_created_by_fk`
                FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        $this->exec(sprintf(
            'ALTER TABLE `%s`
                ADD CONSTRAINT `he_submissions_seed_run_fk`
                FOREIGN KEY (`seed_run_id`) REFERENCES `%s` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE',
            $this->table('submissions'),
            $this->table('seed_runs')
        ));
    }

    public function down(): void
    {
        $this->exec(sprintf(
            'ALTER TABLE `%s` DROP FOREIGN KEY `he_submissions_seed_run_fk`',
            $this->table('submissions')
        ));
        $this->drop('seed_runs');
    }
};
