<?php

declare(strict_types=1);

/**
 * Director Scorecard (Public) — the daily retention job (directive v13, C3 + C6).
 *
 *   php apps/raix-director-scorecard-public/scripts/retention.php
 *   php apps/raix-director-scorecard-public/scripts/retention.php --dry-run   logs only: report, change nothing
 *
 * Deletes every record completed more than 24 months ago, whole, then trims
 * every log in config/logging.php to 30 days and redacts email addresses in
 * what it keeps. Schedule it daily (cron: `15 3 * * *`). The public page also
 * runs it at most once a day, so a missed schedule does not break the promise
 * in the notice.
 *
 * CLI only. Prints counts, never a record or a log line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../platform/bootstrap.php';

use Apps\RaixDirectorScorecardPublic\Services\RecordStore;
use Apps\RaixDirectorScorecardPublic\Services\Retention;
use Platform\Core\AppRegistry;
use Platform\Core\LogRetention;

if (in_array('--dry-run', $argv, true)) {
    $logs = LogRetention::run(null, true);
    echo "dry run: logs only, nothing changed\n";
} else {
    $result = Retention::run(AppRegistry::get('raix-director-scorecard-public'));
    $logs   = $result['logs'];

    echo sprintf("%s  deleted %d record(s) completed before %s UTC\n",
        gmdate('Y-m-d H:i:s'),
        $result['records'],
        RecordStore::retentionCutoff()
    );
}

foreach ($logs as $log) {
    echo sprintf("  %-11s %s  (%d line(s) past %d days, %d address(es) redacted)\n",
        $log['action'], $log['file'], $log['dropped'], (int) config('logging.days'), $log['redacted']);
}
