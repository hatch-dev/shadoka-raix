<?php

declare(strict_types=1);

namespace Platform\Core;

use InvalidArgumentException;

/**
 * Every enabled app, loaded from config/apps.php.
 *
 * The registry is what makes RAIX a platform rather than one program: the
 * router, the migrator, the asset publisher and the admin shell all ask this
 * class which apps exist instead of knowing any app by name.
 */
final class AppRegistry
{
    /** @var array<string, AppManifest>|null */
    private static ?array $apps = null;

    private function __construct()
    {
    }

    /** @return array<string, AppManifest> keyed by slug, in config order */
    public static function all(): array
    {
        if (self::$apps !== null) {
            return self::$apps;
        }

        $apps     = [];
        $prefixes = [];

        foreach ((array) config('apps.enabled', []) as $slug) {
            $manifest = AppManifest::load((string) $slug);

            // Two apps sharing a prefix would let one app's migration target
            // the other's tables. Fail loudly at boot, not at query time.
            if (isset($prefixes[$manifest->tablePrefix])) {
                throw new InvalidArgumentException(sprintf(
                    "Apps '%s' and '%s' both use the table prefix '%s'.",
                    $prefixes[$manifest->tablePrefix],
                    $manifest->slug,
                    $manifest->tablePrefix
                ));
            }
            $prefixes[$manifest->tablePrefix] = $manifest->slug;

            $apps[$manifest->slug] = $manifest;
        }

        return self::$apps = $apps;
    }

    public static function has(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    public static function get(string $slug): AppManifest
    {
        $apps = self::all();

        if (!isset($apps[$slug])) {
            throw new InvalidArgumentException("App '{$slug}' is not enabled.");
        }

        return $apps[$slug];
    }

    /** The app served at the site root, or null when the platform index shows. */
    public static function default(): ?AppManifest
    {
        $slug = config('apps.default');

        return $slug !== null && self::has((string) $slug) ? self::get((string) $slug) : null;
    }

    /**
     * Slugs served OUTSIDE the platform's mount: /RAIX/{slug} and
     * /RAIX/admin/{slug} rather than /RAIX/dev/... (config/apps.php 'unmounted').
     *
     * url() consults this, so every link to such an app — from its own pages or
     * from anywhere else — already carries its new address — and MountGuard
     * consults it, so the old /dev address redirects. The root .htaccess knows
     * nothing about which app is where.
     *
     * @return list<string>
     */
    public static function unmounted(): array
    {
        $slugs   = array_values(array_map('strval', (array) config('apps.unmounted', [])));
        $default = config('apps.default');

        foreach ($slugs as $slug) {
            if (!self::has($slug)) {
                throw new InvalidArgumentException("Unmounted app '{$slug}' is not enabled.");
            }

            // The default app also answers UNSLUGGED paths (/RAIX/dev/assess),
            // which only exist under the mount. Moving it needs its own step.
            if ($slug === $default) {
                throw new InvalidArgumentException("The default app '{$slug}' cannot be unmounted while it is the default.");
            }
        }

        return $slugs;
    }

    public static function isMounted(string $slug): bool
    {
        return !in_array($slug, self::unmounted(), true);
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    /**
     * Every capability declared across every app, plus the platform's own.
     * This is the list the role editor offers.
     *
     * @return list<string>
     */
    public static function capabilities(): array
    {
        $capabilities = (array) config('auth.base_capabilities', []);

        foreach (self::all() as $app) {
            foreach ($app->capabilities as $capability) {
                $capabilities[] = $capability;
            }
        }

        return array_values(array_unique($capabilities));
    }

    /** Forget the cached manifests. Used by tests and the CLI. */
    public static function flush(): void
    {
        self::$apps = null;
    }
}
