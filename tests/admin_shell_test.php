<?php

declare(strict_types=1);

use Platform\Admin\AdminNav;
use Platform\Core\Assert;
use Platform\Core\AppRegistry;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\View;

/**
 * Each app's admin is its own console.
 *
 * Renders the admin shell as each app's screens do and checks it never leads
 * into another app: only that app's sections in the bar, that app's name in
 * the brand, and an Exit that carries that app's slug back to the sign-out.
 *
 * The bug this guards: every app's Admin button, Exit and logo led to the
 * default app (the Director Scorecard), and both apps' sections shared one bar.
 */
return static function (Assert $t): void {
    $owner = Database::selectOne(
        'SELECT u.`id` FROM `admin_users` u JOIN `admin_roles` r ON r.`id` = u.`role_id`
          WHERE r.`slug` = \'owner\' AND u.`is_active` = 1 ORDER BY u.`id` LIMIT 1'
    );

    $t->true('an active owner account exists to render as', $owner !== null);
    if ($owner === null) {
        return;
    }

    Auth::actAs((int) $owner['id']);

    try {
        $apps = AppRegistry::all();

        foreach ($apps as $app) {
            $html = (new View($app))
                ->share('slug', $app->slug)
                ->share('admin', Auth::user())
                ->share('nav', AdminNav::forCurrentUser())
                ->share('csrf', 'test-token')
                ->share('currentPath', '/admin/' . $app->slug . '/analytics')
                ->render('layouts/admin');

            // Its own FIRST section, whatever that is: most apps open on
            // Analytics, the v13 public Director Scorecard on Requests.
            $first = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) ($app->adminNav[0]['section'] ?? '')))), '-');
            $t->true($app->slug . ': the bar links to its own first section (' . $first . ')', $first !== '' && str_contains($html, 'admin/' . $app->slug . '/' . $first));

            foreach ($apps as $other) {
                if ($other->slug === $app->slug) {
                    continue;
                }

                $t->true(
                    $app->slug . ': the bar carries none of ' . $other->slug . "'s sections",
                    !str_contains($html, 'admin/' . $other->slug . '/')
                );
            }

            // The console keeps the app's own footer, not none and not another app's.
            $t->true(
                $app->slug . ': the page carries this app\'s footer',
                str_contains($html, '<footer>') && str_contains($html, 'research edition')
            );

            $t->true(
                $app->slug . ': Exit returns to this app',
                str_contains($html, 'name="app" value="' . $app->slug . '"')
            );
            $t->true(
                $app->slug . ': the brand links to this app\'s public site',
                (bool) preg_match('~class="logo" href="[^"]*/' . preg_quote($app->slug, '~') . '"~', $html)
            );
        }
    } finally {
        Auth::logout();
    }
};
