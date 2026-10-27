<?php

declare(strict_types=1);

/**
 * Did the daily deletion job (directive C3) actually run?
 *
 *   php scripts/ops/check-retention.php            every app with a retention job
 *   php scripts/ops/check-retention.php --max-hours=26
 *
 * Each app's job stamps settings `app.{slug}.retention_last_run` (UTC) when it
 * finishes. This prints each stamp and exits 1 if any is missing or older than
 * --max-hours (default 26: a daily job plus slack), so a monitor, or a person
 * checking acceptance criterion 9, can tell a schedule that silently stopped.
 *
 * Read-only. CLI only. Prints dates and counts, never a record.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../platform/bootstrap.php';

use Platform\Core\AppRegistry;
use Platform\Core\Settings;

$maxHours = 26;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--max-hours=(\d+)$/', $arg, $m)) {
        $maxHours = max(1, (int) $m[1]);
    }
}

$stale = 0;
$found = 0;

foreach (AppRegistry::all() as $app) {
    if (!is_file($app->path('scripts/retention.php'))) {
        continue;
    }

    $found++;
    $last = Settings::get('app.' . $app->slug . '.retention_last_run');
    $age  = $last === null ? null : (time() - (int) strtotime($last . ' UTC')) / 3600;

    if ($age === null || $age > $maxHours) {
        $stale++;
        echo sprintf("STALE  %-34s last run %s\n", $app->slug, $last === null ? 'never' : sprintf('%s UTC (%.0f hours ago)', $last, $age));
    } else {
        echo sprintf("ok     %-34s last run %s UTC (%.1f hours ago)\n", $app->slug, $last, $age);
    }
}

if ($found === 0) {
    echo "No app has a retention job.\n";
}

exit($stale === 0 ? 0 : 1);
