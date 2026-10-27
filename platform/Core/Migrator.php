<?php

declare(strict_types=1);

namespace Platform\Core;

use RuntimeException;

/**
 * Runs platform migrations first, then each enabled app's, in registry order.
 *
 * One `migrations` table tracks them all, keyed by (app, migration), so two
 * apps may carry identically-named files without one being mistaken for the
 * other. Platform migrations are recorded with app = '_platform'.
 */
final class Migrator
{
    public const PLATFORM = '_platform';

    /** @var list<string> */
    private array $log = [];

    /** Create the ledger table if this is a fresh database. */
    public function ensureLedger(): void
    {
        Database::connection()->exec(
            'CREATE TABLE IF NOT EXISTS `migrations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `app` VARCHAR(64) NOT NULL,
                `migration` VARCHAR(191) NOT NULL,
                `batch` INT UNSIGNED NOT NULL,
                `ran_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `migrations_app_migration` (`app`, `migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Apply every pending migration.
     *
     * @return list<string> human-readable lines describing what ran
     */
    public function migrate(): array
    {
        $this->ensureLedger();
        $this->log = [];

        $batch = ((int) Database::scalar('SELECT MAX(batch) FROM `migrations`')) + 1;

        $this->runSet(self::PLATFORM, base_path('database/migrations'), null, $batch);

        foreach (AppRegistry::all() as $app) {
            $this->runSet($app->slug, $app->migrationsPath(), $app, $batch);
        }

        if ($this->log === []) {
            $this->log[] = 'Nothing to migrate.';
        }

        return $this->log;
    }

    /** Pending migrations, without running them. */
    public function pending(): array
    {
        $this->ensureLedger();
        $pending = [];

        foreach ($this->files(base_path('database/migrations')) as $name => $file) {
            if (!$this->hasRun(self::PLATFORM, $name)) {
                $pending[] = self::PLATFORM . '/' . $name;
            }
        }

        foreach (AppRegistry::all() as $app) {
            foreach ($this->files($app->migrationsPath()) as $name => $file) {
                if (!$this->hasRun($app->slug, $name)) {
                    $pending[] = $app->slug . '/' . $name;
                }
            }
        }

        return $pending;
    }

    private function runSet(string $owner, string $directory, ?AppManifest $app, int $batch): void
    {
        foreach ($this->files($directory) as $name => $file) {
            if ($this->hasRun($owner, $name)) {
                continue;
            }

            $migration = require $file;

            if (!$migration instanceof Migration) {
                throw new RuntimeException(
                    "Migration {$owner}/{$name} must return an instance of Platform\\Core\\Migration."
                );
            }

            $migration->bindApp($app);

            // Each migration is its own transaction. MySQL commits DDL
            // implicitly, so this protects the ledger write rather than the
            // schema change — a migration that half-applies still has to be
            // fixed by hand, and the ledger will show it never completed.
            $migration->up();

            Database::insert('migrations', [
                'app'       => $owner,
                'migration' => $name,
                'batch'     => $batch,
                'ran_at'    => date('Y-m-d H:i:s'),
            ]);

            $this->log[] = sprintf('migrated  %s/%s', $owner, $name);
        }
    }

    /**
     * Migration files in a directory, keyed by name, sorted by filename.
     *
     * @return array<string, string>
     */
    private function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $set = [];
        foreach ($files as $file) {
            $set[basename($file, '.php')] = $file;
        }

        return $set;
    }

    private function hasRun(string $owner, string $migration): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `migrations` WHERE `app` = ? AND `migration` = ?',
            [$owner, $migration]
        ) > 0;
    }
}
