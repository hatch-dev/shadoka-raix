<?php
/**
 * Admin authentication.
 *
 * There is no public account system by design: only staff have accounts, and
 * directors reach their own scorecard by an unguessable token instead.
 */
declare(strict_types=1);

return [
    'session_name'     => 'raix_admin',
    'session_lifetime' => 60 * 60 * 8,   // 8 hours
    'password_algo'    => PASSWORD_DEFAULT,

    // Failed-login throttling, enforced in Platform\Core\RateLimiter.
    'max_attempts'     => 5,
    'lockout_seconds'  => 900,

    /*
     * Each app's admin password, behind its public site's Admin dialog.
     *
     * Carried over from the single-file builds, which compared a PIN in their
     * own JavaScript. It is NOT configured here. Every app has its own password
     * (a hash, Auth::pinSetting($slug)) and its own administrator account
     * (Auth::adminSetting($slug), created by the seeder), both only in the
     * `settings` table. Change one at that app's Admin › Settings; reset a
     * forgotten one with scripts/admin-password.php. There is no .env value.
     *
     * Understand what each is: ONE secret per app, shared by everyone who has
     * it, opening that app's whole research dataset. It cannot be revoked for
     * one person, and the audit log can only record "someone with the
     * password" — which is why a sign-in borrows the app's named account.
     */

    // Wrong PINs allowed from one address before it is turned away, and for
    // how long. Tighter than the password limit: a PIN is short and shared.
    'pin_max_attempts'    => 10,
    'pin_lockout_seconds' => 900,

    // Platform capabilities, added to the catalogue the seeder builds roles
    // from. admin.settings (the Admin PIN screen) ends up with the owner only:
    // the researcher and viewer roles keep just admin.access, .view, .export.
    'base_capabilities' => ['admin.access', 'admin.settings'],

    // Roles are seeded rows; this is the fallback used before any role exists.
    'superuser_role' => 'owner',
];
