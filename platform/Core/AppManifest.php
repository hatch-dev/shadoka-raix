<?php

declare(strict_types=1);

namespace Platform\Core;

use InvalidArgumentException;

/**
 * One app's app.php, validated.
 *
 * The manifest is the only registration point an app has. Nothing outside
 * apps/{slug}/ knows an app exists except the one line naming it in
 * config/apps.php, so everything the platform needs must be declared here.
 */
final class AppManifest
{
    private const REQUIRED = ['slug', 'name', 'namespace', 'table_prefix'];

    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $namespace,
        public readonly string $tablePrefix,
        public readonly string $path,
        /** @var list<string> */
        public readonly array $routes,
        /** @var list<array{section:string,route:string,capability?:string}> */
        public readonly array $adminNav,
        /** @var list<string> */
        public readonly array $capabilities,
        public readonly ?string $description,
    ) {
    }

    /** Load and validate apps/{slug}/app.php. */
    public static function load(string $slug): self
    {
        $path = base_path('apps/' . $slug);
        $file = $path . '/app.php';

        if (!is_file($file)) {
            throw new InvalidArgumentException("App '{$slug}' has no app.php at {$file}");
        }

        $data = require $file;

        if (!is_array($data)) {
            throw new InvalidArgumentException("App '{$slug}': app.php must return an array.");
        }

        foreach (self::REQUIRED as $key) {
            if (!isset($data[$key]) || $data[$key] === '') {
                throw new InvalidArgumentException("App '{$slug}': app.php is missing '{$key}'.");
            }
        }

        // The folder name is the identity. A mismatch means routes, assets and
        // capabilities would disagree about which app they belong to.
        if ($data['slug'] !== $slug) {
            throw new InvalidArgumentException(
                "App '{$slug}': manifest slug '{$data['slug']}' does not match its folder."
            );
        }

        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException(
                "App '{$slug}': slug must be lowercase kebab-case."
            );
        }

        // Table prefixes must not collide or shadow one another, which would
        // let one app's migration silently target another app's table.
        if (!preg_match('/^[a-z][a-z0-9]*_$/', (string) $data['table_prefix'])) {
            throw new InvalidArgumentException(
                "App '{$slug}': table_prefix must be lowercase and end with an underscore, e.g. 'ds_'."
            );
        }

        return new self(
            slug:         $slug,
            name:         (string) $data['name'],
            namespace:    rtrim((string) $data['namespace'], '\\') . '\\',
            tablePrefix:  (string) $data['table_prefix'],
            path:         $path,
            routes:       array_values((array) ($data['routes'] ?? [])),
            adminNav:     array_values((array) ($data['admin_nav'] ?? [])),
            capabilities: array_values((array) ($data['capabilities'] ?? [])),
            description:  isset($data['description']) ? (string) $data['description'] : null,
        );
    }

    /** Absolute path inside the app folder. */
    public function path(string $suffix = ''): string
    {
        return $suffix === '' ? $this->path : $this->path . '/' . ltrim($suffix, '/');
    }

    /** Prefix a bare table name with this app's prefix: 'submissions' -> 'ds_submissions'. */
    public function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    /**
     * Read this app's own config with dot notation: $app->config('scoring.rule').
     *
     * App config is separate from the platform's on purpose. The floor rule is
     * the Director Scorecard's rule, not a platform rule — a sibling instrument
     * may aggregate entirely differently.
     *
     * @var array<string, array<string, mixed>> cached per app+file
     */
    public function config(string $key, mixed $default = null): mixed
    {
        static $loaded = [];

        $segments = explode('.', $key);
        $file     = array_shift($segments);
        $cacheKey = $this->slug . ':' . $file;

        if (!array_key_exists($cacheKey, $loaded)) {
            $path            = $this->path('config/' . $file . '.php');
            $loaded[$cacheKey] = is_file($path) ? require $path : [];
        }

        $value = $loaded[$cacheKey];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function migrationsPath(): string
    {
        return $this->path('database/migrations');
    }

    public function seedsPath(): string
    {
        return $this->path('database/seeds');
    }

    public function assetsPath(): string
    {
        return $this->path('assets');
    }
}
