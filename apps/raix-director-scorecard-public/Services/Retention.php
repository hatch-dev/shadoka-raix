<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services;

use Platform\Core\AppManifest;
use Platform\Core\Auth;
use Platform\Core\LogRetention;
use Platform\Core\Settings;

/**
 * C3: the daily job that deletes every record more than 24 months after it was
 * completed, whole — and C6: every log trimmed to 30 days.
 *
 * Two ways in, one body: scripts/retention.php for a scheduler, and
 * runIfDue() from the public page, which does the same work at most once a
 * day so a host without cron still keeps the promise in the notice.
 *
 * Backups are not reachable from here. On AWS they must expire in under 30
 * days (directive C3), so a record deleted here does not outlive the limit in
 * a snapshot.
 */
final class Retention
{
    public static function lastRunKey(AppManifest $app): string
    {
        return 'app.' . $app->slug . '.retention_last_run';
    }

    /**
     * Run now: records past 24 months (C3), then every log past 30 days with
     * email addresses redacted (C6, Platform\Core\LogRetention).
     *
     * @return array{records:int, logs:list<array{file:string, action:string, dropped:int, redacted:int}>}
     */
    public static function run(AppManifest $app): array
    {
        $deleted = (new RecordStore($app))->purgeExpired();
        $logs    = LogRetention::run();

        Settings::put(self::lastRunKey($app), gmdate('Y-m-d H:i:s'));

        if ($deleted > 0) {
            // A count and nothing else: no ids, no addresses.
            Auth::audit('dsp.retention.purge', $app->slug, 'records', null, ['deleted' => $deleted]);
        }

        return ['records' => $deleted, 'logs' => $logs];
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
