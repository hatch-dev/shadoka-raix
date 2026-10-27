<?php

declare(strict_types=1);

use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * PHP and MySQL must agree what time it is.
 *
 * They did not, on the machine this was built on: PHP wrote UTC while NOW()
 * returned the host's local time, 5.5 hours ahead. Every comparison between a
 * stored datetime and NOW() was wrong by that margin — rate-limit rows were
 * purged as "expired" the moment they were written, so the limiter counted to
 * one forever and let everything through.
 *
 * This test exists so that never comes back quietly. It is a platform test
 * because the drift would affect any app, not just one.
 */
return static function (Assert $t): void {
    $mysqlNow = (string) Database::scalar('SELECT NOW()');
    $drift    = abs(strtotime($mysqlNow) - time());

    $t->true(
        'PHP and MySQL agree on the current time (within 2s)',
        $drift <= 2,
        sprintf(
            "PHP %s / MySQL %s — %d seconds apart (%.1f hours).\n"
            . "       Anything comparing a stored datetime to NOW() is wrong by that much.",
            date('Y-m-d H:i:s'),
            $mysqlNow,
            $drift,
            $drift / 3600
        )
    );

    // A datetime written by PHP must read back as being in the past, not the
    // future — this is the exact comparison the rate limiter and the lockout
    // both depend on.
    $writtenByPhp = date('Y-m-d H:i:s', time() - 5);
    $isPast       = (int) Database::scalar('SELECT ? < NOW()', [$writtenByPhp]);

    $t->same('a PHP timestamp from 5s ago is in the past to MySQL too', 1, $isPast);

    $writtenFuture = date('Y-m-d H:i:s', time() + 3600);
    $isFuture      = (int) Database::scalar('SELECT ? > NOW()', [$writtenFuture]);

    $t->same('a PHP timestamp an hour ahead is in the future to MySQL too', 1, $isFuture);

    $t->same(
        'the session timezone matches the app timezone offset',
        (new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone', 'UTC'))))->format('P'),
        (string) Database::scalar('SELECT @@session.time_zone')
    );
};
