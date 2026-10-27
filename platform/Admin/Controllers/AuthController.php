<?php

declare(strict_types=1);

namespace Platform\Admin\Controllers;

use Platform\Admin\AdminController;
use Platform\Admin\AdminNav;
use Platform\Core\AppRegistry;
use Platform\Core\Auth;
use Platform\Core\Csrf;
use Platform\Core\RateLimiter;
use Platform\Core\Response;
use Platform\Core\View;
use Platform\Validation\Validator;

/**
 * Sign-in, sign-out, and the forced first password change.
 *
 * This is what replaced the legacy build's `ADMIN_PIN` — a constant in client
 * JavaScript, compared after `toUpperCase()`, that gated the whole research
 * dataset. The code that shipped it called itself demo-grade, accurately.
 *
 * The shared PIN survives as pin() below, verified server-side. Its value is a
 * hash in the `settings` table, managed by SettingsController, and is never
 * written into source or comments.
 */
final class AuthController extends AdminController
{
    /** A view without the shell: the login page has no nav and no session yet. */
    private function bare(): View
    {
        return (new View())->share('csrf', Csrf::token());
    }

    /*
     * The public nav opens a sign-in DIALOG, as the single-file build's Admin
     * gate did, and a modal wants its error back inline rather than a whole new
     * document — so the replies below are shaped by $request->expectsJson().
     *
     * Only the shape changes. The CSRF check, the per-IP throttle, the account
     * lockout and the audit entry are the same code on both paths, so the
     * dialog cannot become a softer way in than the page.
     */

    /** GET /admin/login */
    public function showLogin(): Response
    {
        /*
         * The public site's Admin dialog asks here for a CSRF token when it
         * opens. Doing it this way, rather than printing a token into every
         * public page, means a director who never touches Admin is never
         * issued a session cookie — this questionnaire is anonymous, and that
         * should cost nothing to keep.
         */
        $target = $this->targetApp();

        /*
         * Signed in counts only for the app being asked about. Each app has its
         * own administrator, so the Director Scorecard's session is NOT signed
         * in to the Enterprise Scorecard — pressing Admin there must ask for
         * that app's password, not bounce into a "Not permitted" page.
         */
        $signedIn = Auth::check() && ($target === null || Auth::canAccessApp($target));

        if ($this->request->expectsJson()) {
            // Same {success, data} envelope the app's own API uses, so the one
            // client in api.js needs no special case for these two routes.
            return Response::json(['success' => true, 'data' => $signedIn
                // safeNext(), not landing(): someone already signed in who
                // presses Admin on the Enterprise Scorecard must land in THAT
                // app's admin. landing() is the default app's — the Director
                // Scorecard's — and sent every app's Admin button there.
                ? ['authenticated' => true, 'redirect' => url($this->safeNext())]
                : [
                    'authenticated' => false,
                    'csrf'          => Csrf::token(),
                    // Whether THIS app has a password to offer the field for.
                    // Never the password itself.
                    'pin_enabled'   => $target !== null && Auth::pinEnabled($target->slug),
                ]]);
        }

        if ($signedIn) {
            return $this->redirect($this->safeNext());
        }

        return $this->bare()->response('auth/login', $this->loginPage());
    }

    /**
     * The app a sign-in is for, from `app` or from where it is going next.
     *
     * Both apps' Admin dialogs send next=/admin/{slug}/…, and the guard's
     * redirect carries the same, so no client had to change to say which
     * app's password is being typed. Only a slug the registry knows counts.
     */
    private function targetApp(): ?\Platform\Core\AppManifest
    {
        $slug = (string) ($this->request->input('app') ?? '');

        if ($slug === '' && preg_match('#^/admin/([a-z0-9-]+)(?:/|$)#', (string) ($this->request->input('next') ?? ''), $m)) {
            $slug = $m[1];
        }

        return $slug !== '' && AppRegistry::has($slug) ? AppRegistry::get($slug) : null;
    }

    /** @return array<string, mixed> what the full-page sign-in needs */
    private function loginPage(array $extra = []): array
    {
        $target = $this->targetApp();

        return $extra + [
            'title'      => 'Sign in · ' . ($target === null ? 'RAI-X' : $target->name) . ' admin',
            'next'       => (string) ($this->request->input('next') ?? ''),
            'appName'    => $target?->name,
            'appSlug'    => $target?->slug,
            'pinEnabled' => $target !== null && Auth::pinEnabled($target->slug),
        ];
    }

    /** POST /admin/login */
    public function login(): Response
    {
        if (!Csrf::verify($this->request)) {
            return $this->loginError('Your session expired. Try again.');
        }

        // Throttled by client as well as by account: without this, one attacker
        // can spray many accounts, each staying under its own attempt limit.
        if (RateLimiter::tooManyAttempts('admin.login', $this->request->ipHash(), 20, 900)) {
            return $this->loginError('Too many attempts from here. Try again shortly.');
        }

        $validator = Validator::make($this->request->body, [
            'email'    => 'required|string|max:191',
            'password' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return $this->loginError('Enter your email address and password.');
        }

        $credentials = $validator->validated();

        $failure = Auth::attempt(
            $credentials['email'],
            $credentials['password'],
            $this->request->ipHash()
        );

        if ($failure !== null) {
            return $this->loginError($failure, $credentials['email']);
        }

        RateLimiter::clear('admin.login', $this->request->ipHash());
        Auth::audit('admin.login', null, 'admin_user', (string) Auth::id(), [], $this->request->ipHash());

        return $this->finish(Auth::mustChangePassword() ? 'admin/password' : $this->safeNext());
    }

    /**
     * POST /admin/pin — one app's password, from that app's Admin dialog.
     *
     * Same gate as the email path, not a bypass: CSRF is verified, guesses
     * are throttled per address, and the audit entry records the app and that
     * this session arrived by the app password rather than as a named person.
     */
    public function pin(): Response
    {
        if (!Csrf::verify($this->request)) {
            return $this->loginError('Your session expired. Try again.');
        }

        $max    = (int) config('auth.pin_max_attempts', 10);
        $window = (int) config('auth.pin_lockout_seconds', 900);

        if (RateLimiter::tooManyAttempts('admin.pin', $this->request->ipHash(), $max, $window)) {
            return $this->loginError('Too many attempts from here. Try again shortly.');
        }

        $pin = (string) ($this->request->input('pin') ?? '');

        $app = $this->targetApp();

        if ($app === null) {
            return $this->loginError('Open Admin from the app you want to manage.');
        }

        if ($pin === '' || mb_strlen($pin) > 100) {
            return $this->loginError('Enter the password.');
        }

        $failure = Auth::attemptPin($app->slug, $pin, $this->request->ipHash());

        if ($failure !== null) {
            return $this->loginError($failure);
        }

        RateLimiter::clear('admin.pin', $this->request->ipHash());

        Auth::audit(
            'admin.login.pin',
            $app->slug,
            'admin_user',
            (string) Auth::id(),
            // Recorded so an app-password session is never mistaken for a
            // person signing in with their own account.
            ['via' => 'app_password'],
            $this->request->ipHash()
        );

        /*
         * safeNext(), not landing() — the same as the password path above.
         *
         * landing() sends every PIN sign-in to the first section of the DEFAULT
         * app, which was right while one app was mounted and wrong the moment a
         * second was: signing in from the Enterprise Scorecard's Admin dialog
         * arrived at the Director Scorecard's analytics, looking at a different
         * instrument's dataset with no indication anything had happened.
         *
         * safeNext() falls back to landing() when no `next` was sent, so a
         * dialog that does not ask for one behaves exactly as before. What it
         * accepts is validated there: inside /admin, by exact segment, with
         * scheme-relative URLs refused.
         */
        return $this->finish(Auth::mustChangePassword() ? 'admin/password' : $this->safeNext());
    }

    /** POST /admin/logout */
    public function logout(): Response
    {
        if (Auth::check()) {
            Auth::audit('admin.logout', null, 'admin_user', (string) Auth::id(), [], $this->request->ipHash());
        }

        // Flushes and regenerates the session, so nothing of this one survives
        // for the next visitor on a shared machine.
        Auth::logout();

        /*
         * Out to the public site, not back to the sign-in page. This is the
         * reference's Exit — `exitAdmin()` dropped the admin flag and went
         * straight to the hero — and landing on a login form implies the next
         * thing to do is sign in again, which is the opposite of what Exit is
         * for.
         *
         * `?signed_out=1` is what makes the public page say so; the reference
         * toasted "Signed out of admin" for the same reason.
         */
        /*
         * Back to the public site of the app whose console this was — the
         * admin shell posts its slug. Only a slug the registry knows is used,
         * so this cannot be turned into an open redirect; anything else falls
         * back to the default app.
         */
        $slug = (string) ($this->request->input('app') ?? '');
        $app  = $slug !== '' && AppRegistry::has($slug) ? AppRegistry::get($slug) : AppRegistry::default();

        return $this->redirect(
            $app === null ? 'admin/login' : $app->slug . '?signed_out=1'
        );
    }

    /**
     * Where a sign-in with nowhere particular to go should land.
     *
     * The single-file build went straight to Analytics (`go(dest||'analytics')`)
     * — there was no platform dashboard to land on, and there is no reason to
     * make someone who just signed in take another step to reach the screen
     * they came for.
     *
     * The first section of the default app, and specifically the first one THIS
     * person may see: landing an administrator on a page their role denies
     * would be a worse greeting than the dashboard.
     */
    private function landing(): string
    {
        $app = AppRegistry::default();

        if ($app !== null) {
            foreach (AdminNav::forCurrentUser() as $group) {
                if (($group['slug'] ?? null) !== $app->slug) {
                    continue;
                }

                $first = $group['sections'][0]['path'] ?? null;

                if (is_string($first) && $first !== '') {
                    return ltrim($first, '/');
                }
            }
        }

        // No default app, or nothing in it this person can open.
        return 'admin';
    }

    /**
     * Send the signed-in visitor on, as JSON for the dialog or a redirect.
     *
     * The dialog cannot follow a 302 usefully — fetch would swallow it and hand
     * back the destination page as a string — so it is told where to go and
     * navigates itself.
     */
    private function finish(string $destination): Response
    {
        if ($this->request->expectsJson()) {
            return Response::json(['success' => true, 'data' => ['redirect' => url($destination)]]);
        }

        return $this->redirect($destination);
    }

    /**
     * Only ever redirect within this application.
     *
     * ?next= comes from the URL a signed-out admin was trying to reach. Taken
     * at face value it is an open redirect — a link to our login that lands on
     * somebody else's site with our branding still in the address bar.
     */
    private function safeNext(): string
    {
        $next = (string) ($this->request->input('next') ?? '');

        // A scheme-relative "//evil.example" is still absolute to a browser,
        // so it is rejected before anything else looks at the path.
        if ($next === '' || str_starts_with($next, '//')) {
            return $this->landing();
        }

        // Exact-segment match, not a prefix: a plain str_starts_with('/admin')
        // would also accept "/adminfoo", which is a different area entirely.
        if ($next !== '/admin' && !str_starts_with($next, '/admin/')) {
            return $this->landing();
        }

        return ltrim($next, '/');
    }

    private function loginError(string $message, string $email = ''): Response
    {
        if ($this->request->expectsJson()) {
            // Same message as the page shows, and it still says nothing about
            // whether the address is known.
            return Response::json(['success' => false, 'message' => $message], 422);
        }

        return $this->bare()->response('auth/login', $this->loginPage([
            'error' => $message,
            'email' => $email,
        ]), 422);
    }

    /* ------------------------------------------------------------------ */
    /* Forced first password change                                        */
    /* ------------------------------------------------------------------ */

    /** GET /admin/password */
    public function showPassword(): Response
    {
        if (!Auth::check()) {
            return $this->redirect('admin/login');
        }

        return $this->bare()->response('auth/password', [
            'title'  => 'Change password · RAI-X admin',
            'forced' => Auth::mustChangePassword(),
        ]);
    }

    /** POST /admin/password */
    public function updatePassword(): Response
    {
        if (!Auth::check()) {
            return $this->redirect('admin/login');
        }

        if (!Csrf::verify($this->request)) {
            return $this->passwordError('Your session expired. Try again.');
        }

        $validator = Validator::make($this->request->body, [
            'password'         => 'required|string|min:12|max:200',
            'password_confirm' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->passwordError((string) $validator->firstError());
        }

        $input = $validator->validated();

        if ($input['password'] !== $input['password_confirm']) {
            return $this->passwordError('Those two passwords do not match.');
        }

        $user = Auth::user();

        // A "new" password identical to the old one leaves a forced change
        // satisfied without anything having changed.
        if (password_verify($input['password'], (string) $user['password_hash'])) {
            return $this->passwordError('The new password must be different from the current one.');
        }

        Auth::setPassword((int) $user['id'], $input['password']);
        Auth::audit('admin.password_changed', null, 'admin_user', (string) $user['id'], [], $this->request->ipHash());

        return $this->redirect($this->landing());
    }

    private function passwordError(string $message): Response
    {
        return $this->bare()->response('auth/password', [
            'title'  => 'Change password · RAI-X admin',
            'forced' => Auth::mustChangePassword(),
            'error'  => $message,
        ], 422);
    }
}
