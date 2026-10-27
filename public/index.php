<?php

declare(strict_types=1);

/**
 * Front controller — the single entry point for the whole platform.
 *
 *   1. bootstrap (helpers, autoloader, error handling)
 *   2. load every enabled app's routes under its own slug prefix
 *   3. dispatch
 *
 * Adding a feature means adding a controller and a route inside an app.
 * This file should not need to change again.
 */

/*
 * PHP's built-in server (`php -S 127.0.0.1:8000 -t public public/index.php`)
 * routes EVERY request through this file, including ones for real files, so
 * published assets would come back as HTML 404s. Apache does the equivalent
 * with `RewriteCond %{REQUEST_FILENAME} !-f`; this is that rule for the dev
 * server. Returning false tells it to serve the file itself.
 *
 * No effect under any other SAPI.
 */
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $candidate = realpath(__DIR__ . rawurldecode($requested));

    // realpath() resolves any ../ before the prefix check, so a traversal
    // attempt lands outside __DIR__ and is refused rather than served.
    if ($candidate !== false
        && is_file($candidate)
        && str_starts_with($candidate, realpath(__DIR__) . DIRECTORY_SEPARATOR)
    ) {
        return false;
    }
}

require __DIR__ . '/../platform/bootstrap.php';

use Platform\Core\AppRegistry;
use Platform\Core\Assets;
use Platform\Core\MountGuard;
use Platform\Core\Request;
use Platform\Core\Response;
use Platform\Core\Router;

$request = Request::capture();

try {
    /*
     * In development, republish any app asset whose source is newer. Source
     * lives in apps/{slug}/assets and public/ holds a copy, so without this an
     * edited stylesheet silently does nothing until publish-assets.php is run —
     * which is a confusing way to lose ten minutes. No-op unless APP_DEBUG.
     */
    Assets::syncStale();

    $router = new Router();

    /* Platform-level routes, then every app's. */
    $platformRoutes = base_path('routes/web.php');
    if (is_file($platformRoutes)) {
        (static function (string $path, Router $router): void {
            require $path;
        })($platformRoutes, $router);
    }

    $router->loadApps();

    /*
     * Which side of the /dev mount each app lives on is config/apps.php
     * 'unmounted' and nothing else — the root .htaccess sends app paths here
     * on both sides. A request on the wrong side is redirected to the right
     * one, so no app ever answers at two addresses.
     */
    $response = MountGuard::redirect($request);

    if ($response === null) {
        /*
         * The default app answers at the site root, so '/' and '/assess' reach it
         * without the slug. An explicitly slugged URL still works, which keeps
         * links stable if the default app ever changes.
         */
        $response = $router->dispatch($request);

        $default = AppRegistry::default();
        if ($response === null && $default !== null && $request->firstSegment() !== $default->slug) {
            $response = $router->dispatch(
                $request->withPath($default->slug . $request->path)
            );
        }
    }

    if ($response === null) {
        $response = $request->expectsJson()
            ? Response::json(['success' => false, 'message' => 'Not found.'], 404)
            : Response::make(render_not_found($request), 404);
    }

    $response->send();
} catch (Throwable $e) {
    logger($e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'error');

    $debug = (bool) config('app.debug');

    $response = $request->expectsJson()
        ? Response::json([
            'success' => false,
            'message' => $debug ? $e->getMessage() : 'Server error.',
        ], 500)
        : Response::make(
            $debug
                ? '<pre>' . e($e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>'
                : 'Server error.',
            500
        );

    $response->send();
}

/** A minimal 404 that still tells you which apps are mounted. */
function render_not_found(Request $request): string
{
    $apps = '';
    foreach (AppRegistry::all() as $app) {
        $apps .= sprintf(
            '<li><a href="%s">%s</a> <code>/%s</code></li>',
            e(url($app->slug)),
            e($app->name),
            e($app->slug)
        );
    }

    return sprintf(
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Not found · %s</title>'
        . '<style>body{background:#060D18;color:#E8F0FA;font:16px/1.6 system-ui,sans-serif;padding:60px 24px;max-width:640px;margin:0 auto}'
        . 'h1{font-size:22px;margin:0 0 8px}code{color:#7AB8E8}a{color:#5A9FDA}ul{padding-left:18px}</style></head>'
        . '<body><h1>Not found</h1><p style="color:#A0B4CA">No route matches <code>%s</code>.</p>'
        . '<p style="color:#A0B4CA">Apps mounted here:</p><ul>%s</ul></body></html>',
        e(config('app.name')),
        e($request->path),
        $apps
    );
}
