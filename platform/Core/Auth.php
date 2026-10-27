<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Admin authentication and capability checks.
 *
 * This is what replaced the legacy build's `ADMIN_PIN` constant — a value in
 * client JavaScript, compared after `toUpperCase()`, that gated the entire
 * research dataset. The code that shipped it called itself demo-grade; it was.
 *
 * The sign-in was removed on 22 Sep 2026 and restored on the same day. While it
 * was gone the console answered without a credential, and `user()` fell back to
 * a default administrator so that every screen still had a name for the audit
 * log. That fallback is deliberately NOT here: an identity comes from the
 * session or there is none, because a fallback identity is the same thing as no
 * gate at all.
 *
 * The PIN survives as attemptPin(), and since 25 Sep 2026 there is one PER APP:
 * each app has its own password (a hash in the `settings` table) and its own
 * administrator account, changed from that app's console at Settings. None is
 * ever written into source or comments — not even into a comment explaining
 * that it used to live in one.
 *
 * Only staff have accounts. Directors never authenticate: a submission is
 * reached by its own unguessable token.
 */
final class Auth
{
    private const SESSION_KEY = 'admin_user_id';

    /** Shortest and longest app password a new one may be. */
    public const PIN_MIN_LENGTH = 8;
    public const PIN_MAX_LENGTH = 64;

    /**
     * What a NEW app password must contain, beyond its length.
     *
     * The one list: the server enforces it (passwordProblems), and the Settings
     * page renders its live checklist from it, so the two cannot disagree. The
     * patterns are written to mean the same in PHP and in JavaScript.
     */
    public const PASSWORD_RULES = [
        'upper' => ['label' => 'An uppercase letter (A–Z)', 'pattern' => '[A-Z]'],
        'digit' => ['label' => 'A number (0–9)',            'pattern' => '[0-9]'],
    ];

    /** @var array<string, mixed>|null request-scoped cache of the signed-in admin */
    private static ?array $user = null;

    private function __construct()
    {
    }

    /* ------------------------------------------------------------------ */
    /* Signing in                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Sign in with an email address and password.
     *
     * @return string|null an error to show, or null on success
     */
    public static function attempt(string $email, string $password, ?string $ipHash = null): ?string
    {
        $email = trim(strtolower($email));

        $user = Database::selectOne(
            'SELECT * FROM `admin_users` WHERE LOWER(`email`) = ? LIMIT 1',
            [$email]
        );

        // Always spend the cost of a hash, even with no matching row, so the
        // response time does not reveal whether the account exists.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok   = password_verify($password, $hash);

        // The same message for an unknown address and a wrong password: telling
        // them apart turns this form into a list of which addresses are real.
        if ($user === null) {
            return 'Those details were not recognised.';
        }

        if ((int) $user['is_active'] !== 1) {
            return 'That account is not active.';
        }

        if (self::isLocked($user)) {
            return 'Too many attempts. Try again later.';
        }

        if (!$ok) {
            self::recordFailure($user);

            return 'Those details were not recognised.';
        }

        self::establish($user, $ipHash);

        return null;
    }

    /**
     * Sign in to ONE app's admin with that app's password, from its public
     * site's Admin dialog.
     *
     * Every app has its own password and its own administrator account (both
     * in the database, see pinSetting() / adminSetting()). The password signs
     * in AS that app's administrator, whose role holds only that app's
     * capabilities — so the Director Scorecard's password cannot open the
     * Enterprise Scorecard's console, and the other way round.
     *
     * The single-file builds compared `value.trim().toUpperCase()` in the
     * browser, so their PIN was case-insensitive. Since 25 Sep 2026 the
     * password is CASE-SENSITIVE (only surrounding spaces are ignored): folding
     * case halves the guesses per letter, and it is a password, not a code. The
     * comparison lives here, where the secret stays on the server and a wrong
     * guess can be counted.
     *
     * @return string|null an error to show, or null on success
     */
    public static function attemptPin(string $slug, string $pin, ?string $ipHash = null): ?string
    {
        $hash = (string) Settings::get(self::pinSetting($slug), '');

        if ($hash === '') {
            return 'Admin sign-in is not set up for this app.';
        }

        // password_verify is constant-time, so the reply cannot be timed
        // character by character.
        $normalised = self::normalisePin($pin);

        if ($normalised === '' || !password_verify($normalised, $hash)) {
            return 'Wrong password.';
        }

        $user = self::appAdmin($slug);

        // The password was right but there is nobody to be. A deployment
        // mistake (the seeder has not run), not a wrong guess.
        if ($user === null) {
            return 'Admin sign-in is not configured: this app has no administrator.';
        }

        if ((int) $user['is_active'] !== 1) {
            return 'That account is not active.';
        }

        if (self::isLocked($user)) {
            return 'Too many attempts. Try again later.';
        }

        self::establish($user, $ipHash);

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Per-app admin password                                              */
    /* ------------------------------------------------------------------ */

    /** Settings key holding an app's admin password hash. */
    public static function pinSetting(string $slug): string
    {
        return 'app.' . $slug . '.pin_hash';
    }

    /** Settings key holding the admin_users.id an app's password signs in as. */
    public static function adminSetting(string $slug): string
    {
        return 'app.' . $slug . '.admin_user_id';
    }

    /** Does this app have a password set? The dialog asks, to know which field to offer. */
    public static function pinEnabled(string $slug): bool
    {
        return (string) Settings::get(self::pinSetting($slug), '') !== '';
    }

    /**
     * Set an app's password. Stored only as a hash, so it can never be read
     * back out of the database.
     */
    public static function setPin(string $slug, string $pin, ?int $byUserId = null): void
    {
        Settings::put(
            self::pinSetting($slug),
            password_hash(self::normalisePin($pin), (string) config('auth.password_algo')),
            $byUserId
        );
    }

    /**
     * Why a proposed new password is not strong enough, one message per rule
     * it fails, keyed by rule ('length' first). Empty means it is acceptable.
     *
     * @return array<string, string>
     */
    public static function passwordProblems(string $pin): array
    {
        $pin      = self::normalisePin($pin);
        $problems = [];
        $length   = mb_strlen($pin);

        if ($length < self::PIN_MIN_LENGTH) {
            $problems['length'] = 'At least ' . self::PIN_MIN_LENGTH . ' characters.';
        } elseif ($length > self::PIN_MAX_LENGTH) {
            $problems['length'] = 'No more than ' . self::PIN_MAX_LENGTH . ' characters.';
        }

        foreach (self::PASSWORD_RULES as $key => $rule) {
            if (!preg_match('/' . $rule['pattern'] . '/u', $pin)) {
                $problems[$key] = $rule['label'] . '.';
            }
        }

        return $problems;
    }

    /** Does $pin match this app's current password? */
    public static function checkPin(string $slug, string $pin): bool
    {
        $hash = (string) Settings::get(self::pinSetting($slug), '');

        return $hash !== '' && password_verify(self::normalisePin($pin), $hash);
    }

    /**
     * The administrator account that belongs to this app, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function appAdmin(string $slug): ?array
    {
        $id = (int) Settings::get(self::adminSetting($slug), '0');

        return $id > 0
            ? Database::selectOne('SELECT * FROM `admin_users` WHERE `id` = ? LIMIT 1', [$id])
            : null;
    }

    /**
     * May the signed-in administrator open this app's console at all?
     *
     * True when their role holds any capability the app declares — the same
     * test the sidebar uses. An app admin holds only their own app's.
     */
    public static function canAccessApp(AppManifest $app): bool
    {
        foreach ($app->capabilities as $capability) {
            if (self::can($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Surrounding spaces only — a pasted password often carries one. Case is
     * kept: the password is case-sensitive.
     *
     * Hashes stored before 25 Sep 2026 were made from the UPPER-CASE form, so
     * until an app's password is set again it only matches typed in capitals.
     */
    public static function normalisePin(string $pin): string
    {
        return trim($pin);
    }

    /** Start the session for a verified administrator. */
    private static function establish(array $user, ?string $ipHash): void
    {
        self::recordSuccess($user, $ipHash);

        // New session id, so a token fixed before sign-in is not the one that
        // ends up holding an administrator.
        Session::regenerate();
        Session::put(self::SESSION_KEY, (int) $user['id']);
        self::$user = null;
    }

    private static function isLocked(array $user): bool
    {
        return $user['locked_until'] !== null
            && strtotime((string) $user['locked_until']) > time();
    }

    private static function recordFailure(array $user): void
    {
        $attempts = (int) $user['failed_attempts'] + 1;
        $max      = (int) config('auth.max_attempts', 5);

        $lockedUntil = $attempts >= $max
            ? date('Y-m-d H:i:s', time() + (int) config('auth.lockout_seconds', 900))
            : null;

        Database::statement(
            'UPDATE `admin_users` SET `failed_attempts` = ?, `locked_until` = ? WHERE `id` = ?',
            [$attempts, $lockedUntil, $user['id']]
        );
    }

    private static function recordSuccess(array $user, ?string $ipHash): void
    {
        Database::statement(
            'UPDATE `admin_users`
                SET `failed_attempts` = 0, `locked_until` = NULL,
                    `last_login_at` = ?, `last_login_ip_hash` = ?
              WHERE `id` = ?',
            [date('Y-m-d H:i:s'), $ipHash, $user['id']]
        );
    }

    public static function logout(): void
    {
        Session::flush();
        Session::regenerate();
        self::$user = null;
    }

    /* ------------------------------------------------------------------ */
    /* Who is signed in                                                    */
    /* ------------------------------------------------------------------ */

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * The signed-in administrator, or null.
     *
     * Session only. There is deliberately no fallback to "the first active
     * admin" — that is what the console did while the sign-in was removed, and
     * it makes every guard below decorative.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        $user = Database::selectOne(
            'SELECT u.*, r.`slug` AS role_slug, r.`name` AS role_name, r.`capabilities`
               FROM `admin_users` u
               JOIN `admin_roles` r ON r.`id` = u.`role_id`
              WHERE u.`id` = ? AND u.`is_active` = 1
              LIMIT 1',
            [$id]
        );

        // Deleted or deactivated since they signed in: drop the stale id rather
        // than serve a ghost.
        if ($user === null) {
            Session::forget(self::SESSION_KEY);

            return null;
        }

        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    /**
     * Does the signed-in administrator hold this capability?
     *
     * Capabilities are declared by apps in their manifests and stored on the
     * role, so which sections the console offers is a row edit, not a deploy.
     */
    public static function can(string $capability): bool
    {
        $user = self::user();

        if ($user === null) {
            return false;
        }

        $capabilities = json_decode((string) $user['capabilities'], true);

        if (!is_array($capabilities)) {
            return false;
        }

        return in_array($capability, $capabilities, true);
    }

    /**
     * Act as a specific administrator without going through the form.
     *
     * Tests use this. Nothing in the console does — sign-in goes through
     * attempt() or attemptPin(), which is where the throttling and the audit
     * entry live. Passing null signs out.
     */
    public static function actAs(?int $userId): void
    {
        if ($userId === null) {
            Session::forget(self::SESSION_KEY);
        } else {
            Session::put(self::SESSION_KEY, $userId);
        }

        self::$user = null;
    }

    /* ------------------------------------------------------------------ */
    /* Passwords                                                           */
    /* ------------------------------------------------------------------ */

    /** Is this administrator still on the password the seeder issued? */
    public static function mustChangePassword(): bool
    {
        $user = self::user();

        return $user !== null && (int) $user['must_change_password'] === 1;
    }

    /** Set a new password and clear the forced-change flag. */
    public static function setPassword(int $userId, string $password): void
    {
        Database::statement(
            'UPDATE `admin_users`
                SET `password_hash` = ?, `must_change_password` = 0,
                    `failed_attempts` = 0, `locked_until` = NULL, `updated_at` = ?
              WHERE `id` = ?',
            [
                password_hash($password, (string) config('auth.password_algo')),
                date('Y-m-d H:i:s'),
                $userId,
            ]
        );

        self::$user = null;
    }

    /* ------------------------------------------------------------------ */

    /** Write an audit row attributed to the current admin (or to the CLI). */
    public static function audit(
        string $action,
        ?string $app = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $meta = [],
        ?string $ipHash = null,
    ): void {
        $user = self::user();

        Database::insert('audit_log', [
            'admin_user_id' => $user === null ? null : (int) $user['id'],
            // Captured at write time so the log survives the user being deleted.
            'actor_label'   => $user === null ? 'system' : (string) $user['name'],
            'app'           => $app,
            'action'        => $action,
            'subject_type'  => $subjectType,
            'subject_id'    => $subjectId,
            'meta'          => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR),
            'ip_hash'       => $ipHash,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /** Forget the cached user. Tests and long-running CLI scripts need this. */
    public static function flush(): void
    {
        self::$user = null;
    }
}
