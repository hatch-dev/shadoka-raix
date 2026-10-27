<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Base class for platform and app seeders.
 *
 * Seeders are idempotent by contract: running one twice must not duplicate
 * rows. The runner does not track what it has already done, because a seeder
 * that cannot safely re-run is a seeder that cannot be used on a live database.
 */
abstract class Seeder
{
    protected ?AppManifest $app = null;

    /** @var list<string> */
    protected array $log = [];

    public function bindApp(?AppManifest $app): void
    {
        $this->app = $app;
    }

    /** Short description shown by the runner. */
    abstract public function name(): string;

    abstract public function run(): void;

    /** @return list<string> */
    public function output(): array
    {
        return $this->log;
    }

    protected function say(string $message): void
    {
        $this->log[] = $message;
    }

    /** Resolve a table name against this seeder's app prefix. */
    protected function table(string $name): string
    {
        return $this->app?->table($name) ?? $name;
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
