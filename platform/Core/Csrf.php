<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * CSRF tokens for state-changing admin requests.
 *
 * One token per session, compared with hash_equals. Public submission
 * endpoints are deliberately NOT covered: they are anonymous, unauthenticated
 * and carry no ambient authority, so a CSRF token there would protect nothing
 * while forcing every respondent to hold a session cookie.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf';
    public const FIELD = '_token';
    public const HEADER = 'HTTP_X_CSRF_TOKEN';

    private function __construct()
    {
    }

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** Hidden input for a form. */
    public static function field(): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', self::FIELD, e(self::token()));
    }

    /** Does this request carry a valid token? Safe methods always pass. */
    public static function verify(Request $request): bool
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $provided = $request->input(self::FIELD);

        if (!is_string($provided) || $provided === '') {
            $provided = $request->server[self::HEADER] ?? '';
        }

        return is_string($provided)
            && $provided !== ''
            && hash_equals($expected, $provided);
    }
}
