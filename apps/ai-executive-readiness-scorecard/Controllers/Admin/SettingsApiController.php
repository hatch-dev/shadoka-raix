<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Controllers\Admin;

use Platform\Core\Auth;
use Platform\Core\RateLimiter;
use Platform\Core\Response;
use Platform\Core\Settings;

/**
 * This app's admin password, changed from the Settings view INSIDE the v4
 * page. The same rules as the console's Settings screen
 * (Platform\Admin\Controllers\SettingsController) — current password asked for
 * and throttled, the platform's strength rules, typed twice — only the shape
 * differs (JSON in and out).
 *
 *   GET  /api/admin/settings            → {pin_enabled, changed_at, min, max, rules:[{key,label,pattern}]}
 *   POST /api/admin/settings/password   {current, pin, pin_confirm} → {} | 422 {errors:{field: message}}
 *
 * The password is never echoed back, logged or put in the audit meta.
 */
final class SettingsApiController extends AdminApiController
{
    /** GET /api/admin/settings */
    public function show(): Response
    {
        if ($denied = $this->gate('admin.settings', false, true)) {
            return $denied;
        }

        $slug  = $this->app->slug;
        $row   = Settings::row(Auth::pinSetting($slug));
        $rules = [];

        foreach (Auth::PASSWORD_RULES as $key => $rule) {
            $rules[] = ['key' => $key, 'label' => $rule['label'], 'pattern' => $rule['pattern']];
        }

        return Response::json([
            'pin_enabled' => Auth::pinEnabled($slug),
            'changed_at'  => isset($row['updated_at']) ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $row['updated_at'])) : null,
            'min'         => Auth::PIN_MIN_LENGTH,
            'max'         => Auth::PIN_MAX_LENGTH,
            'rules'       => $rules,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** POST /api/admin/settings/password */
    public function updatePassword(): Response
    {
        if ($denied = $this->gate('admin.settings', true, true)) {
            return $denied;
        }

        $slug    = $this->app->slug;
        $current = (string) ($this->request->body['current'] ?? '');
        $pin     = (string) ($this->request->body['pin'] ?? '');
        $confirm = (string) ($this->request->body['pin_confirm'] ?? '');
        $ipHash  = $this->request->ipHash();

        // The current-password box is a place to guess the password from, so
        // it shares the console Settings screen's throttle.
        if (RateLimiter::attempts('admin.settings.current', $ipHash) >= 10) {
            return Response::json(['errors' => ['form' => 'Too many attempts. Try again in a few minutes.']], 429);
        }

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

        if (trim($confirm) === '') {
            $errors['pin_confirm'] = 'Type the new password again.';
        } elseif (Auth::normalisePin($pin) !== Auth::normalisePin($confirm)) {
            $errors['pin_confirm'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            // Only a WRONG current password counts towards the limit.
            if (isset($errors['current']) && trim($current) !== '') {
                RateLimiter::tooManyAttempts('admin.settings.current', $ipHash, 10, 900);
            }

            return Response::json(['errors' => $errors], 422);
        }

        RateLimiter::clear('admin.settings.current', $ipHash);

        Auth::setPin($slug, $pin, Auth::id());
        Auth::audit('admin.pin_changed', $slug, 'setting', Auth::pinSetting($slug), [], $ipHash);

        return Response::json(new \stdClass());
    }
}
