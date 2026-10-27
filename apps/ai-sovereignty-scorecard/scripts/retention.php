<?php

declare(strict_types=1);

/**
 * AI Sovereignty Scorecard — the daily retention job (directive v6, C3 + C6).
 *
 *   php apps/ai-sovereignty-scorecard/scripts/retention.php
 *   php apps/ai-sovereignty-scorecard/scripts/retention.php --dry-run   report, change nothing
 *
 * Deletes every sov_records row completed more than 24 months ago, whole, then
 * trims every log in config/logging.php to 30 days and redacts email
 * addresses in what it keeps. Schedule it daily (cron: `20 3 * * *`). The v6
 * page and endpoints also run it at most once a day, so a missed schedule
 * does not break the promises in the notice.
 *
 * CLI only. Prints counts, never a record or a log line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../platform/bootstrap.php';

use Apps\AiSovereigntyScorecard\Services\RecordStore;
use Apps\AiSovereigntyScorecard\Services\Retention;
use Platform\Core\AppRegistry;
use Platform\Core\Database;
use Platform\Core\LogRetention;

$app    = AppRegistry::get('ai-sovereignty-scorecard');
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
