<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Keeps each app on its own side of the /dev mount.
 *
 * config/apps.php 'unmounted' is the ONLY place an app's side is written.
 * The root .htaccess sends every app-shaped path to the front controller on
 * both sides, and this class redirects a request that arrived on the wrong
 * side to the right one:
 *
 *   an unmounted app reached through /dev   -> 301 (308 for a POST) to /RAIX/...
 *   anything else reached outside the mount -> 302 (307 for a POST) into /RAIX/dev/...
 *
 * Every one of these is sent `Cache-Control: no-store`. A browser otherwise
 * keeps a 301 FOREVER and never asks again: move an app out, move it back, and
 * everyone who followed the first redirect is sent to the outside address by
 * their own browser, which the server can no longer correct. With no-store the
 * browser asks each time, so the list can change in either direction.
 */
final class MountGuard
{
    private function __construct()
    {
    }

    public static function redirect(Request $request): ?Response
    {
        $mount = (string) config('app.mount');
        $base  = base_url_path();

        // Unset, or reached via /public: there is no mount to be on either side of.
        if ($mount === '' || str_ends_with($base, '/public')) {
            return null;
        }

        $root    = site_root_path();
        $mounted = $base !== $root;

        if (path_is_mounted(ltrim($request->path, '/'), $mounted) === $mounted) {
            return null;
        }

        // Rebuilt from the RAW request path so nothing is decoded and re-encoded
        // on the way: only the mount segment is dropped or added.
        $uri    = (string) ($request->server['REQUEST_URI'] ?? '/');
        $raw    = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $rest   = substr($raw, strlen($base));
        $qs     = parse_url($uri, PHP_URL_QUERY);
        $target = ($mounted ? $root : $root . '/' . $mount) . $rest . ($qs ? '?' . $qs : '');

        // A moved app's old address is permanent; the way back in is not, since
        // that app may move out later. 308/307 keep a POST a POST.
        $safe   = in_array($request->method, ['GET', 'HEAD'], true);
        $status = $mounted ? ($safe ? 301 : 308) : ($safe ? 302 : 307);

        return Response::redirect($target, $status)->withHeader('Cache-Control', 'no-store');
    }
}
