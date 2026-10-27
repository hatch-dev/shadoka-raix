<?php

declare(strict_types=1);

use Apps\HumanEdgeScorecard\Services\Instrument;
use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Apps\HumanEdgeScorecard\Services\Records;
use Apps\HumanEdgeScorecard\Services\Stats;
use Apps\HumanEdgeScorecard\Services\Synthetic\SeedGenerator;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The Data seeder: the page's seedDataset() and applySeed(), ported.
 *
 * Deterministic for a seed, everything flagged synthetic, identities that can
 * never reach a person, scores consistent with the page's rules, and a write
 * that replaces the synthetic set without touching a real record.
 *
 * Shared database: the synthetic set is saved first and put back, and the
 * test's own seed run is removed.
 */
return static function (Assert $t): void {
    $app       = AppRegistry::get('human-edge-scorecard');
    $generator = new SeedGenerator($app);
    $stats     = new Stats(new Instrument($app));
    $recs      = $app->table('records');
    $subs      = $app->table('submissions');

    $strip = static fn (array $rs): string => json_encode(array_map(static function (array $r): array {
        unset($r['timestamp']);   // from the clock, as on the page

        return $r;
    }, $rs));

    /* ---------- the generator ---------- */

    $a = $generator->records(SeedGenerator::DEFAULT_OPTIONS);
    $b = (new SeedGenerator($app))->records(SeedGenerator::DEFAULT_OPTIONS);
    $t->same('the same seed gives the same dataset', $strip($a), $strip($b));
    $t->true('a different seed gives a different one', $strip($a) !== $strip($generator->records(['count' => 14, 'seed' => 7])));
    $t->same('the default set covers fourteen organizations', 14, count(array_unique(array_map(static fn (array $r): string => $r['org']['org'], $a))));

    $problems = [];
    $ids      = [];
    foreach ($a as $r) {
        $ids[] = $r['id'];
        if ($r['synthetic'] !== true || $r['archetype'] === null) {
            $problems[] = $r['id'] . ' not flagged';
        }
        if (!str_ends_with($r['userEmail'], '.example.invalid') || !preg_match('/^\+1 555 01\d\d$/', $r['userPhone'])) {
            $problems[] = $r['id'] . ' contactable';
        }
        $marks = 0;
        foreach ((array) $r['responses'] as $byIndex) {
            foreach ((array) $byIndex as $mark) {
                $marks++;
                if ($mark < 1 || $mark > 5) {
                    $problems[] = $r['id'] . ' mark ' . $mark;
                }
            }
        }
        if ($marks !== 18) {
            $problems[] = $r['id'] . ' has ' . $marks . ' marks';
        }
        $s = $stats->summarize((array) $r['dims']);
        if ($s['mean'] !== $r['mean'] || ($s['level']['name'] ?? null) !== $r['level']) {
            $problems[] = $r['id'] . ' score';
        }
        if (strtotime($r['timestamp']) > time()) {
            $problems[] = $r['id'] . ' in the future';
        }
    }
    $t->same('every record: synthetic, unreachable, 18 marks of 1-5, scored by the page\'s rules, not future-dated', [], $problems);
    $t->same('record ids are unique', count($ids), count(array_unique($ids)));

    $several = $generator->records(['count' => 6, 'seed' => 99, 'boardShare' => 1, 'raters' => ['min' => 3, 'max' => 5]]);
    $byOrg   = [];
    foreach ($several as $r) {
        $byOrg[$r['org']['org']][] = $r['org']['role'];
    }
    $t->true('several respondents per organization when asked, each a different role',
        count($byOrg) === 6 && !array_filter($byOrg, static fn (array $roles): bool => count($roles) < 3 || count($roles) > 5 || count(array_unique($roles)) !== count($roles)));

    /* ---------- the write ---------- */

    $savedSynthetic = Database::select("SELECT * FROM `{$recs}` WHERE `synthetic` = 1");
    $seedRun        = Database::scalar("SELECT MAX(`seed_run_id`) FROM `{$subs}` WHERE `is_synthetic` = 1");
    $realBefore     = Database::select("SELECT `id`, `payload`, `restricted` FROM `{$recs}` WHERE `synthetic` = 0 ORDER BY `id`");
    $runId          = null;

    try {
        $out   = $generator->generate(['count' => 3, 'seed' => 42, 'boardShare' => 0]);
        $runId = $out['run'];
        $t->same('generate(): three scorecards, three organizations', [3, 3], [$out['records'], $out['orgs']]);
        $t->same('the synthetic set is REPLACED, not added to', 3, (int) Database::scalar("SELECT COUNT(*) FROM `{$recs}` WHERE `synthetic` = 1"));
        $t->same('the console copies carry the seed run', 3, (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `is_synthetic` = 1 AND `seed_run_id` = ?", [$runId]));
        $t->same('the run is recorded with its seed', '42', (string) Database::scalar('SELECT `seed` FROM `' . $app->table('seed_runs') . '` WHERE `id` = ?', [$runId]));
        $t->same('no real record was touched', $realBefore, Database::select("SELECT `id`, `payload`, `restricted` FROM `{$recs}` WHERE `synthetic` = 0 ORDER BY `id`"));

        $t->same('purge(): every synthetic record goes', 3, SeedGenerator::purge($app));
        $t->same('… and every synthetic copy', 0, (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `is_synthetic` = 1"));
    } finally {
        Database::statement("DELETE FROM `{$recs}` WHERE `synthetic` = 1");
        foreach ($savedSynthetic as $r) {
            Database::insert($recs, $r);
        }
        (new RecordMirror($app))->syncSynthetic($seedRun === null ? null : (int) $seedRun);
        if ($runId !== null) {
            Database::statement('DELETE FROM `' . $app->table('seed_runs') . '` WHERE `id` = ?', [$runId]);
        }
        RecordMirror::pruneOrganisations();
        Records::flush();
    }

    $t->same('cleaned up: the demonstration set is back', count($savedSynthetic), (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `is_synthetic` = 1"));
};
