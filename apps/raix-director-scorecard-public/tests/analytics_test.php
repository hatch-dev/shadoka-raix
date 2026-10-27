<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecardPublic\Services\Analytics;
use Apps\RaixDirectorScorecardPublic\Services\SubmissionService;
use Apps\RaixDirectorScorecardPublic\Services\Synthetic\SeedGenerator;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The read-side aggregates.
 *
 * Built against a known set of records so the numbers can be asserted rather
 * than merely shown to be non-empty. Everything created is removed afterwards.
 *
 * These aggregates run over the WHOLE dataset, so the suite asserts that the
 * dataset is empty before it starts. That precondition used to be implicit, and
 * a single stray probe record turned twelve assertions red without any of them
 * saying why. One clear failure beats twelve cryptic ones.
 */
return static function (Assert $t): void {
    $app     = AppRegistry::get('raix-director-scorecard-public');
    $service = new SubmissionService($app);
    $tokens  = [];

    /** Create a completed scorecard with fixed marks. */
    $score = static function (array $profile, array $marks) use ($service, &$tokens): array {
        // The identity this edition requires; the figures under test do not use it.
        $started  = $service->start($profile + [
            'director_email' => 'probe@raix-tests.org',
            'director_phone' => '+1 202 555 0147',
            'company_size'   => '51–250',
        ]);
        $tokens[] = $started['token'];

        $submission = $service->findByToken($started['token']);
        $items      = Database::select(
            'SELECT i.`id`, c.`code` FROM `dsp_items` i
               JOIN `dsp_competencies` c ON c.`id` = i.`competency_id`
              WHERE c.`instrument_id` = ? ORDER BY c.`position`, i.`position`',
            [$submission['instrument_id']]
        );

        $service->saveProgress($submission, array_map(
            static fn (array $i): array => ['item_id' => (int) $i['id'], 'mark' => $marks[$i['code']]],
            $items
        ));
        $service->submit($service->findByToken($started['token']));

        return $service->findByToken($started['token']);
    };

    // These aggregates are computed over the whole dataset, so the figures below
    // are only predictable when this suite's three records are the only ones.
    // Said once, plainly, rather than as a dozen confusing mismatches.
    $baseline = (new Analytics($app, false))->overview();

    $t->same(
        'the dataset is empty before this suite runs',
        0,
        $baseline['n'],
        'analytics aggregates over every record, so this suite needs the dataset '
        . 'to itself. Found ' . $baseline['n'] . ' existing scorecard(s) — clear '
        . 'them, or run this suite against a fresh database.'
    );

    if ($baseline['n'] !== 0) {
        return;
    }

    try {
        $year = (int) date('Y');

        // Two directors of one board, one of another, across two years.
        $score(['board_name' => 'Analytics Probe Alpha', 'director_name' => 'AP One',
                'seat_role' => 'Board Chair', 'sector' => 'Technology', 'assessment_year' => $year - 1],
               ['GOV' => 2, 'DAT' => 2, 'EXP' => 2, 'FAI' => 2, 'RIS' => 2, 'HUM' => 2, 'AUT' => 2]);

        $score(['board_name' => 'Analytics Probe Alpha', 'director_name' => 'AP Two',
                'seat_role' => 'Audit Committee Chair', 'sector' => 'Technology', 'assessment_year' => $year],
               ['GOV' => 4, 'DAT' => 4, 'EXP' => 4, 'FAI' => 4, 'RIS' => 4, 'HUM' => 4, 'AUT' => 1]);

        $score(['board_name' => 'Analytics Probe Beta', 'director_name' => 'AP Three',
                'seat_role' => 'Risk Committee Chair', 'sector' => 'Education', 'assessment_year' => $year],
               ['GOV' => 3, 'DAT' => 3, 'EXP' => 3, 'FAI' => 3, 'RIS' => 3, 'HUM' => 3, 'AUT' => 3]);

        $analytics = new Analytics($app, false);

        /* ---------- overview ---------- */

        $overview = $analytics->overview();
        $t->same('three scorecards counted', 3, $overview['n']);
        $t->same('across two boards', 2, $overview['boards']);
        // floors: 2.00, 1.00, 3.00 -> mean 2.00
        $t->same('mean floor', 2.0, $overview['mean_floor']);
        // means: 2.00, 3.57, 3.00 -> 2.86
        $t->same('mean average', 2.86, $overview['mean_average']);
        $t->same('gap', 0.86, $overview['gap']);

        /* ---------- year over year ---------- */

        $years = $analytics->yearOverYear();
        $t->count('two years', 2, $years);
        $t->same('earlier year first', $year - 1, $years[0]['year']);
        $t->same('earlier year mean floor', 2.0, $years[0]['mean_floor']);
        $t->same('first year has no change to report', null, $years[0]['floor_change']);

        // Year 2: floors 1.00 and 3.00 -> 2.00, averages 3.57 and 3.00 -> 3.29
        $t->same('later year mean floor', 2.0, $years[1]['mean_floor']);
        $t->same('later year mean average', 3.29, $years[1]['mean_average']);
        $t->same('floor did not move', 0.0, $years[1]['floor_change']);
        $t->true(
            'but the gap widened — the pattern the floor rule exists to expose',
            $years[1]['gap_change'] > 0.05
        );

        /* ---------- boards ---------- */

        $boards = $analytics->boards();
        $alpha  = null;
        foreach ($boards as $board) {
            if ($board['name'] === 'Analytics Probe Alpha') {
                $alpha = $board;
            }
        }

        $t->true('the two-director board is listed', $alpha !== null);
        $t->same('with both directors', 2, $alpha['directors'] ?? null);
        $t->same('across two years', 2, $alpha['years'] ?? null);
        $t->same('weakest director on that board', 1.0, $alpha['weakest'] ?? null);


        /* ---------- boards that re-scored ---------- */

        // Alpha scored in both years, Beta only in the current one, so only
        // Alpha can be asked what changed.
        $rescored = $analytics->boardYears();
        $t->count('only the board that scored in two years', 1, $rescored);
        $t->same('and it is Alpha', 'Analytics Probe Alpha', $rescored[0]['name']);
        $t->count('with a figure for each of its years', 2, $rescored[0]['years']);
        $t->same('earlier year floor', 2.0, $rescored[0]['years'][$year - 1]['mean_floor']);
        $t->same('latest year floor', 1.0, $rescored[0]['years'][$year]['mean_floor']);
        $t->same('and the move between the two', -1.0, $rescored[0]['change']);

        // AP One marked everything 2, so a tie resolved to the first competency
        // in instrument order; AP Two marked AUT alone down to 1.
        $t->same('the floor started on GOV', 'GOV', $rescored[0]['from_code']);
        $t->same('and has moved to AUT', 'AUT', $rescored[0]['to_code']);

        /* ---------- one board, year by year ---------- */

        // What the dialog over the dashboard draws: the board aggregated per
        // year, then banded — not the mean of its directors' own floors.
        $profile = $analytics->boardByYear((int) $alpha['id']);
        $t->count('both of Alpha’s years', 2, $profile);
        $t->same('oldest first', $year - 1, $profile[0]['year']);
        $t->count('seven competencies in each', 7, $profile[0]['competencies']);

        // AP One marked everything 2, so every competency mean is 2.00 and the
        // tie resolves to the first in instrument order.
        $t->same('first year floor', 2.0, $profile[0]['floor']);
        $t->same('on GOV, by instrument order', 'GOV', $profile[0]['floor_code']);
        $t->same('first year mean', 2.0, $profile[0]['mean']);
        $t->same('one director that year', 1, $profile[0]['directors']);

        // AP Two marked AUT alone down to 1, which is what caps the board.
        $t->same('latest year floor', 1.0, $profile[1]['floor']);
        $t->same('on AUT', 'AUT', $profile[1]['floor_code']);
        $t->same(
            'and the mean is well above it',
            3.57,
            $profile[1]['mean'],
            'the gap between this and the floor is the whole argument for the floor rule'
        );

        $t->same('a board with no scorecards has no years', [], $analytics->boardByYear(0));

        /* ---------- one competency, by sector ---------- */

        // AUT marks: Technology 2 and 1, Education 3.
        $sectors = $analytics->competencyBySector('AUT');
        $t->count('both sectors scored AUT', 2, $sectors);
        $t->same('strongest sector first', 'Education', $sectors[0]['sector']);
        $t->same('at its single mark', 3.0, $sectors[0]['mean']);
        $t->same('then Technology', 'Technology', $sectors[1]['sector']);
        $t->same('across its two marks', 1.5, $sectors[1]['mean']);
        $t->same('asking for no competency asks nothing of the database', [], $analytics->competencyBySector(''));

        /* ---------- the written read of the correlations ---------- */

        $t->same(
            'nothing is written while every coefficient is withheld',
            '',
            $analytics->correlationInsight(),
            'the pane says the data is thin in its own words; a read of figures '
            . 'it is refusing to print would contradict that'
        );

        $written = $analytics->correlationInsight([[
            'label'      => 'Hours of AI education taken',
            'reportable' => true,
            'stat'       => ['r' => 0.41, 'n' => 34, 'lo' => 0.08, 'hi' => 0.66, 'crosses' => false],
        ]]);

        $t->true(
            'a reportable figure is named with its interval',
            str_contains($written, 'hours of AI education taken')
                && str_contains($written, 'r = +0.41')
                && str_contains($written, '95% confidence interval from 0.08 to 0.66')
                && str_contains($written, 'n = 34')
        );
        $t->true(
            'and is described as an association, not an effect',
            str_contains($written, 'association rather than an effect')
        );
        $t->true(
            'an interval clear of zero is not said to include it',
            !str_contains($written, 'includes zero')
        );

        $crossing = $analytics->correlationInsight([[
            'label'      => 'Directors on the board',
            'reportable' => true,
            'stat'       => ['r' => 0.08, 'n' => 41, 'lo' => -0.23, 'hi' => 0.38, 'crosses' => true],
        ]]);

        $t->true(
            'an interval spanning zero is said so in words, next to the number',
            str_contains($crossing, 'includes zero, so the data cannot distinguish this from no association')
        );
        /* ---------- board drilldown ---------- */

        $detail = $analytics->board((int) $alpha['id']);
        $t->true('the drilldown resolves', $detail !== null);
        $t->count('it lists both directors', 2, $detail['directors']);
        $t->count('and every competency', 7, $detail['competencies']);

        $byCode = [];
        foreach ($detail['competencies'] as $competency) {
            $byCode[$competency['code']] = $competency;
        }

        // GOV: marks 2 and 4 -> mean 3.00; only the 4 reaches Practiced.
        $t->same('board mean for GOV', 3.0, $byCode['GOV']['mean']);
        $t->same('one director reaches Practiced on GOV', 1, $byCode['GOV']['at_practiced']);
        // The spread the printed board profile draws as ticks on each bar.
        $t->same(
            'and the spread across them',
            [2.0, 4.0],
            [$byCode['GOV']['min'], $byCode['GOV']['max']]
        );
        // AUT: marks 2 and 1 -> nobody reaches Practiced.
        $t->same('nobody reaches Practiced on AUT', 0, $byCode['AUT']['at_practiced']);

        /* ---------- per-statement ---------- */

        $items = $analytics->items('AUT');
        $t->true('the statement breakdown resolves', $items !== null);
        $t->count('five statements', 5, $items['items']);
        // AUT marks across the three directors: 2, 1, 3 -> mean 2.00 per item.
        $t->same('each statement has three marks', 3, $items['items'][0]['n']);
        $t->same('mean per statement', 2.0, $items['items'][0]['mean']);
        $t->same('distribution counts one of each', [1, 1, 1, 0], $items['items'][0]['distribution']);

        $t->same('an unknown competency code returns null', null, $analytics->items('ZZZ'));

        /* ---------- benchmark ---------- */

        $result = $service->result($service->findByToken($tokens[1]));
        $t->true('the benchmark is reported', $result['benchmark'] !== null);
        $t->same(
            'it is withheld below the peer minimum',
            false,
            $result['benchmark']['reportable']
        );
        $t->same('and says how many peers exist', 2, $result['benchmark']['peers']);

        /* ---------- synthetic records stay out unless asked for ---------- */

        $generator = new SeedGenerator($app, 777);
        $generator->generate(['count' => 3, 'board_share' => 0]);

        $t->same(
            'synthetic records do not change the real overview',
            3,
            (new Analytics($app, false))->overview()['n']
        );
        $t->true(
            'but are included when asked for',
            (new Analytics($app, true))->overview()['n'] > 3
        );
        // Correlations follow the toggle, as in the reference (25 Sep 2026).
        $t->same(
            'correlations leave synthetic records out when not asked for',
            0,
            (new Analytics($app, false))->correlations()[0]['n']
        );
        $t->true(
            'and plot them when included',
            (new Analytics($app, true))->correlations()[0]['n'] >= 3
        );
    } finally {
        SeedGenerator::purge($app);
        Database::statement('DELETE FROM `dsp_seed_runs`');

        foreach ($tokens as $token) {
            Database::statement('DELETE FROM `dsp_submissions` WHERE `public_token` = ?', [$token]);
        }

        Database::statement(
            'DELETE FROM `organisations`
              WHERE `name` LIKE ? AND `id` NOT IN
                    (SELECT DISTINCT `organisation_id` FROM `dsp_submissions` WHERE `organisation_id` IS NOT NULL)',
            ['Analytics Probe%']
        );
    }
};
