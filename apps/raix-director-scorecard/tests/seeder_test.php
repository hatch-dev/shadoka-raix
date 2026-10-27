<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecard\Services\Synthetic\Archetypes;
use Apps\RaixDirectorScorecard\Services\Synthetic\SeedGenerator;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The synthetic generator, and the three legacy defects that must not return.
 *
 * Generates into the live database and removes everything it made. It only ever
 * deletes rows flagged synthetic, so a real submission cannot be caught by the
 * cleanup even if one appears mid-run.
 */
return static function (Assert $t): void {
    $app = AppRegistry::get('raix-director-scorecard');

    $realBefore = (int) Database::scalar('SELECT COUNT(*) FROM `ds_submissions` WHERE `is_synthetic` = 0');
    $runIds     = [];

    try {
        /* ---------- generation ---------- */

        $generator = new SeedGenerator($app, 20260908);
        $result    = $generator->generate(['count' => 6, 'board_share' => 0.5]);
        $runIds[]  = $result['seed_run_id'];

        $t->true('records were generated', $result['records'] > 0);
        $t->true('boards were created', $result['organisations'] > 0);
        $t->true(
            'a board_share above zero produces more directors than boards',
            $result['records'] > $result['organisations']
        );

        $rows = Database::select(
            'SELECT * FROM `ds_submissions` WHERE `seed_run_id` = ?',
            [$result['seed_run_id']]
        );

        $t->count('every generated record is retrievable', $result['records'], $rows);
        $t->true('all are flagged synthetic', array_reduce(
            $rows,
            static fn (bool $ok, array $r): bool => $ok && (int) $r['is_synthetic'] === 1,
            true
        ));
        $t->true('all are complete', array_reduce(
            $rows,
            static fn (bool $ok, array $r): bool => $ok && $r['status'] === 'complete',
            true
        ));
        $t->true('all carry an archetype', array_reduce(
            $rows,
            static fn (bool $ok, array $r): bool => $ok && in_array($r['archetype'], array_keys(Archetypes::DEFINITIONS), true),
            true
        ));

        /* ---------- DEFECT 1: manufactured correlations ---------- */

        $invented = array_filter(
            $rows,
            static fn (array $r): bool => $r['agenda_meetings'] !== null
                || $r['education_hours'] !== null
                || $r['board_seats'] !== null
        );

        $t->count(
            'the generator does NOT invent the figures used in correlations',
            0,
            $invented,
            'the legacy generator derived agenda_meetings and education_hours from '
            . 'each record\'s own score, which made the correlation screen report '
            . 'a relationship it had manufactured'
        );

        /* ---------- DEFECT 2: records dated in the future ---------- */

        $future = array_filter(
            $rows,
            static fn (array $r): bool => strtotime((string) $r['submitted_at']) > time()
        );

        $t->count(
            'no generated record is dated in the future',
            0,
            $future,
            'the legacy generator subtracted days from "now" then forced the year, '
            . 'pushing about one record in ten past today'
        );

        $wrongYear = array_filter(
            $rows,
            static fn (array $r): bool => (int) date('Y', strtotime((string) $r['submitted_at'])) !== (int) $r['assessment_year']
        );
        $t->count('each record is dated inside its own assessment year', 0, $wrongYear);

        /* ---------- DEFECT 3: engine-dependent determinism ---------- */

        SeedGenerator::purge($app, $result['seed_run_id']);
        $runIds = [];

        $again     = new SeedGenerator($app, 20260908);
        $resultB   = $again->generate(['count' => 6, 'board_share' => 0.5]);
        $runIds[]  = $resultB['seed_run_id'];

        $t->same(
            'the same seed produces the same number of records',
            $result['records'],
            $resultB['records'],
            'the legacy shuffle used an inconsistent sort comparator, so the PRNG '
            . 'stream desynchronised and the same seed gave 32 records in Node and '
            . '38 in Chrome'
        );
        $t->same('and the same number of boards', $result['organisations'], $resultB['organisations']);
        $t->same('and consumes exactly the same number of PRNG draws', $generator->draws(), $again->draws());

        $rowsB = Database::select(
            'SELECT `seat_role`, `assessment_year`, `archetype`, `floor_score`, `mean_score`, `level_value`
               FROM `ds_submissions` WHERE `seed_run_id` = ? ORDER BY `id`',
            [$resultB['seed_run_id']]
        );
        $rowsA = array_map(
            static fn (array $r): array => [
                'seat_role'       => $r['seat_role'],
                'assessment_year' => $r['assessment_year'],
                'archetype'       => $r['archetype'],
                'floor_score'     => $r['floor_score'],
                'mean_score'      => $r['mean_score'],
                'level_value'     => $r['level_value'],
            ],
            $rows
        );

        $t->same('and produces identical scores, record for record', $rowsA, $rowsB);

        /* ---------- scores are real, not asserted ---------- */

        $badMarks = (int) Database::scalar(
            'SELECT COUNT(*) FROM `ds_submission_responses` r
               JOIN `ds_submissions` s ON s.`id` = r.`submission_id`
              WHERE s.`seed_run_id` = ? AND (r.`mark` < 1 OR r.`mark` > 4)',
            [$resultB['seed_run_id']]
        );
        $t->same('every generated mark is within 1-4', 0, $badMarks);

        $unscored = array_filter($rowsB, static fn (array $r): bool => $r['floor_score'] === null);
        $t->count('every generated record was scored by the real Scorer', 0, $unscored);

        $inconsistent = (int) Database::scalar(
            'SELECT COUNT(*) FROM `ds_submissions` s
              WHERE s.`seed_run_id` = ?
                AND s.`floor_score` > s.`mean_score`',
            [$resultB['seed_run_id']]
        );
        $t->same('the floor never exceeds the mean', 0, $inconsistent);

        /* ---------- a different seed gives a different dataset ---------- */

        $other    = new SeedGenerator($app, 4242);
        $resultC  = $other->generate(['count' => 6, 'board_share' => 0.5]);
        $runIds[] = $resultC['seed_run_id'];

        $scoresC = Database::select(
            'SELECT `floor_score` FROM `ds_submissions` WHERE `seed_run_id` = ? ORDER BY `id`',
            [$resultC['seed_run_id']]
        );
        $t->true(
            'a different seed produces a different dataset',
            array_column($scoresC, 'floor_score') !== array_column($rowsB, 'floor_score')
                || $resultC['records'] !== $resultB['records']
        );

        /* ---------- purge removes only synthetic rows ---------- */

        $removed = SeedGenerator::purge($app);
        $runIds  = [];

        $t->true('purge removed records', $removed > 0);
        $t->same(
            'no synthetic record survives',
            0,
            (int) Database::scalar('SELECT COUNT(*) FROM `ds_submissions` WHERE `is_synthetic` = 1')
        );
        $t->same(
            'real submissions are untouched',
            $realBefore,
            (int) Database::scalar('SELECT COUNT(*) FROM `ds_submissions` WHERE `is_synthetic` = 0')
        );
        $t->same(
            'orphaned demo boards are cleaned up too',
            0,
            (int) Database::scalar('SELECT COUNT(*) FROM `organisations` WHERE `is_synthetic` = 1')
        );
    } finally {
        foreach ($runIds as $runId) {
            SeedGenerator::purge($app, $runId);
        }
        SeedGenerator::purge($app);
        Database::statement('DELETE FROM `ds_seed_runs`');
    }
};
