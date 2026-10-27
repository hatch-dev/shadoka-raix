<?php

declare(strict_types=1);

use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\RateLimiter;
use Platform\Core\Settings;

/**
 * The layer that replaces the client-side PIN.
 *
 * Creates its own throwaway admin account and removes it again; it never
 * touches the real one, and never truncates a table.
 *
 * This suite was deleted on 22 Sep 2026 when the sign-in was removed and
 * rewritten when it came back. The assertions worth keeping honest are the ones
 * about what the form REFUSES — a wrong password, an unknown address given the
 * same message so it cannot be used to enumerate accounts, and a locked account
 * — because those are the ones that still pass if the check is quietly gone.
 */
return static function (Assert $t): void {
    $email    = 'test-' . bin2hex(random_bytes(6)) . '@raix.local';
    $password = bin2hex(random_bytes(12));
    $userId   = null;
    $bucket   = 'test.' . bin2hex(random_bytes(4));
    $ipHash   = hash('sha256', 'test-client');

    $role = Database::selectOne('SELECT `id`, `capabilities` FROM `admin_roles` WHERE `slug` = ?', ['viewer']);
    $t->true('the viewer role was seeded', $role !== null);
    if ($role === null) {
        return;
    }

    try {
        $userId = Database::insert('admin_users', [
            'role_id'       => $role['id'],
            'name'          => 'Throwaway Test Admin',
            'email'         => $email,
            'password_hash' => password_hash($password, (string) config('auth.password_algo')),
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        /* ---------- nobody is signed in to begin with ---------- */

        Auth::logout();
        $t->true('no session means no user', !Auth::check());
        $t->same('and no identity to fall back to', null, Auth::id());
        $t->true('nor any capability', !Auth::can('ds.view'));

        /* ---------- sign-in ---------- */

        $t->same('a wrong password is refused', 'Those details were not recognised.',
            Auth::attempt($email, 'not-the-password', $ipHash));

        $t->same('an unknown account gives the SAME message (no enumeration oracle)',
            'Those details were not recognised.',
            Auth::attempt('nobody-' . bin2hex(random_bytes(4)) . '@raix.local', 'whatever', $ipHash));

        $t->true('a refused attempt leaves nobody signed in', !Auth::check());

        $t->same('the right password is accepted', null, Auth::attempt($email, $password, $ipHash));
        $t->true('the session now has a user', Auth::check());
        $t->same('and it is the right one', $userId, Auth::id());

        /* ---------- capabilities come from the role ---------- */

        $t->true('the viewer holds ds.view', Auth::can('ds.view'));
        $t->true('the viewer does NOT hold ds.export', !Auth::can('ds.export'));
        $t->true('nor an invented capability', !Auth::can('ds.' . bin2hex(random_bytes(3))));

        /* ---------- audit trail ---------- */

        Auth::audit('test.action', null, 'test', 'subject-1', ['k' => 'v'], $ipHash);

        $entry = Database::selectOne(
            'SELECT * FROM `audit_log` WHERE `action` = ? ORDER BY `id` DESC LIMIT 1',
            ['test.action']
        );

        $t->true('an audit row was written', $entry !== null);
        $t->same('attributed to the signed-in admin', $userId, (int) $entry['admin_user_id']);
        $t->same('with the name captured at write time', 'Throwaway Test Admin', $entry['actor_label']);

        /* ---------- lockout after repeated failures ---------- */

        Auth::logout();
        $max = (int) config('auth.max_attempts', 5);

        for ($i = 0; $i < $max; $i++) {
            Auth::attempt($email, 'wrong-again', $ipHash);
        }

        $t->same(
            'the account locks after the configured number of failures',
            'Too many attempts. Try again later.',
            Auth::attempt($email, $password, $ipHash)
        );
        $t->true('and the right password does NOT get in while locked', !Auth::check());

        // Clear the lock the way a successful sign-in would.
        Database::statement(
            'UPDATE `admin_users` SET `failed_attempts` = 0, `locked_until` = NULL WHERE `id` = ?',
            [$userId]
        );

        $t->same('once unlocked the right password works again', null,
            Auth::attempt($email, $password, $ipHash));

        /* ---------- each app's own admin password ---------- */

        /*
         * The public Admin dialog of each app. The password is compared HERE
         * and not in page JavaScript, and each app has its own password and its
         * own administrator — so the assertions that matter are the ones about
         * what one app's password must NOT open.
         *
         * The passwords live in the settings table, so the suite sets its own
         * and then puts back EXACTLY the rows it found (see the finally below)
         * — this database is shared with whoever is using the console.
         */
        Auth::logout();
        $ds = 'raix-director-scorecard';
        $es = 'enterprise-ai-scorecard';

        $pinRowsBefore = [];
        foreach ([$ds, $es] as $slug) {
            $pinRowsBefore[$slug] = Database::selectOne('SELECT * FROM `settings` WHERE `key` = ?', [Auth::pinSetting($slug)]);
        }

        $dsAdmin = Auth::appAdmin($ds);
        $esAdmin = Auth::appAdmin($es);
        $t->true('the Director Scorecard has its own administrator', $dsAdmin !== null);
        $t->true('the Enterprise Scorecard has its own administrator', $esAdmin !== null);
        $t->true('and they are different accounts', $dsAdmin !== null && $esAdmin !== null && $dsAdmin['id'] !== $esAdmin['id']);

        Settings::put(Auth::pinSetting($ds), '');
        $t->same(
            'an app with no password refuses sign-in outright',
            'Admin sign-in is not set up for this app.',
            Auth::attemptPin($ds, 'anything-' . bin2hex(random_bytes(3)), $ipHash)
        );

        $dsPin = 'Ds' . bin2hex(random_bytes(4));
        $esPin = 'Es' . bin2hex(random_bytes(4));
        Auth::setPin($ds, $dsPin);
        Auth::setPin($es, $esPin);

        $t->true('setting a password switches it on', Auth::pinEnabled($ds) && Auth::pinEnabled($es));
        $t->true(
            'the password is stored hashed, never as typed',
            !str_contains((string) Settings::get(Auth::pinSetting($ds)), $dsPin)
        );

        $t->same('a wrong password is refused', 'Wrong password.', Auth::attemptPin($ds, $dsPin . '-wrong', $ipHash));
        $t->true('and a wrong password creates no session', !Auth::check());
        $t->same('an empty password is refused', 'Wrong password.', Auth::attemptPin($ds, '', $ipHash));

        // The heart of it: one app's password does not open the other.
        $t->same('the Enterprise password does NOT open the Director admin', 'Wrong password.', Auth::attemptPin($ds, $esPin, $ipHash));
        $t->same('the Director password does NOT open the Enterprise admin', 'Wrong password.', Auth::attemptPin($es, $dsPin, $ipHash));
        $t->true('and neither cross attempt signed anybody in', !Auth::check());

        // Case-sensitive: the single-file builds folded case, this does not.
        // $dsPin mixes cases ("Ds…"), so both foldings differ from it.
        $t->same('the password in lower case is refused', 'Wrong password.', Auth::attemptPin($ds, strtolower($dsPin), $ipHash));
        $t->same('the password in upper case is refused', 'Wrong password.', Auth::attemptPin($ds, strtoupper($dsPin), $ipHash));
        $t->true('and neither signed anybody in', !Auth::check());

        // Surrounding spaces are still forgiven — a pasted password often has one.
        foreach ([
            'the password, exactly as set'  => $dsPin,
            'the password with stray space' => '  ' . $dsPin . '  ',
        ] as $label => $candidate) {
            Auth::logout();
            $t->same($label . ' is accepted', null, Auth::attemptPin($ds, $candidate, $ipHash));
        }

        $t->same('the Director password signs in as the Director administrator', (int) $dsAdmin['id'], Auth::id());
        $t->true('who can open the Director console', Auth::canAccessApp(AppRegistry::get($ds)));
        $t->true('but NOT the Enterprise console', !Auth::canAccessApp(AppRegistry::get($es)));
        $t->true('holds ds.view', Auth::can('ds.view'));
        $t->true('and not es.view', !Auth::can('es.view'));

        Auth::logout();
        $t->same('the Enterprise password opens the Enterprise admin', null, Auth::attemptPin($es, $esPin, $ipHash));
        $t->same('as the Enterprise administrator', (int) $esAdmin['id'], Auth::id());
        $t->true('who cannot open the Director console', !Auth::canAccessApp(AppRegistry::get($ds)));

        // Changing one app's password takes effect at once and leaves the other alone.
        Auth::logout();
        Auth::setPin($ds, 'Other' . bin2hex(random_bytes(4)));
        $t->same('once changed, the old Director password is refused', 'Wrong password.', Auth::attemptPin($ds, $dsPin, $ipHash));
        $t->same('and the Enterprise password still works', null, Auth::attemptPin($es, $esPin, $ipHash));
        $t->true('checkPin agrees', Auth::checkPin($es, $esPin) && !Auth::checkPin($es, $dsPin));

        /* ---------- strength rules for a new app password ---------- */

        $t->same('a strong password has no problems', [], Auth::passwordProblems('Strong#Pass9'));
        $t->same('exactly the minimum length passes', [], Auth::passwordProblems('ABCDEF12'));
        $t->same('lower case and symbols are allowed, not required', [], Auth::passwordProblems('RAIX2026'));
        $t->same('one short of the minimum fails on length only', ['length'], array_keys(Auth::passwordProblems('ABCDEF1')));
        $t->same('surrounding spaces do not count towards length', ['length'], array_keys(Auth::passwordProblems('  ABCDEF1  ')));
        $t->true('over the maximum fails', isset(Auth::passwordProblems('A1' . str_repeat('x', Auth::PIN_MAX_LENGTH))['length']));
        $t->same('no uppercase', ['upper'], array_keys(Auth::passwordProblems('strong#pass9')));
        $t->same('no number', ['digit'], array_keys(Auth::passwordProblems('Strong#Pass')));
        $t->same('every rule is reported at once', ['length', 'upper', 'digit'], array_keys(Auth::passwordProblems('abc')));

        /* ---------- password change ---------- */

        Auth::logout();
        Auth::attempt($email, $password, $ipHash);

        $newPassword = bin2hex(random_bytes(12));
        Auth::setPassword($userId, $newPassword);

        Auth::logout();
        $t->same('the old password no longer works', 'Those details were not recognised.',
            Auth::attempt($email, $password, $ipHash));
        $t->same('the new password works', null, Auth::attempt($email, $newPassword, $ipHash));
        $t->true('and the forced-change flag is cleared', !Auth::mustChangePassword());

        /* ---------- logout ---------- */

        Auth::logout();
        $t->true('logout clears the session', !Auth::check());
        $t->same('and no capability survives it', false, Auth::can('ds.view'));

        /* ---------- rate limiter ---------- */

        for ($i = 1; $i <= 3; $i++) {
            $over = RateLimiter::tooManyAttempts($bucket, $ipHash, 3, 60);
            $t->true("hit {$i} of 3 is allowed", !$over);
        }
        $t->true('the fourth hit is over the limit', RateLimiter::tooManyAttempts($bucket, $ipHash, 3, 60));
        $t->true('the window reports time remaining', RateLimiter::availableIn($bucket, $ipHash) > 0);

        RateLimiter::clear($bucket, $ipHash);
        $t->same('clearing resets the counter', 0, RateLimiter::attempts($bucket, $ipHash));
    } finally {
        Auth::logout();
        RateLimiter::clear($bucket, $ipHash);

        // Put each app's real password back as it was — value, author and time.
        foreach ($pinRowsBefore ?? [] as $slug => $row) {
            if ($row === null) {
                Settings::forget(Auth::pinSetting($slug));
            } else {
                Database::statement(
                    'UPDATE `settings` SET `value` = ?, `updated_by` = ?, `updated_at` = ? WHERE `key` = ?',
                    [$row['value'], $row['updated_by'], $row['updated_at'], Auth::pinSetting($slug)]
                );
            }
        }
        Settings::flush();

        if ($userId !== null) {
            // audit_log.admin_user_id is ON DELETE SET NULL, so the log entry
            // outlives the row it points at — which is the point of
            // actor_label. Remove this suite's entries explicitly.
            Database::statement('DELETE FROM `audit_log` WHERE `action` = ?', ['test.action']);
            Database::statement('DELETE FROM `admin_users` WHERE `id` = ?', [$userId]);
        }
    }

    $t->same(
        'the throwaway administrator was removed',
        0,
        (int) Database::scalar('SELECT COUNT(*) FROM `admin_users` WHERE `email` = ?', [$email])
    );
};
