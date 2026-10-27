<?php

declare(strict_types=1);

use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * Apps served outside the /dev mount (config/apps.php 'unmounted').
 *
 * That list is the only place an app's side is written: url() builds links
 * from it and MountGuard redirects wrong-side requests from it, while the root
 * .htaccess sends app-shaped paths to PHP on both sides without knowing any
 * app. This suite fails when a link to an app would carry the wrong address,
 * when the .htaccess stops handing app paths to PHP, and when a moved app's
 * address would be claimed by a real folder beside it.
 *
 * It runs on the CLI, which counts as a request under the mount; the other
 * side is exercised through path_is_mounted() directly. The redirects
 * themselves need Apache and are checked over HTTP.
 */
return static function (Assert $t): void {
    $mount = (string) config('app.mount');
    $root  = site_root_path();
    $under = $root . ($mount === '' ? '' : '/' . $mount);

    $htaccess = (string) file_get_contents(base_path('.htaccess'));
    $deny     = strpos($htaccess, 'RewriteRule ^(platform|apps|config');
    $outside  = strpos($htaccess, 'RewriteRule ^[a-z0-9]+(-[a-z0-9]+)*(/.*)?$ public/index.php [QSA,L]');

    $t->true('the root .htaccess hands app-shaped paths outside the mount to PHP', $outside !== false);
    $t->true('the source-folder denial runs before that rule', $deny !== false && $outside !== false && $deny < $outside);
    $t->true('no app is named in the root .htaccess (the config is the only list)',
        array_filter(AppRegistry::slugs(), static fn (string $slug): bool => str_contains($htaccess, $slug)) === []);

    foreach (AppRegistry::all() as $slug => $app) {
        $base = AppRegistry::isMounted($slug) ? $under : $root;

        $t->same("{$slug}: public address", $base . '/' . $slug, url($slug));
        $t->same("{$slug}: API address", $base . '/' . $slug . '/api', url($slug . '/api'));
        $t->same("{$slug}: console address", $base . '/admin/' . $slug . '/analytics', url('admin/' . $slug . '/analytics'));
        $t->true("{$slug}: absolute address carries the same path", str_ends_with(absolute_url($slug), $base . '/' . $slug));

        // On Windows a folder beside RAIX/.htaccess with the same name (any
        // case) would be served instead of the app, or shadow its rewrite.
        $t->true("{$slug}: no real folder at /{$slug}", !is_dir(base_path($slug)));
    }

    $t->true('no real folder at /admin', !is_dir(base_path('admin')));

    $default = AppRegistry::default();
    if ($default !== null) {
        $t->true('the default app stays under the mount', AppRegistry::isMounted($default->slug));
    }

    // Shared endpoints follow the side the request came in on.
    foreach (['admin', 'admin/login', 'admin/pin', 'admin/logout', 'admin/password'] as $shared) {
        $t->same("{$shared}: stays under the mount for a mounted page", true, path_is_mounted($shared, true));
        $t->same("{$shared}: stays outside for a page served outside", false, path_is_mounted($shared, false));
    }

    // Everything that is not an app belongs to the platform and the default app.
    $t->same('the platform root is under the mount', true, path_is_mounted('', false));
    $t->same('a query string does not change the decision', path_is_mounted('admin/login', false), path_is_mounted('admin/login?next=/admin', false));
    $t->true('published assets keep one address, under the mount', str_starts_with(asset('platform/css/base.css'), $under . '/assets/'));
};
