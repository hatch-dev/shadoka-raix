<?php
/**
 * Plain functions available everywhere. Loaded by the front controller before
 * anything else, so the autoloader and config are usable from the first line.
 */

declare(strict_types=1);

if (!function_exists('base_path')) {
    /** Absolute path to the platform root, with an optional suffix. */
    function base_path(string $suffix = ''): string
    {
        $root = dirname(__DIR__, 2);
        return $suffix === '' ? $root : $root . '/' . ltrim($suffix, '/');
    }
}

if (!function_exists('env')) {
    /**
     * Read a value from .env, parsed once and cached.
     *
     * Values are strings; "true"/"false"/"null" and bare integers are cast so
     * config files can rely on real types instead of string comparisons.
     */
    function env(string $key, mixed $default = null): mixed
    {
        static $vars = null;

        if ($vars === null) {
            $vars = [];
            $file = base_path('.env');
            if (is_file($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $name  = trim(substr($line, 0, $pos));
                    $value = trim(substr($line, $pos + 1));
                    // Strip one layer of surrounding quotes, if present.
                    if (strlen($value) > 1
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0]
                    ) {
                        $value = substr($value, 1, -1);
                    }
                    $vars[$name] = $value;
                }
            }
        }

        if (!array_key_exists($key, $vars)) {
            return $default;
        }

        $value = $vars[$key];

        return match (strtolower($value)) {
            'true'  => true,
            'false' => false,
            'null', '' => $value === '' ? '' : null,
            default => is_numeric($value) && (string) (int) $value === $value ? (int) $value : $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Read config with dot notation: config('database.host').
     *
     * The first segment names a file in config/. Files are loaded on first use
     * and kept for the request.
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $loaded = [];

        $segments = explode('.', $key);
        $file     = array_shift($segments);

        if (!array_key_exists($file, $loaded)) {
            $path = base_path('config/' . $file . '.php');
            $loaded[$file] = is_file($path) ? require $path : [];
        }

        $value = $loaded[$file];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('e')) {
    /** Escape for HTML output. Quotes included — unlike the old client-side esc(). */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url_path')) {
    /**
     * The path the application is served from, e.g. '' or '/RAIX/public'.
     *
     * Derived from the running request rather than from APP_URL, because a site
     * is routinely reached by more than one hostname — localhost and 127.0.0.1,
     * an IP during testing, with and without www. Building absolute URLs from a
     * single configured host makes every asset cross-origin the moment someone
     * uses a different one, which the browser then refuses to load.
     *
     * Falls back to APP_URL's path on the CLI, where there is no request.
     */
    function base_url_path(): string
    {
        static $base = null;

        if ($base !== null) {
            return $base;
        }

        if (PHP_SAPI === 'cli' || !isset($_SERVER['SCRIPT_NAME'])) {
            $path = parse_url((string) config('app.url'), PHP_URL_PATH);

            return $base = rtrim((string) $path, '/');
        }

        $directory = str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME']));
        $directory = ($directory === '/' || $directory === '.') ? '' : rtrim($directory, '/');

        /*
         * When the site is reached without /public — the root .htaccess rewrites
         * /RAIX/... into /RAIX/public/... — SCRIPT_NAME still ends in /public
         * while the browser's URL does not. Generating links from SCRIPT_NAME
         * alone would put /public back into every one of them, and stripping it
         * unconditionally would break a deployment that really is served from
         * public/. So: drop the suffix only when this request did not come
         * through it.
         */
        if (str_ends_with($directory, '/public')) {
            $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

            if (!str_starts_with($requested, $directory . '/') && $requested !== $directory) {
                $directory = substr($directory, 0, -strlen('/public'));

                /*
                 * The rewrite may also hide a mount segment: /RAIX/dev/... is
                 * served from /RAIX/public/..., and SCRIPT_NAME shows only the
                 * second shape. Nothing in the request can distinguish that
                 * segment from a route ('/RAIX/dev' and '/RAIX/assess' look
                 * alike here), so it is configured rather than guessed. Drop it
                 * and every link, redirect and routed path loses /dev.
                 *
                 * Added only when the request came through it: an app the root
                 * .htaccess serves outside the mount (config/apps.php
                 * 'unmounted') routes from /RAIX. url() puts the mount back for
                 * any target that lives under it (see path_is_mounted()).
                 */
                $mount = (string) config('app.mount');
                $under = $directory . '/' . $mount;

                if ($mount !== '' && ($requested === $under || str_starts_with($requested, $under . '/'))) {
                    $directory .= '/' . $mount;
                }
            }
        }

        return $base = $directory;
    }
}

if (!function_exists('asset')) {
    /**
     * Root-relative URL for a published asset, e.g. asset('platform/css/base.css').
     *
     * Carries ?v=<token> derived from the published file's mtime, so a
     * republished file is a different URL and the long cache lifetime in
     * public/.htaccess cannot serve a stale stylesheet. Without this, editing
     * CSS and republishing still shows the old file to anyone who has visited
     * before.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $url  = mount_base_path() . '/assets/' . $path;

        $version = \Platform\Core\Assets::fingerprint($path);

        return $version === '' ? $url : $url . '?v=' . $version;
    }
}

if (!function_exists('absolute_url')) {
    /**
     * A fully-qualified URL on APP_URL's host.
     *
     * For links that leave the request — an email, a sitemap, a canonical tag —
     * where a relative path has nothing to resolve against. The path comes from
     * url(), not from APP_URL: APP_URL names the /dev mount, and an app served
     * outside it would otherwise be sent to its old address.
     */
    function absolute_url(string $path = ''): string
    {
        $parts  = parse_url((string) config('app.url'));
        $origin = isset($parts['host'])
            ? ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : '';

        return $origin . url($path);
    }
}

if (!function_exists('app_asset')) {
    /** URL for an app's published asset, e.g. app_asset('raix-director-scorecard', 'js/assess.js'). */
    function app_asset(string $slug, string $path): string
    {
        return asset('apps/' . $slug . '/' . ltrim($path, '/'));
    }
}

if (!function_exists('app_module')) {
    /**
     * The URL an ES `import` inside a published module resolves to.
     *
     * Deliberately NOT fingerprinted. `import { x } from './api.js'` resolves
     * against the importing script's own URL and drops its query, so the
     * browser asks for a bare path. A `<link rel="modulepreload">` only warms
     * that request if it names the identical URL — adding `?v=` here would
     * fetch the file a second time instead of saving a round trip.
     *
     * Safe because JS is served `Cache-Control: no-cache`: these revalidate.
     */
    function app_module(string $slug, string $path): string
    {
        return mount_base_path() . '/assets/apps/' . $slug . '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Root-relative URL for a path within the application.
     *
     * Relative, not absolute, so the app answers correctly on whatever hostname
     * it was reached by. Use absolute_url() for links that leave the request.
     *
     * The mount is decided by the TARGET path, not by the page doing the
     * linking: an app listed in config/apps.php 'unmounted' is /RAIX/{slug}
     * and /RAIX/admin/{slug} from anywhere, every other app stays under
     * /RAIX/dev (see path_is_mounted()). That is what lets an app move without
     * touching any of the places that link to it.
     */
    function url(string $path = ''): string
    {
        $path  = ltrim($path, '/');
        $base  = base_url_path();
        $mount = (string) config('app.mount');

        if ($mount !== '' && !str_ends_with($base, '/public')) {
            $root = site_root_path();
            $base = path_is_mounted($path, $base !== $root) ? $root . '/' . $mount : $root;
        }

        return $path === '' ? ($base === '' ? '/' : $base) : $base . '/' . $path;
    }
}

if (!function_exists('site_root_path')) {
    /** base_url_path() without the mount — '/RAIX' whichever side of it the request came in on. */
    function site_root_path(): string
    {
        $base  = base_url_path();
        $mount = (string) config('app.mount');

        return $mount !== '' && str_ends_with($base, '/' . $mount)
            ? substr($base, 0, -strlen('/' . $mount))
            : $base;
    }
}

if (!function_exists('mount_base_path')) {
    /**
     * The base under the mount ('/RAIX/dev'), even for a request that came in
     * outside it. Published assets are shared by every app, so they keep ONE
     * address there rather than one per side.
     */
    function mount_base_path(): string
    {
        $base  = base_url_path();
        $mount = (string) config('app.mount');

        return $mount === '' || str_ends_with($base, '/public') ? $base : site_root_path() . '/' . $mount;
    }
}

if (!function_exists('path_is_mounted')) {
    /**
     * Whether an application path lives under the mount.
     *
     *   {slug}/...        the app's own answer (AppRegistry::isMounted)
     *   admin/{slug}/...  the app's console — moves with the app
     *   admin, admin/login|pin|logout|password
     *                     shared by every app, served on BOTH sides, so they
     *                     stay on whichever side this request came in on
     *   anything else     the platform and the default app: under the mount
     */
    function path_is_mounted(string $path, bool $requestMounted): bool
    {
        $segments = explode('/', trim(substr($path, 0, strcspn($path, '?#')), '/'), 3);
        $first    = $segments[0];

        if ($first === 'admin') {
            $second = $segments[1] ?? '';

            return \Platform\Core\AppRegistry::has($second)
                ? \Platform\Core\AppRegistry::isMounted($second)
                : $requestMounted;
        }

        return !\Platform\Core\AppRegistry::has($first) || \Platform\Core\AppRegistry::isMounted($first);
    }
}

if (!function_exists('storage_path')) {
    /** Absolute path inside storage/, which is never web-served. */
    function storage_path(string $suffix = ''): string
    {
        return base_path('storage' . ($suffix === '' ? '' : '/' . ltrim($suffix, '/')));
    }
}

if (!function_exists('logger')) {
    /** Append a line to storage/logs/app.log. Deliberately minimal. */
    function logger(string $message, string $level = 'info'): void
    {
        $line = sprintf("[%s] %s: %s\n", date('c'), strtoupper($level), $message);
        @file_put_contents(storage_path('logs/app.log'), $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('request_origin')) {
    /**
     * scheme://host of the running request, falling back to APP_URL's on the CLI.
     *
     * For the few URLs that must be absolute AND must name the host the visitor
     * actually used — a share card's og:image is fetched by LinkedIn or Slack
     * from whatever link was pasted, and APP_URL here is localhost.
     */
    function request_origin(): string
    {
        if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
            $parts = parse_url((string) config('app.url'));

            return isset($parts['host'])
                ? ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : '';
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
}

if (!function_exists('favicon_data_link')) {
    /**
     * The site icon as one <link>, its SVG inlined as a data: URI.
     *
     * For the supplied scorecard pages: they declare no icon, and their CSP
     * allows images from data: only, so a link to the file could be refused.
     * The icon is platform/assets/img/favicon.svg — Shadoka's, the one
     * shadoka.com serves (the earlier RAI-X mark is kept as favicon-raix.svg).
     * Empty if the file is missing, so a page is never broken by it.
     */
    function favicon_data_link(): string
    {
        $svg = @file_get_contents(base_path('platform/assets/img/favicon.svg'));

        return $svg === false || $svg === ''
            ? ''
            : '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,' . base64_encode($svg) . '">';
    }
}

if (!function_exists('social_meta')) {
    /**
     * The share-card tags (Open Graph + Twitter) for one app's public pages.
     *
     * The banner is a file, not a setting: apps/{slug}/assets/social/banner.jpg
     * (or .jpeg/.png/.webp), published with the app's other assets. An app with
     * no banner of its own falls back to platform/assets/img/social/default.*,
     * and with neither the card is still written, just without an image.
     * Crawlers do not run JavaScript, so this has to be in the served HTML.
     */
    function social_meta(string $slug, ?string $title = null, ?string $description = null): string
    {
        $manifest = \Platform\Core\AppRegistry::has($slug) ? \Platform\Core\AppRegistry::get($slug) : null;

        $title       = $title ?: ($manifest->name ?? (string) config('app.name'));
        $description = $description ?: ($manifest->description ?? '');

        $image = null;
        foreach (['apps/' . $slug . '/social/banner', 'platform/img/social/default'] as $stem) {
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                if (is_file(base_path('public/assets/' . $stem . '.' . $ext))) {
                    $image = $stem . '.' . $ext;
                    break 2;
                }
            }
        }

        $origin = request_origin();
        $page   = $origin . (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? url($slug)), PHP_URL_PATH) ?: url($slug));

        $tags = [
            ['property', 'og:type',        'website'],
            ['property', 'og:site_name',   (string) config('app.name')],
            ['property', 'og:title',       $title],
            ['property', 'og:description', $description],
            ['property', 'og:url',         $page],
            ['name',     'twitter:card',   $image ? 'summary_large_image' : 'summary'],
            ['name',     'twitter:title',  $title],
            ['name',     'twitter:description', $description],
        ];

        if ($image !== null) {
            $src = $origin . asset($image);
            $tags[] = ['property', 'og:image', $src];
            $tags[] = ['property', 'og:image:alt', $title];
            $tags[] = ['name', 'twitter:image', $src];

            $size = @getimagesize(base_path('public/assets/' . $image));
            if ($size) {
                $tags[] = ['property', 'og:image:width',  (string) $size[0]];
                $tags[] = ['property', 'og:image:height', (string) $size[1]];
                $tags[] = ['property', 'og:image:type',   (string) $size['mime']];
            }
        }

        $html = '';
        foreach ($tags as [$attr, $key, $value]) {
            if ($value !== '') {
                $html .= sprintf("<meta %s=\"%s\" content=\"%s\">\n", $attr, e($key), e($value));
            }
        }

        return $html;
    }
}
