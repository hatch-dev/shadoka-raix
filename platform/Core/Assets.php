<?php

declare(strict_types=1);

namespace Platform\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Publishing app assets into public/, and fingerprinting them for the browser.
 *
 * Source assets live in apps/{slug}/assets so they sit beside the code that
 * uses them; public/ holds only a published copy. That split is deliberate —
 * public/ is the document root and should contain nothing that is also source —
 * but it has one sharp edge: editing the source changes nothing until it is
 * republished, and the symptom is a stylesheet that stubbornly will not update.
 *
 * So, two things happen here:
 *
 *  - In debug mode the front controller calls syncStale() on each page render,
 *    which republishes any file whose source is newer. Editing a stylesheet and
 *    reloading now just works.
 *  - Every asset URL carries ?v=<mtime>, so a republished file is a new URL and
 *    the long cache lifetime in public/.htaccess is actually safe. Before this,
 *    that .htaccess comment claimed assets were "fingerprinted by the
 *    publisher" and nothing fingerprinted anything.
 */
final class Assets
{
    /** Source extensions that are never servable, whatever they are doing there. */
    private const NEVER_PUBLISH = ['php', 'md'];

    /** @var array<string, string> published path => cache-busting token */
    private static array $fingerprints = [];

    private function __construct()
    {
    }

    /* ------------------------------------------------------------------ */
    /* Publishing                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Publish the platform's assets and every enabled app's.
     *
     * @return array{files:int, bytes:int, apps:array<string, array{files:int, bytes:int}>}
     */
    public static function publishAll(bool $clean = false, ?string $only = null): array
    {
        $result = ['files' => 0, 'bytes' => 0, 'apps' => []];

        if ($only === null) {
            $platform = self::copy(base_path('platform/assets'), base_path('public/assets/platform'), $clean);

            if ($platform['files'] > 0) {
                $result['apps']['_platform'] = $platform;
                $result['files'] += $platform['files'];
                $result['bytes'] += $platform['bytes'];
            }
        }

        foreach (AppRegistry::all() as $app) {
            if ($only !== null && $app->slug !== $only) {
                continue;
            }

            $published = self::copy(
                $app->assetsPath(),
                base_path('public/assets/apps/' . $app->slug),
                $clean
            );

            $result['apps'][$app->slug] = $published;
            $result['files'] += $published['files'];
            $result['bytes'] += $published['bytes'];
        }

        return $result;
    }

    /**
     * Republish anything whose source is newer than its published copy.
     *
     * Debug only. It walks the asset trees on every page render, which is fine
     * for a few dozen files in development and would be wasteful in production —
     * where `php scripts/publish-assets.php` runs once at deploy instead.
     */
    public static function syncStale(): void
    {
        if (!config('app.debug')) {
            return;
        }

        try {
            self::publishAll();
        } catch (\Throwable $e) {
            // A failed dev sync must never take the page down with it.
            logger('Asset sync skipped: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * Copy $from into $to, skipping files that are already current.
     *
     * @return array{files:int, bytes:int}
     */
    public static function copy(string $from, string $to, bool $clean = false): array
    {
        if (!is_dir($from)) {
            return ['files' => 0, 'bytes' => 0];
        }

        if ($clean) {
            self::removeTree($to);
        }

        self::ensureDirectory($to);

        $files = 0;
        $bytes = 0;

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $target   = $to . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                self::ensureDirectory($target);
                continue;
            }

            if (in_array(strtolower($item->getExtension()), self::NEVER_PUBLISH, true)) {
                continue;
            }

            // Size as well as mtime: a reformat that happens to preserve the
            // timestamp still changes the bytes.
            $stale = !is_file($target)
                || filemtime($item->getPathname()) > filemtime($target)
                || filesize($item->getPathname()) !== filesize($target);

            if ($stale) {
                /*
                 * Checked, not assumed. A published file the web server's user
                 * cannot write (deployed by another user) used to raise PHP
                 * warnings, which printed into the top of every page while
                 * syncStale() ran. An exception instead is caught and logged by
                 * syncStale(), and reported plainly by publish-assets.php.
                 */
                if (!@copy($item->getPathname(), $target)) {
                    throw new \RuntimeException(sprintf(
                        'Cannot write %s (%s). Run `php scripts/publish-assets.php` as the user that owns public/assets.',
                        $target,
                        error_get_last()['message'] ?? 'permission denied'
                    ));
                }

                // Only a nicety (it lets the mtime comparison above settle), so
                // a filesystem that refuses it is not an error.
                @touch($target, filemtime($item->getPathname()));
            }

            $files++;
            $bytes += $item->getSize();
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /* ------------------------------------------------------------------ */
    /* Fingerprinting                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * A short token that changes whenever the published file does.
     *
     * Returns '' when the file is missing, so a broken path stays visibly
     * broken rather than being disguised by a query string.
     */
    public static function fingerprint(string $publishedPath): string
    {
        if (isset(self::$fingerprints[$publishedPath])) {
            return self::$fingerprints[$publishedPath];
        }

        $file = base_path('public/assets/' . ltrim($publishedPath, '/'));

        if (!is_file($file)) {
            return self::$fingerprints[$publishedPath] = '';
        }

        return self::$fingerprints[$publishedPath] = substr(
            base_convert((string) filemtime($file), 10, 36),
            -6
        );
    }

    /** Forget cached fingerprints. Used after a publish within the same process. */
    public static function flush(): void
    {
        self::$fingerprints = [];
    }

    /* ------------------------------------------------------------------ */

    private static function ensureDirectory(string $path): void
    {
        // Silenced, not ignored: the exception below says the same thing once,
        // instead of PHP also printing a raw warning above it.
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(
                "Could not create {$path}. Run `php scripts/publish-assets.php` as the user that owns public/assets."
            );
        }
    }

    public static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
