<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Auth;
use Platform\Core\LogRetention;
use Platform\Core\Settings;

/**
 * The daily job: C3, every es_records row more than 24 months after it was
 * completed deleted whole; and C6, every log trimmed to 30 days with email
 * addresses redacted in what it keeps (Platform\Core\LogRetention, over the
 * files config/logging.php names: the RAIX app log always, and the web
 * server's and PHP's logs where SERVER_LOG_DIR / PHP_LOG_DIR are set).
 *
 * Two ways in, one body: scripts/retention.php for a scheduler, and
 * runIfDue() from the v32 page and endpoints, which does the same work at
 * most once a day so a host without cron still keeps the promises in the
 * notice.
 *
 * Only es_records is in scope for deletion — the table the v32 page writes.
 * The older es_submissions flow is not touched.
 *
 * Backups are not reachable from here. On AWS they must expire in under 30
 * days (directive C3), so a record deleted here does not outlive the limit in
 * a snapshot; likewise CloudWatch, CloudFront, load-balancer and S3 logs carry
 * their own 30-day retention setting (C6), which is AWS configuration.
 */
final class Retention
{
    public static function lastRunKey(AppManifest $app): string
    {
        return 'app.' . $app->slug . '.retention_last_run';
    }

    /**
     * Run now.
     *
     * @return array{records:int, cutoff:string, logs:list<array{file:string, action:string, dropped:int, redacted:int}>}
     */
    public static function run(AppManifest $app, ?\DateTimeImmutable $now = null): array
    {
        $deleted = (new RecordStore($app))->purgeExpired($now);
        $logs    = LogRetention::run($now?->getTimestamp());

        Settings::put(self::lastRunKey($app), gmdate('Y-m-d H:i:s'));

        if ($deleted > 0) {
            // A count and nothing else: no ids, no addresses.
            Auth::audit('es.retention.purge', $app->slug, 'records', null, ['deleted' => $deleted]);
        }

        return ['records' => $deleted, 'cutoff' => RecordStore::retentionCutoff($now), 'logs' => $logs];
    }

    /** Run if the last run was more than a day ago (or never). */
    public static function runIfDue(AppManifest $app): void
    {
        $last = Settings::get(self::lastRunKey($app));

        if ($last !== null && strtotime($last . ' UTC') > time() - 86400) {
            return;
        }

        self::run($app);
    }
}
