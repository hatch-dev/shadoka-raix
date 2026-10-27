<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Fixed-window rate limiting for endpoints with no account behind them.
 *
 * Deliberately simple: a counter per (bucket, identifier) that expires. A fixed
 * window allows a burst across a boundary, which is fine for what this guards —
 * stopping a script filling the research dataset, not defending a login.
 *
 * Admin sign-ins were throttled separately, on the admin_users row, because
 * there is an account to attach the count to.
 */
final class RateLimiter
{
    private function __construct()
    {
    }

    /**
     * Count a hit and report whether the caller is now over the limit.
     *
     * @param string $bucket     what is being limited, e.g. 'submission.start'
     * @param string $identifier a HASH — Request::ipHash(), never a raw address
     */
    public static function tooManyAttempts(string $bucket, string $identifier, int $max, int $windowSeconds): bool
    {
        self::purgeExpired();

        $now     = time();
        $expires = date('Y-m-d H:i:s', $now + $windowSeconds);

        // One statement, so two concurrent requests cannot both read 0 and
        // both write 1. The UNIQUE key makes the upsert atomic; the CASE
        // resets the counter when the previous window has already lapsed.
        Database::statement(
            'INSERT INTO `rate_limits` (`bucket`, `identifier`, `hits`, `expires_at`)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                `hits` = CASE WHEN `expires_at` < NOW() THEN 1 ELSE `hits` + 1 END,
                `expires_at` = CASE WHEN `expires_at` < NOW() THEN VALUES(`expires_at`) ELSE `expires_at` END',
            [$bucket, $identifier, $expires]
        );

        $hits = (int) Database::scalar(
            'SELECT `hits` FROM `rate_limits` WHERE `bucket` = ? AND `identifier` = ?',
            [$bucket, $identifier]
        );

        return $hits > $max;
    }

    /** Hits recorded in the current window, without counting another. */
    public static function attempts(string $bucket, string $identifier): int
    {
        return (int) Database::scalar(
            'SELECT `hits` FROM `rate_limits`
              WHERE `bucket` = ? AND `identifier` = ? AND `expires_at` >= NOW()',
            [$bucket, $identifier]
        );
    }

    /** Seconds until the window resets, or 0 when it already has. */
    public static function availableIn(string $bucket, string $identifier): int
    {
        $expires = Database::scalar(
            'SELECT `expires_at` FROM `rate_limits` WHERE `bucket` = ? AND `identifier` = ?',
            [$bucket, $identifier]
        );

        if ($expires === null) {
            return 0;
        }

        return max(0, strtotime((string) $expires) - time());
    }

    public static function clear(string $bucket, string $identifier): void
    {
        Database::statement(
            'DELETE FROM `rate_limits` WHERE `bucket` = ? AND `identifier` = ?',
            [$bucket, $identifier]
        );
    }

    /**
     * Drop lapsed rows. Called on write rather than by a scheduled task, so the
     * table cannot grow unbounded on a deployment with no cron.
     */
    private static function purgeExpired(): void
    {
        // Cheap enough to run on every write given the expires_at index, and a
        // 1-in-50 sample would leave rows around on a low-traffic install.
        Database::statement('DELETE FROM `rate_limits` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    }
}
