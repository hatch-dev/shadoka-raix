<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Controllers\Admin;

use Apps\AiSovereigntyScorecard\Services\RecordStore;
use Platform\Core\Auth;
use Platform\Core\Controller;
use Platform\Core\Response;

/**
 * The gate every admin JSON endpoint called from the v6 page shares.
 *
 * "Shadoka staff" is this app's admin: its own PIN sign-in (/admin/pin or
 * /admin/login), checked here through the session cookie the page sends with
 * credentials: 'include'. There are no separate staff accounts.
 *
 * Not AdminController::guard(): that demands a CSRF token, which the supplied
 * page cannot send. Cross-site use is closed instead by the session cookie
 * being SameSite=Lax (a cross-site fetch does not carry it), by refusing any
 * request whose Origin or Sec-Fetch-Site says it came from another site, and —
 * for writes — by requiring a JSON body, which a cross-site form cannot send.
 */
abstract class AdminApiController extends Controller
{
    protected function store(): RecordStore
    {
        return new RecordStore($this->app);
    }

    /**
     * Null when the caller is signed in to THIS app's admin with $capability.
     *
     * $changingPassword lets the one endpoint that changes the password through
     * while a password change is being forced — otherwise it could never be done.
     */
    protected function gate(string $capability, bool $write = false, bool $changingPassword = false): ?Response
    {
        if (!$this->sameSite()) {
            return Response::json(['error' => 'Cross-site requests are refused.'], 403);
        }

        if (!Auth::check() || !Auth::canAccessApp($this->app)) {
            return Response::json(['error' => 'Sign in to the admin to continue.'], 401);
        }

        if (!$changingPassword && Auth::mustChangePassword()) {
            return Response::json(['error' => 'Change your password to continue.'], 403);
        }

        if (!Auth::can($capability)) {
            return Response::json(['error' => 'You do not have access to that.'], 403);
        }

        if ($write && !str_contains(strtolower((string) ($this->request->server['CONTENT_TYPE'] ?? '')), 'application/json')) {
            return Response::json(['error' => 'Send JSON.'], 415);
        }

        return null;
    }

    private function sameSite(): bool
    {
        $site = strtolower((string) ($this->request->server['HTTP_SEC_FETCH_SITE'] ?? ''));

        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }

        $origin = (string) ($this->request->server['HTTP_ORIGIN'] ?? '');

        if ($origin === '') {
            return true;
        }

        $host = strtolower((string) ($this->request->server['HTTP_HOST'] ?? ''));
        $from = parse_url($origin);

        if (!is_array($from) || !isset($from['host'])) {
            return false;
        }

        $fromHost = strtolower($from['host']) . (isset($from['port']) ? ':' . $from['port'] : '');

        return $fromHost === $host;
    }
}
