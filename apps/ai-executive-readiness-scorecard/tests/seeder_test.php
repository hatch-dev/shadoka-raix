<?php

declare(strict_types=1);

use Apps\AiExecutiveReadinessScorecard\Services\Records;
use Apps\AiExecutiveReadinessScorecard\Services\Synthetic\SeedGenerator;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The demo generator: deterministic, flagged, unreachable, paired, and removable
 * by run.
 *
 * Only this test's OWN runs are purged (by seed_run_id). A global purge would
 * delete the demo set someone is browsing in the shared dev database.
 */
return static function (Assert $t): void {
    $app   = AppRegistry::get('ai-executive-readiness-scorecard');
    $table = $app->table('submissions');
    $runs  = [];

    $scores = static fn (int $run): array => array_map(
        static fn (array $r): string => $r['self_mean'] . '/' . $r['leadership_mean'] . '/' . $r['assessment_year'],
        Database::select(sprintf('SELECT `self_mean`, `leadership_mean`, `assessment_year` FROM `%s` WHERE `seed_run_id` = ? ORDER BY `id`', $table), [$run])
    );

    try {
        $a      = (new SeedGenerator($app, 777))->generate(['count' => 4]);
        $runs[] = $a['seed_run_id'];
        $b      = (new SeedGenerator($app, 777))->generate(['count' => 4]);
        $runs[] = $b['seed_run_id'];

        $t->true('generates records', $a['records'] >= 4);
        $t->same('the same seed gives the same records', $scores($a['seed_run_id']), $scores($b['seed_run_id']));

        $rows = Database::select(sprintf('SELECT * FROM `%s` WHERE `seed_run_id` = ?', $table), [$a['seed_run_id']]);

        $t->true('every row is synthetic and complete', array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && (int) $r['is_synthetic'] === 1 && $r['status'] === 'complete', true));
        $t->true('no consent is recorded', array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && $r['consent_agreed_at'] === null, true));
        $t->true('every address is unreachable', array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && str_ends_with($r['respondent_email'], '.example.invalid'), true));
        $t->true('every phone is in the fictional range', array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && str_starts_with($r['respondent_phone'], '+1 555 01'), true));
        $t->true('never dated in the future', array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && strtotime($r['submitted_at']) <= time(), true));
        $t->true('both profiles scored', array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && $r['self_level'] !== null && $r['leadership_level'] !== null, true));

        $ratings = Database::select(sprintf(
            'SELECT `scope`, COUNT(*) AS n FROM `%s` WHERE `submission_id` = ? GROUP BY `scope` ORDER BY `scope`',
            $app->table('submission_responses')
        ), [$rows[0]['id']]);
        $t->same('twelve ratings in each scope', [['scope' => 'self', 'n' => 12], ['scope' => 'leadership', 'n' => 12]], array_map(
            static fn (array $r): array => ['scope' => $r['scope'], 'n' => (int) $r['n']],
            array_values(array_merge(
                array_filter($ratings, static fn ($r) => $r['scope'] === 'self'),
                array_filter($ratings, static fn ($r) => $r['scope'] === 'leadership')
            ))
        ));

        Records::flush();
        $record = (new Records($app))->find((int) $rows[0]['id']);
        $t->true('the record carries its archetype', isset($record['archetype']));
        $t->same('a generated profile ends on tenure, as synthRecord wrote it', 'tenure', array_key_last($record['org']));

        $removed = SeedGenerator::purge($app, $a['seed_run_id']);
        $t->same('purging one run removes exactly its records', $a['records'], $removed);
        $t->same('the other run is untouched', $b['records'], (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s` WHERE `seed_run_id` = ?', $table), [$b['seed_run_id']]));
    } finally {
        foreach ($runs as $run) {
            SeedGenerator::purge($app, $run);
            Database::statement(sprintf('DELETE FROM `%s` WHERE `id` = ?', $app->table('seed_runs')), [$run]);
        }
        Records::flush();
    }
};
