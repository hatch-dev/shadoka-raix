<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Admin;

use Apps\RaixDirectorScorecardPublic\Services\RecordStore;
use Platform\Core\Auth;
use Platform\Core\Controller;
use Platform\Core\Response;

/**
 * The gate every staff JSON endpoint called from the v13 page shares:
 * B3–B5 (RecordsApiController) and the in-page Requests view
 * (RequestsApiController).
 *
 * The login is this app's admin sign-in — the page's Admin dialog performs it
 * (ScorecardPage::SIGN_IN_JS), as does /admin/login. The session cookie is
 * what is checked.
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

    /** Null when the caller is signed in to THIS app's admin with $capability. */
    protected function gate(string $capability, bool $write = false): ?Response
    {
        if (!$this->sameSite()) {
            return Response::json(['error' => 'Cross-site requests are refused.'], 403);
        }

        if (!Auth::check() || !Auth::canAccessApp($this->app)) {
            return Response::json(['error' => 'Sign in to the admin to continue.'], 401);
        }

        if (Auth::mustChangePassword()) {
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
