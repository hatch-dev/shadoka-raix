<?php

declare(strict_types=1);

/**
 * Human Edge Scorecard — the daily retention job (privacy directive, C3 + C6).
 *
 *   php apps/human-edge-scorecard/scripts/retention.php
 *   php apps/human-edge-scorecard/scripts/retention.php --dry-run   report, change nothing
 *
 * Deletes every he_records row completed more than 24 months ago, whole, then
 * trims every log in config/logging.php to 30 days and redacts email
 * addresses in what it keeps. Schedule it daily (cron: `40 3 * * *`). The page
 * and its endpoints also run it at most once a day, so a missed schedule
 * does not break the promises in the notice.
 *
 * CLI only. Prints counts, never a record or a log line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../platform/bootstrap.php';

use Apps\HumanEdgeScorecard\Services\RecordStore;
use Apps\HumanEdgeScorecard\Services\Retention;
use Platform\Core\AppRegistry;
use Platform\Core\Database;
use Platform\Core\LogRetention;

$app    = AppRegistry::get('human-edge-scorecard');
$cutoff = RecordStore::retentionCutoff();

if (in_array('--dry-run', $argv, true)) {
    $due = (int) Database::scalar(
        sprintf('SELECT COUNT(*) FROM `%s` WHERE `completed_at` < ?', $app->table('records')),
        [$cutoff]
    );

    echo sprintf("dry run: %d record(s) completed before %s UTC would be deleted; nothing changed\n", $due, $cutoff);
    $logs = LogRetention::run(null, true);
} else {
    $result = Retention::run($app);
    $logs   = $result['logs'];

    echo sprintf("%s  deleted %d record(s) completed before %s UTC\n", gmdate('Y-m-d H:i:s'), $result['records'], $result['cutoff']);
}

if ($logs === []) {
    echo "  logs: none configured or found (config/logging.php)\n";
}

foreach ($logs as $log) {
    echo sprintf("  %-11s %s  (%d line(s) past %d days, %d address(es) redacted)\n",
        $log['action'], $log['file'], $log['dropped'], (int) config('logging.days'), $log['redacted']);
}
