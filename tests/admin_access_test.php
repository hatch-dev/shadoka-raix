<?php

declare(strict_types=1);

use Platform\Admin\AdminNav;
use Platform\Core\Assert;
use Platform\Core\Auth;
use Platform\Core\AppRegistry;
use Platform\Core\Database;

/**
 * Admin access control.
 *
 * The console is open: the sign-in was removed on 22 Sep 2026, so nothing here
 * keeps anybody out of /admin. Capabilities now shape what the console SHOWS
 * for the identity it runs as, and that is what these check — that a role is
 * offered only what it holds, that the sidebar never advertises a section its
 * identity cannot open, and that every section it does advertise has a route.
 *
 * Creates its own throwaway accounts and removes them again.
 */
return static function (Assert $t): void {
    $created = [];

    $makeUser = static function (string $roleSlug) use (&$created): ?int {
        $role = Database::selectOne('SELECT `id` FROM `admin_roles` WHERE `slug` = ?', [$roleSlug]);

        if ($role === null) {
            return null;
        }

        $id = Database::insert('admin_users', [
            'role_id'       => $role['id'],
            'name'          => 'Test ' . ucfirst($roleSlug),
            'email'         => 'test-' . $roleSlug . '-' . bin2hex(random_bytes(4)) . '@raix.local',
            'password_hash' => password_hash(bin2hex(random_bytes(8)), (string) config('auth.password_algo')),
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $created[] = $id;

        return $id;
    };

    try {
        /* ---- capabilities differ by role ---- */

        $ownerId  = $makeUser('owner');
        $viewerId = $makeUser('viewer');

        $t->true('owner and viewer roles exist', $ownerId !== null && $viewerId !== null);
        if ($ownerId === null || $viewerId === null) {
            return;
        }

        // Act as a given administrator. There is no sign-in to go through any
        // more; actAs is the seam a real gate would write to if one returns.
        $signIn = static function (int $id): void {
            Auth::actAs($id);
        };

        $signIn($viewerId);
        $t->same('the console is acting as the viewer', $viewerId, Auth::id());
        $t->true('viewer holds ds.view', Auth::can('ds.view'));
        $t->true('viewer does NOT hold ds.export', !Auth::can('ds.export'));
        $t->true('viewer does NOT hold ds.submission.delete', !Auth::can('ds.submission.delete'));
        $t->true('viewer does NOT hold ds.instrument.edit', !Auth::can('ds.instrument.edit'));

        $signIn($ownerId);
        $t->true('owner holds ds.export', Auth::can('ds.export'));
        $t->true('owner holds ds.submission.delete', Auth::can('ds.submission.delete'));
        $t->true('owner holds ds.instrument.edit', Auth::can('ds.instrument.edit'));

        /* ---- the sidebar reflects capabilities ---- */

        $nav = AdminNav::forCurrentUser();
        $t->true('owner sees at least one app group', $nav !== []);

        $labels = [];
        foreach ($nav as $group) {
            foreach ($group['sections'] as $section) {
                $labels[] = $section['label'];
            }
        }

        $t->true('owner sees Analytics', in_array('Analytics', $labels, true));
        $t->true('owner sees Research data', in_array('Research data', $labels, true));

        /* ---- every advertised section must have a route ---- */

        $router = new \Platform\Core\Router();
        $platformRoutes = base_path('routes/web.php');
        if (is_file($platformRoutes)) {
            (static function (string $path, \Platform\Core\Router $router): void {
                require $path;
            })($platformRoutes, $router);
        }
        $router->loadApps();
        $paths = array_values($router->names());

        foreach ($nav as $group) {
            foreach ($group['sections'] as $section) {
                $t->true(
                    'sidebar section "' . $section['label'] . '" has a route',
                    in_array('/' . trim($section['path'], '/'), $paths, true),
                    'AdminNav derives the path from the manifest label; a section '
                    . 'without a matching route is a dead link for every admin'
                );
            }
        }

        /* ---- a section the role cannot open is never offered ---- */

        $signIn($viewerId);
        $viewerLabels = [];
        foreach (AdminNav::forCurrentUser() as $group) {
            foreach ($group['sections'] as $section) {
                $viewerLabels[] = $section['label'];
                $t->true(
                    'viewer is only offered sections they hold: ' . $section['label'],
                    $section['capability'] === null || Auth::can($section['capability'])
                );
            }
        }

        $t->true('viewer still sees Analytics', in_array('Analytics', $viewerLabels, true));

        /* ---- signed out sees nothing ---- */

        Auth::logout();
        $t->same('a signed-out visitor gets no sidebar', [], AdminNav::forCurrentUser());
        $t->true('and holds no capability', !Auth::can('ds.view'));

        /* ---- the manifest cannot advertise an unknown capability ---- */

        $declared = AppRegistry::capabilities();
        foreach (AppRegistry::all() as $app) {
            foreach ($app->adminNav as $entry) {
                if (($entry['capability'] ?? null) === null) {
                    continue;
                }
                $t->true(
                    $app->slug . ' declares the capability its "' . $entry['section'] . '" section needs',
                    in_array($entry['capability'], $declared, true),
                    'a nav section gated by a capability absent from the manifest '
                    . 'can never be granted, so the section is invisible to everyone'
                );
            }
        }
    } finally {
        Auth::logout();
        foreach ($created as $id) {
            Database::statement('DELETE FROM `audit_log` WHERE `admin_user_id` = ?', [$id]);
            Database::statement('DELETE FROM `admin_users` WHERE `id` = ?', [$id]);
        }
    }

    $t->same(
        'test accounts cleaned up',
        0,
        (int) Database::scalar("SELECT COUNT(*) FROM `admin_users` WHERE `email` LIKE 'test-%@raix.local'")
    );
};
