<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Base class for every migration, platform or app.
 *
 * A migration file returns an anonymous class extending this one. Keeping them
 * as returned objects rather than named classes means two apps can both have a
 * "create submissions" migration without colliding in the class table.
 */
abstract class Migration
{
    /**
     * The app this migration belongs to, or null for a platform migration.
     * Set by the migrator before up()/down() so $this->table() can prefix.
     */
    protected ?AppManifest $app = null;

    public function bindApp(?AppManifest $app): void
    {
        $this->app = $app;
    }

    abstract public function up(): void;

    /** Override when a migration can be reversed. */
    public function down(): void
    {
        // Irreversible by default: most of these create tables holding research
        // data, and a silent no-op is safer than a guessed DROP.
    }

    /**
     * Resolve a table name. App migrations get their prefix applied; platform
     * migrations get the bare name, so shared tables stay shared.
     */
    protected function table(string $name): string
    {
        return $this->app?->table($name) ?? $name;
    }

    /** Run raw DDL. */
    protected function exec(string $sql): void
    {
        Database::connection()->exec($sql);
    }

    /** The trailing clause every table in this platform is created with. */
    protected function tableOptions(): string
    {
        return sprintf(
            'ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s',
            config('database.engine', 'InnoDB'),
            config('database.charset', 'utf8mb4'),
            config('database.collation', 'utf8mb4_unicode_ci')
        );
    }

    /** Create a table from a body of column and key definitions. */
    protected function create(string $name, string $body): void
    {
        $this->exec(sprintf(
            "CREATE TABLE IF NOT EXISTS `%s` (\n%s\n) %s",
            $this->table($name),
            rtrim(trim($body), ','),
            $this->tableOptions()
        ));
    }

    protected function drop(string $name): void
    {
        $this->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table($name)));
    }
}
