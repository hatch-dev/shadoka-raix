<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * A thin session wrapper, so Auth and Csrf share one set of cookie settings.
 *
 * Started lazily: a public page that never touches the session never sends a
 * cookie, which keeps the anonymous scorecard genuinely anonymous.
 */
final class Session
{
    private static bool $started = false;

    private function __construct()
    {
    }

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $secure = ($_SERVER['HTTPS'] ?? '') !== ''
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        session_name((string) config('auth.session_name', 'raix_admin'));
        session_set_cookie_params([
            'lifetime' => (int) config('auth.session_lifetime', 28800),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        self::start();

        return isset($_SESSION[$key]);
    }

    /** Wipe the session and issue a new id. Used on login and logout. */
    public static function regenerate(): void
    {
        self::start();

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flush(): void
    {
        self::start();
        $_SESSION = [];
    }
}
