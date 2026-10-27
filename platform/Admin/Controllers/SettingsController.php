<?php

declare(strict_types=1);

namespace Platform\Admin\Controllers;

use Platform\Admin\AdminController;
use Platform\Core\Auth;
use Platform\Core\RateLimiter;
use Platform\Core\Response;
use Platform\Core\Session;
use Platform\Core\Settings;

/**
 * /admin/{slug}/settings — ONE app's admin password.
 *
 * Every app has its own password and its own administrator account, both in
 * the database. This screen is mounted inside each app's console (routes/web.php
 * registers it under every app's prefix), so $this->app is always the app whose
 * password is being changed, and the guard has already refused anyone signed
 * in to a different app.
 *
 * Changing it asks for the current one: a console left open should not be a
 * way to lock its owner out. A forgotten password is reset on the server with
 * scripts/admin-password.php — there is no email reset.
 */
final class SettingsController extends AdminController
{
    private const NOTICE_KEY = 'settings_notice';

    /** GET /admin/{slug}/settings */
    public function show(): Response
    {
        if ($denied = $this->guard('admin.settings')) {
            return $denied;
        }

        return $this->page();
    }

    /** POST /admin/{slug}/settings/password */
    public function updatePassword(): Response
    {
        if ($denied = $this->guard('admin.settings')) {
            return $denied;
        }

        $slug    = $this->app->slug;
        $current = (string) ($this->request->input('current') ?? '');
        $pin     = (string) ($this->request->input('pin') ?? '');
        $confirm = (string) ($this->request->input('pin_confirm') ?? '');
        $ipHash  = $this->request->ipHash();

        /*
         * The current-password box is a place to guess the password from, so
         * it is throttled like the sign-in is. Checked before anything else.
         */
        if (RateLimiter::attempts('admin.settings.current', $ipHash) >= 10) {
            return $this->page(['form' => 'Too many attempts. Try again in a few minutes.'], 429);
        }

        // Every problem at once, each under its own field, so one submit shows
        // everything that needs fixing instead of one message at a time.
        $errors = [];

        if (Auth::pinEnabled($slug)) {
            if (trim($current) === '') {
                $errors['current'] = 'Enter the current password.';
            } elseif (!Auth::checkPin($slug, $current)) {
                $errors['current'] = 'The current password is not right.';
            }
        }

        if (trim($pin) === '') {
            $errors['pin'] = 'Enter a new password.';
        } elseif ($problems = Auth::passwordProblems($pin)) {
            $errors['pin'] = 'Not strong enough. Still needed: ' . implode(' ', $problems);
        } elseif (Auth::checkPin($slug, $pin)) {
            $errors['pin'] = 'The new password must be different from the current one.';
        }

        // Compared the way sign-in compares: case-sensitive, stray spaces ignored.
        if (trim($confirm) === '') {
            $errors['pin_confirm'] = 'Type the new password again.';
        } elseif (Auth::normalisePin($pin) !== Auth::normalisePin($confirm)) {
            $errors['pin_confirm'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            // Only a WRONG current password counts towards the limit — a weak
            // new password is a typing problem, not a guess.
            if (isset($errors['current']) && trim($current) !== '') {
                RateLimiter::tooManyAttempts('admin.settings.current', $ipHash, 10, 900);
            }

            return $this->page($errors, 422);
        }

        RateLimiter::clear('admin.settings.current', $ipHash);

        Auth::setPin($slug, $pin, Auth::id());
        // The password itself is never put in the audit meta.
        $this->audit('admin.pin_changed', 'setting', Auth::pinSetting($slug));

        Session::put(self::NOTICE_KEY, 'Password changed. ' . $this->app->name . ' admin now opens with the new one.');

        return $this->redirect('admin/' . $slug . '/settings');
    }

    /** @param array<string, string> $errors keyed by field: current, pin, pin_confirm, or form */
    private function page(array $errors = [], int $status = 200): Response
    {
        $notice = Session::get(self::NOTICE_KEY);
        Session::forget(self::NOTICE_KEY);

        $slug = $this->app->slug;
        $row  = Settings::row(Auth::pinSetting($slug));

        return $this->view()->response('settings', [
            'title'      => 'Settings · ' . $this->app->name . ' admin',
            'appName'    => $this->app->name,
            'appSlug'    => $slug,
            'pinEnabled' => Auth::pinEnabled($slug),
            'changedAt'  => $row['updated_at'] ?? null,
            'minLength'  => Auth::PIN_MIN_LENGTH,
            'maxLength'  => Auth::PIN_MAX_LENGTH,
            'rules'      => Auth::PASSWORD_RULES,
            'notice'     => is_string($notice) ? $notice : null,
            'errors'     => $errors,
        ], $status);
    }
}
