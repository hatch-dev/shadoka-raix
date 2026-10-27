<?php

declare(strict_types=1);

use Apps\AiSovereigntyScorecard\Services\Synthetic\Archetypes;
use Apps\AiSovereigntyScorecard\Services\Synthetic\SeedGenerator;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The demo data generator.
 *
 * Three properties matter, and each of them was a defect in the reference:
 *
 *  1. Deterministic. The same seed must produce the same dataset, byte for byte,
 *     on any machine. The reference shuffled with `sort(() => r() - .5)`, whose
 *     comparator call count is engine-dependent, so "deterministic for a given
 *     seed" held only within one JavaScript engine.
 *  2. Able to reach the top of the scale. It clamped its archetype targets to 4
 *     while marking 1–5, so no synthetic organisation could ever be Embedded and
 *     the top level was empty in every demo dataset.
 *  3. Honest about what it does not know. The two figures the correlation pane
 *     plots are left null, so that pane reports nothing it manufactured.
 *
 * Everything written here is removed in a finally. Nothing truncates a table:
 * this database is shared with a running site, and it already holds a demo set
 * somebody may be looking at.
 */
return static function (Assert $t): void {
    $app = AppRegistry::get('ai-sovereignty-scorecard');

    /* ---------- the tables come from the instrument, not from code ---------- */

    $definitions = Archetypes::definitions();

    $t->count('six archetypes, as the reference has', 6, $definitions);
    $t->true('fragmented is the one whose marks cluster at Emerging', $definitions['fragmented']['emerging']);
    $t->true('and the others are not', !$definitions['established']['emerging']);
    $t->same(
        'established asks for 4.4 on data',
        4.4,
        $definitions['established']['targets'][0]
    );
    $t->count('every sector has a prior', 10, Archetypes::sectorPrior());
    $t->count('ten roles carry a bias', 10, Archetypes::roleBias());
    $t->true('and every biased role is one the instrument offers', array_reduce(
        array_keys(Archetypes::roleBias()),
        static fn (bool $carry, string $role): bool => $carry && in_array($role, Archetypes::roles(), true),
        true
    ));

    /* ---------- a run ---------- */

    $before = (int) Database::scalar('SELECT COUNT(*) FROM `sov_submissions` WHERE `is_synthetic` = 1');
    $real   = (int) Database::scalar('SELECT COUNT(*) FROM `sov_submissions` WHERE `is_synthetic` = 0');

    $seed    = 424242;
    $runIds  = [];

    try {
        $generator = new SeedGenerator($app, $seed);
        $first     = $generator->generate(['count' => 8, 'multi_share' => 0.5]);
        $runIds[]  = $first['seed_run_id'];

        $t->true('it wrote some records', $first['records'] > 0);
        $t->same('and eight organisations', 8, $first['organisations']);
        $t->true('records outnumber organisations, so some were scored by several people',
            $first['records'] > $first['organisations']);

        $t->same(
            'real submissions are untouched',
            $real,
            (int) Database::scalar('SELECT COUNT(*) FROM `sov_submissions` WHERE `is_synthetic` = 0')
        );

        $rows = Database::select(
            'SELECT * FROM `sov_submissions` WHERE `seed_run_id` = ?',
            [$first['seed_run_id']]
        );

        $t->count('every record belongs to the run', $first['records'], $rows);

        foreach ($rows as $row) {
            if ((int) $row['is_synthetic'] !== 1) {
                $t->true('every record is flagged synthetic', false);
                break;
            }
        }
        $t->true('every record is flagged synthetic', true);

        /* Every one is complete and scored by the real Scorer. */
        $unscored = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submissions`
              WHERE `seed_run_id` = ? AND (`mean_score` IS NULL OR `level_value` IS NULL OR `status` <> \'complete\')',
            [$first['seed_run_id']]
        );
        $t->same('all of them are complete and scored', 0, $unscored);

        /* Sixteen marks each, all inside 1–5. */
        $wrongCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM (
                SELECT s.`id`, COUNT(r.`id`) AS n
                  FROM `sov_submissions` s
                  JOIN `sov_submission_responses` r ON r.`submission_id` = s.`id`
                 WHERE s.`seed_run_id` = ?
              GROUP BY s.`id`
                HAVING n <> 16
             ) AS wrong',
            [$first['seed_run_id']]
        );
        $t->same('each answered all sixteen questions', 0, $wrongCount);

        $outOfRange = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submission_responses` r
               JOIN `sov_submissions` s ON s.`id` = r.`submission_id`
              WHERE s.`seed_run_id` = ? AND (r.`mark` < 1 OR r.`mark` > 5)',
            [$first['seed_run_id']]
        );
        $t->same('no mark is outside the scale', 0, $outOfRange);

        /* ---------- what it deliberately does not generate ---------- */

        $reported = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submissions`
              WHERE `seed_run_id` = ? AND (`agenda_meetings` IS NOT NULL OR `education_hours` IS NOT NULL)',
            [$first['seed_run_id']]
        );
        $t->same(
            'the two correlation axes are left empty, so no finding is manufactured',
            0,
            $reported
        );

        $consented = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submissions` WHERE `seed_run_id` = ? AND `consent_agreed_at` IS NOT NULL',
            [$first['seed_run_id']]
        );
        $t->same('and no consent is recorded, because nobody consented', 0, $consented);

        /* The inventory count IS generated: it is a fact, not an axis. */
        $withInventory = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submissions` WHERE `seed_run_id` = ? AND `systems_inventory` IS NOT NULL',
            [$first['seed_run_id']]
        );
        $t->same('the inventory count is generated', $first['records'], $withInventory);

        /* ---------- every generated identity is unreachable ---------- */

        $reachable = Database::select(
            'SELECT `respondent_email`, `respondent_phone` FROM `sov_submissions` WHERE `seed_run_id` = ?',
            [$first['seed_run_id']]
        );

        $badEmail = 0;
        $badPhone = 0;

        foreach ($reachable as $person) {
            if (!str_ends_with((string) $person['respondent_email'], '.example.invalid')) {
                $badEmail++;
            }
            if (!str_starts_with((string) $person['respondent_phone'], '+1 555 01')) {
                $badPhone++;
            }
        }

        $t->same('every address is on the reserved .invalid TLD', 0, $badEmail);
        $t->same('and every number is in the fictional range', 0, $badPhone);

        /* And the form would refuse every one of them. */
        $t->same(
            'a generated address could never be submitted through the form',
            'Use an address you actually read. Disposable and placeholder domains are not accepted.',
            \Apps\AiSovereigntyScorecard\Services\RespondentIdentity::emailError(
                (string) $reachable[0]['respondent_email']
            )
        );

        /* ---------- dates are inside their year, never in the future ---------- */

        $misdated = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submissions`
              WHERE `seed_run_id` = ?
                AND (YEAR(`submitted_at`) <> `assessment_year` OR `submitted_at` > NOW())',
            [$first['seed_run_id']]
        );
        $t->same('no record is dated outside its own year or in the future', 0, $misdated);

        /* ---------- determinism ---------- */

        $draws = $generator->draws();

        $fingerprint = static fn (int $runId): string => (string) Database::scalar(
            'SELECT GROUP_CONCAT(x.`sig` ORDER BY x.`sig`) FROM (
                SELECT CONCAT(s.`organisation_name`, "|", s.`role`, "|", s.`assessment_year`, "|",
                              s.`mean_score`, "|", s.`floor_score`, "|", s.`archetype`) AS sig
                  FROM `sov_submissions` s WHERE s.`seed_run_id` = ?
             ) AS x',
            [$runId]
        );

        $signatureOne = $fingerprint($first['seed_run_id']);

        $repeat   = new SeedGenerator($app, $seed);
        $second   = $repeat->generate(['count' => 8, 'multi_share' => 0.5]);
        $runIds[] = $second['seed_run_id'];

        $t->same('the same seed draws the same number of times', $draws, $repeat->draws());
        $t->same('and produces the same record count', $first['records'], $second['records']);
        $t->same(
            'and the same scores, organisations and archetypes',
            $signatureOne,
            $fingerprint($second['seed_run_id'])
        );

        /* A different seed must not. */
        $different = new SeedGenerator($app, $seed + 1);
        $third     = $different->generate(['count' => 8, 'multi_share' => 0.5]);
        $runIds[]  = $third['seed_run_id'];

        $t->true(
            'a different seed produces a different dataset',
            $fingerprint($third['seed_run_id']) !== $signatureOne
        );

        /* ---------- the whole scale is reachable ---------- */

        /*
         * The reference clamped its archetype targets to 4 while marking 1–5, so
         * Embedded was empty in every demo dataset it produced. A run weighted
         * entirely to the strongest archetype has to be able to reach it.
         */
        $strong   = new SeedGenerator($app, 987654);
        $embedded = $strong->generate([
            'count'       => 25,
            'multi_share' => 0,
            'archetypes'  => ['established' => 1.0],
        ]);
        $runIds[] = $embedded['seed_run_id'];

        $topLevel = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submissions` WHERE `seed_run_id` = ? AND `level_value` = 5',
            [$embedded['seed_run_id']]
        );

        $t->true('the strongest archetype can reach Embedded', $topLevel > 0);

        $weak     = new SeedGenerator($app, 987655);
        $starting = $weak->generate([
            'count'       => 25,
            'multi_share' => 0,
            'archetypes'  => ['starting' => 1.0],
        ]);
        $runIds[] = $starting['seed_run_id'];

        $bottom = (int) Database::scalar(
            'SELECT COUNT(*) FROM `sov_submissions` WHERE `seed_run_id` = ? AND `level_value` <= 2',
            [$starting['seed_run_id']]
        );

        $t->true('and the weakest one stays at the bottom', $bottom > 0);

        /* ---------- options ---------- */

        $single   = new SeedGenerator($app, 111);
        $alone    = $single->generate(['count' => 5, 'multi_share' => 0, 'years' => [(int) date('Y')]]);
        $runIds[] = $alone['seed_run_id'];

        $t->same('multi_share 0 means one respondent each', 5, $alone['records']);
        $t->same(
            'and one year means one year',
            1,
            (int) Database::scalar(
                'SELECT COUNT(DISTINCT `assessment_year`) FROM `sov_submissions` WHERE `seed_run_id` = ?',
                [$alone['seed_run_id']]
            )
        );

        /* An all-zero archetype mix cannot divide by zero. */
        $zeroed   = new SeedGenerator($app, 222);
        $fallback = $zeroed->generate([
            'count'       => 2,
            'multi_share' => 0,
            'archetypes'  => ['fragmented' => 0.0, 'building' => 0.0],
        ]);
        $runIds[] = $fallback['seed_run_id'];

        /*
         * Records, not organisations: multi_share 0 means one respondent per
         * organisation-year, and an organisation still appears in one, two or
         * all three years. Two organisations therefore write between two and six
         * records, which is why this asserts the organisation count and only
         * that records were written at all.
         */
        $t->true('an empty archetype mix falls back rather than dividing by zero', $fallback['records'] > 0);
        $t->same('and still makes the organisations it was asked for', 2, $fallback['organisations']);

        /* The run is recorded with everything needed to reproduce it. */
        $run = Database::selectOne('SELECT * FROM `sov_seed_runs` WHERE `id` = ?', [$first['seed_run_id']]);

        $t->same('the run records its seed', $seed, (int) $run['seed']);
        $t->same('and its record count', $first['records'], (int) $run['records_created']);
        $t->same('and its organisation count', 8, (int) $run['organisations_created']);
        $t->true('and the options it ran with', str_contains((string) $run['options'], 'multi_share'));

        /* ---------- purging one batch, then all ---------- */

        $removed = SeedGenerator::purge($app, $first['seed_run_id']);

        $t->same('purging one run removes exactly its records', $first['records'], $removed);
        $t->same(
            'and leaves the others',
            $second['records'],
            (int) Database::scalar(
                'SELECT COUNT(*) FROM `sov_submissions` WHERE `seed_run_id` = ?',
                [$second['seed_run_id']]
            )
        );
        $t->same(
            'and never touches a real submission',
            $real,
            (int) Database::scalar('SELECT COUNT(*) FROM `sov_submissions` WHERE `is_synthetic` = 0')
        );
    } finally {
        foreach ($runIds as $runId) {
            // The demo records each run's rows were given (RecordMirror) go first.
            (new \Apps\AiSovereigntyScorecard\Services\RecordMirror($app))->forgetSynthetic($runId);
            Database::statement('DELETE FROM `sov_submissions` WHERE `seed_run_id` = ?', [$runId]);
            Database::statement('DELETE FROM `sov_seed_runs` WHERE `id` = ?', [$runId]);
        }

        /*
         * Demo organisations nothing points at any more. The same rule the purge
         * uses: only synthetic ones, and only where no app's submissions
         * reference them — `organisations` is shared, so a row the Director
         * Scorecard's demo set still uses must survive.
         */
        Database::statement(
            'DELETE FROM `organisations`
              WHERE `is_synthetic` = 1
                AND `id` NOT IN (SELECT DISTINCT `organisation_id` FROM `sov_submissions` WHERE `organisation_id` IS NOT NULL)
                AND `id` NOT IN (SELECT DISTINCT `organisation_id` FROM `ds_submissions` WHERE `organisation_id` IS NOT NULL)'
        );
    }

    $t->same(
        'the synthetic population is back where it started',
        $before,
        (int) Database::scalar('SELECT COUNT(*) FROM `sov_submissions` WHERE `is_synthetic` = 1')
    );
};
