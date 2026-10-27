<?php

declare(strict_types=1);

namespace Platform\Admin;

use Platform\Core\Auth;
use Platform\Core\Controller;
use Platform\Core\Csrf;
use Platform\Core\Response;
use Platform\Core\View;

/**
 * Base for every admin screen, platform or app.
 *
 * Each action opens with `if ($denied = $this->guard('ds.view')) return $denied;`
 * rather than relying on route metadata. It is one line, it is visible at the
 * top of the method, and it cannot be lost by a route file being reorganised.
 *
 * Since the sign-in screen was removed on 22 Sep 2026 this guard no longer asks
 * WHO is calling — there is nothing to ask. It resolves the identity the
 * console runs as, checks that identity's capability so a screen its role does
 * not hold is not served, and verifies CSRF on writes. None of that keeps a
 * stranger out of /admin; see the note at the top of Platform\Core\Auth.
 */
abstract class AdminController extends Controller
{
    /**
     * Refuse the request unless the console has an identity holding $capability.
     *
     * Returns a Response to send, or null to carry on.
     */
    protected function guard(?string $capability = null): ?Response
    {
        if (!Auth::check()) {
            // ?next= carries where they were going, so signing in lands there
            // rather than on the dashboard. AuthController refuses anything
            // outside /admin, so it cannot be pointed off-site.
            return $this->request->expectsJson()
                ? $this->fail('Sign in to continue.', 401)
                : $this->redirect('admin/login?next=' . urlencode($this->request->path));
        }

        /*
         * Still on the password the seeder printed. Everything except the
         * change-password screen itself waits until that is done.
         */
        if (Auth::mustChangePassword() && !str_starts_with($this->request->path, '/admin/password')) {
            return $this->request->expectsJson()
                ? $this->fail('Change your password to continue.', 403)
                : $this->redirect('admin/password');
        }

        /*
         * Signed in, but to a DIFFERENT app. Each app has its own administrator
         * and password, so this is not "forbidden" — it is "not signed in
         * here": send them to this app's sign-in, which asks for its password.
         */
        if ($this->app !== null && !Auth::canAccessApp($this->app)) {
            return $this->request->expectsJson()
                ? $this->fail('Sign in to ' . $this->app->name . ' to continue.', 401)
                : $this->redirect('admin/login?next=' . urlencode($this->request->path));
        }

        if ($capability !== null && !Auth::can($capability)) {
            return $this->request->expectsJson()
                ? $this->fail('You do not have access to that.', 403)
                : $this->view()->response('errors/forbidden', [
                    'title'      => 'Not permitted · RAI-X admin',
                    'capability' => $capability,
                ], 403);
        }

        // Every state-changing admin request carries a token, so another site
        // cannot post a delete or a purge through a signed-in browser.
        if (!Csrf::verify($this->request)) {
            return $this->request->expectsJson()
                ? $this->fail('Your session expired. Sign in and try again.', 419)
                : $this->redirect('admin/login');
        }

        return null;
    }

    /**
     * A view preloaded with everything the admin shell needs: the identity the
     * console is acting as, and which sections that identity may see across
     * every enabled app.
     */
    protected function view(): View
    {
        $view = new View($this->app);

        return $view
            ->share('slug', $this->app?->slug)
            ->share('admin', Auth::user())
            ->share('nav', AdminNav::forCurrentUser())
            ->share('csrf', Csrf::token())
            ->share('currentPath', $this->request->path);
    }

    /** Record an admin action against the current app. */
    protected function audit(string $action, ?string $subjectType = null, ?string $subjectId = null, array $meta = []): void
    {
        Auth::audit($action, $this->app?->slug, $subjectType, $subjectId, $meta, $this->request->ipHash());
    }
}
