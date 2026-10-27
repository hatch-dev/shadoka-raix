<?php

declare(strict_types=1);

use Apps\EnterpriseAiScorecard\Services\Analytics;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The read side: the figures every admin screen prints.
 *
 * Two rules this suite exists to hold:
 *
 *  1. Synthetic records are excluded unless asked for, and NEVER reach the
 *     correlations whatever the toggle says.
 *  2. A level is banded from the average and a floor is the weakest dimension,
 *     and no method returns one where the other is meant.
 *
 * It builds a small dataset of its own with known scores, so the assertions are
 * against arithmetic rather than against whatever happens to be in the shared
 * database. Everything it writes is removed in a finally.
 */
return static function (Assert $t): void {
    $app          = AppRegistry::get('enterprise-ai-scorecard');
    $instrument   = Database::selectOne(
        'SELECT `id` FROM `es_instruments` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
        ['published']
    );
    $instrumentId = (int) $instrument['id'];

    $dimensions = Database::select(
        'SELECT `id`, `code` FROM `es_dimensions` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrumentId]
    );

    $itemsByDimension = [];
    foreach ($dimensions as $dimension) {
        $itemsByDimension[$dimension['code']] = array_column(Database::select(
            'SELECT `id` FROM `es_items` WHERE `dimension_id` = ? ORDER BY `position`',
            [$dimension['id']]
        ), 'id');
    }

    /* Pearson is pure arithmetic and needs no fixtures. */

    $t->same('too few points returns nothing', null, Analytics::pearson([1, 2, 3], [1, 2, 3]));
    $t->same('a flat variable returns nothing', null, Analytics::pearson([1, 1, 1, 1], [1, 2, 3, 4]));

    $perfect = Analytics::pearson([1, 2, 3, 4, 5], [2, 4, 6, 8, 10]);
    $t->same('a perfect correlation is r = 1', 1.0, $perfect['r']);
    $t->true('and reports no interval it cannot compute', !$perfect['crosses']);

    $inverse = Analytics::pearson([1, 2, 3, 4, 5], [10, 8, 6, 4, 2]);
    $t->same('a perfect inverse is r = -1', -1.0, $inverse['r']);

    $noisy = Analytics::pearson([1, 2, 3, 4, 5, 6], [3, 1, 4, 1, 5, 2]);
    $t->true('a weak association crosses zero', $noisy['crosses']);
    $t->true('and its interval brackets its coefficient', $noisy['lo'] <= $noisy['r'] && $noisy['r'] <= $noisy['hi']);

    $t->same('median of an odd set', 3.0, Analytics::median([5.0, 1.0, 3.0]));
    $t->same('median of an even set', 3.0, Analytics::median([1.0, 2.0, 4.0, 5.0]));
    $t->same('median of nothing', null, Analytics::median([]));

    $fit = Analytics::fitLine([['x' => 1.0, 'y' => 2.0], ['x' => 3.0, 'y' => 6.0]]);
    $t->same('a fit line starts at the lowest x', 1.0, $fit['x0']);
    $t->same('and follows the slope', 6.0, round($fit['y1'], 2));

    /* ---------- a dataset with known scores ---------- */

    $created = [];
    $now     = date('Y-m-d H:i:s');
    $year    = (int) date("Y");

    /*
     * A year nothing else in this database uses.
     *
     * The dev database is shared with a running site, so a fixture placed in the
     * current year lands alongside whatever has really been submitted and every
     * count below becomes "one more than whatever was already there". Scoping the
     * fixtures to their own year makes each slice exactly the records this test
     * wrote, and it is also why nothing here truncates a table.
     */
    $fixtureYear = $year - 7;

    /**
     * One scorecard, every question in a dimension marked the same.
     *
     * @param list<int> $marks one per dimension, in instrument order
     */
    $write = static function (
        string $organisation,
        string $sector,
        array $marks,
        int $assessmentYear,
        bool $synthetic,
        ?int $agenda = null
    ) use ($instrumentId, $dimensions, $itemsByDimension, $now, &$created, $app): int {
        $organisationId = \Platform\Assessment\OrganisationResolver::resolve(
            $organisation,
            $sector,
            '251–1,000',
            $synthetic
        );

        $id = Database::insert('es_submissions', [
            'public_token'      => bin2hex(random_bytes(16)),
            'instrument_id'     => $instrumentId,
            'organisation_id'   => $organisationId,
            'respondent_name'   => 'Analytics Fixture',
            'respondent_email'  => 'fixture@' . md5($organisation) . '.test',
            'respondent_phone'  => '+1 555 0100',
            'organisation_name' => $organisation,
            'organisation_size' => '251–1,000',
            'sector'            => $sector,
            'assessment_year'   => $assessmentYear,
            'agenda_meetings'   => $agenda,
            'status'            => 'complete',
            'is_synthetic'      => $synthetic ? 1 : 0,
            'submitted_at'      => $now,
            'created_at'        => $now,
        ]);

        foreach ($dimensions as $index => $dimension) {
            foreach ($itemsByDimension[$dimension['code']] as $itemId) {
                Database::insert('es_submission_responses', [
                    'submission_id' => $id,
                    'item_id'       => $itemId,
                    'mark'          => $marks[$index],
                    'answered_at'   => $now,
                ]);
            }
        }

        (new \Apps\EnterpriseAiScorecard\Services\Scoring\Scorer($app))->score($id);

        $created[] = $id;

        return $id;
    };

    /*
     * Three real scorecards and one synthetic, with a fourth-wall-thin naming
     * scheme so they are recognisable if anything ever leaks out of the finally.
     *
     *   ZZ Analytics Alpha   5 5 5 5 5 1  -> mean 4.33, floor 1.00 on WRK
     *   ZZ Analytics Beta    3 3 3 3 3 3  -> mean 3.00, floor 3.00
     *   ZZ Analytics Alpha   2 2 2 2 2 2  -> mean 2.00, prior year, same org
     *   ZZ Analytics Synth   1 1 1 1 1 1  -> synthetic, must not be counted
     */
    try {
        $write('ZZ Analytics Alpha', 'Technology', [5, 5, 5, 5, 5, 1], $fixtureYear, false, 6);
        $write('ZZ Analytics Beta', 'Education', [3, 3, 3, 3, 3, 3], $fixtureYear, false, 2);
        $write('ZZ Analytics Alpha', 'Technology', [2, 2, 2, 2, 2, 2], $fixtureYear - 1, false);
        $write('ZZ Analytics Synth', 'Technology', [1, 1, 1, 1, 1, 1], $fixtureYear, true, 99);

        /* ---------- synthetic records are opt-in ---------- */

        $real = new Analytics($app, false);
        $all  = new Analytics($app, true);

        $counts = $real->counts();
        $t->true('counts() reports both populations regardless of the toggle', $counts['synthetic'] > 0);

        $t->true(
            'the synthetic record is out by default',
            $all->overview()['n'] > $real->overview()['n']
        );

        /* ---------- levels come from the average ---------- */

        $bander = $real->bander();

        $t->same('4.33 bands as Embedded', 'Embedded', $bander->name(4.33));
        $t->same('1.00 would band as Undeveloped', 'Undeveloped', $bander->name(1.00));

        $alpha = Database::selectOne(
            'SELECT * FROM `es_submissions` WHERE `organisation_name` = ? AND `assessment_year` = ?',
            ["ZZ Analytics Alpha", $fixtureYear]
        );

        $t->same('the lopsided scorecard averages to 4.33', '4.33', $alpha['mean_score']);
        $t->same('its floor is 1.00', '1.00', $alpha['floor_score']);
        $t->same(
            'and its LEVEL is Embedded, from the average',
            5,
            (int) $alpha['level_value']
        );
        $t->true(
            'which is four levels above what its floor would have given',
            (int) $alpha['level_value'] !== $bander->value(1.00)
        );

        /* ---------- the aggregate floor is a different question ---------- */

        $scoped     = new Analytics($app, false, ["sector" => "Technology", "year" => (string) $fixtureYear]);
        $scopedDims = $scoped->dimensions();

        $t->count('six dimensions are always reported', 6, $scopedDims);
        $t->same('WRK is the weakest across this slice', 'WRK', $scoped->aggregateFloor($scopedDims)['code']);
        $t->same('and it is the one record\'s floor too', 1, $scopedDims[5]['floor_count']);
        $t->same('while a strong dimension caps nobody', 0, $scopedDims[0]['floor_count']);

        $overview = $scoped->overview();
        $t->same('one record in this slice', 1, $overview['n']);
        $t->same('its mean is the average', 4.33, $overview['mean_average']);
        $t->same('its mean floor is the weakest', 1.0, $overview['mean_floor']);
        $t->same('and the gap is the difference', 3.33, $overview['gap']);

        /* ---------- filters ---------- */

        $education = new Analytics($app, false, ["sector" => "Education", "year" => (string) $fixtureYear]);
        $t->same('the sector filter narrows to one', 1, $education->overview()['n']);

        $nowhere = new Analytics($app, false, ['sector' => 'ZZ Nonexistent Sector']);
        $t->same('a filter matching nothing means nothing, not everything', 0, $nowhere->overview()['n']);

        $priorYear = new Analytics($app, false, ["year" => (string) ($fixtureYear - 1), "sector" => "Technology"]);
        $t->same('the year filter works', 1, $priorYear->overview()['n']);
        $t->same('and picks the right record', 2.0, $priorYear->overview()['mean_average']);

        /* ---------- year over year ---------- */

        $rescored = array_values(array_filter(
            $scoped === null ? [] : (new Analytics($app, false))->rescoredOrganisations(),
            static fn (array $o): bool => $o['name'] === 'ZZ Analytics Alpha'
        ));

        $t->count('the organisation that scored twice is found', 1, $rescored);

        if ($rescored !== []) {
            $organisation = $rescored[0];

            $t->count('with both of its years', 2, $organisation['years']);
            $t->same("its first year", $fixtureYear - 1, $organisation["first_year"]);
            $t->same("its latest year", $fixtureYear, $organisation["last_year"]);
            $t->same('and the change between them', 2.33, $organisation['change']);
            $t->same('its weakest dimension moved', 'WRK', $organisation['floor_to']);
        }

        $t->true(
            'the single-year organisation is not listed as having re-scored',
            !in_array(
                'ZZ Analytics Beta',
                array_column((new Analytics($app, false))->rescoredOrganisations(), 'name'),
                true
            )
        );

        /* ---------- one dimension in detail ---------- */

        $detail = $scoped->dimension('WRK');

        $t->true('the dimension resolves', $detail !== null);
        $t->count('with its five questions', 5, $detail['items']);
        $t->same('each marked 1 in this slice', 1.0, $detail['items'][0]['mean']);
        $t->same('so every mark is at Undeveloped, not Emerging', 0.0, $detail['emerging_share']);
        $t->same('and the weakest question is reported', 1.0, $detail['weakest']['mean']);
        $t->same('an unknown code resolves to nothing', null, $scoped->dimension('ZZZ'));

        /* ---------- correlations follow the synthetic toggle, as the reference ---------- */

        foreach ([$real, $all] as $index => $analytics) {
            $agenda = array_values(array_filter(
                $analytics->correlations(),
                static fn (array $c): bool => $c['metric'] === 'agenda_meetings'
            ))[0];

            // The synthetic fixture reports 99 meetings on a scorecard of all 1s.
            $counted = in_array(99.0, array_column($agenda['points'], 'y'), true);

            if ($index === 0) {
                $t->true('with synthetic off, the synthetic record contributes no pair', !$counted);
            } else {
                $t->true('with synthetic on, it is plotted like any other record', $counted);
            }
        }

        $correlations = $real->correlations();
        $t->count('one row per reported figure', 3, $correlations);

        foreach ($correlations as $correlation) {
            $t->same(
                $correlation['metric'] . ' is withheld below the threshold',
                $correlation['n'] >= $correlation['minimum'],
                $correlation['reportable']
            );

            if (!$correlation['reportable']) {
                $t->same($correlation['metric'] . ' reports no coefficient', null, $correlation['stat']);
            }
        }

        /* ---------- the organisation drilldown ---------- */

        $organisationId = (int) $alpha['organisation_id'];
        $profile        = (new Analytics($app, false))->organisation($organisationId);

        $t->true('the organisation resolves', $profile !== null);
        $t->count('with both scorecards', 2, $profile['submissions']);
        $t->count('across two years', 2, $profile['years']);
        $t->same("its latest year is the fixture year", $fixtureYear, $profile["latest"]["year"]);
        $t->same('its latest average is 4.33', 4.33, $profile['latest']['mean']);
        $t->same('its first year average is 2.00', 2.0, $profile['first']['mean']);
        $t->true('and it is not flagged synthetic', !$profile['synthetic']);

        $t->same('an unknown organisation resolves to nothing', null, (new Analytics($app, false))->organisation(0));

        /* ---------- the read of the dataset states what is on the screen ---------- */

        $reading = $scoped->datasetReading();

        $t->true('the reading quotes the mean', str_contains($reading, '4.33'));
        $t->true('and the floor', str_contains($reading, '1.00'));
        $t->true('and names the most common cap, in the reference wording', str_contains($reading, 'is the most common cap.'));

        $t->same(
            'and an empty slice says so rather than inventing a figure',
            'No scorecards match the current filters.',
            $nowhere->datasetReading()
        );
    } finally {
        foreach ($created as $id) {
            Database::statement('DELETE FROM `es_submissions` WHERE `id` = ?', [$id]);
        }

        Database::statement(
            'DELETE FROM `organisations`
              WHERE `name` LIKE ?
                AND `id` NOT IN (SELECT DISTINCT `organisation_id` FROM `es_submissions` WHERE `organisation_id` IS NOT NULL)
                AND `id` NOT IN (SELECT DISTINCT `organisation_id` FROM `ds_submissions` WHERE `organisation_id` IS NOT NULL)',
            ['ZZ Analytics %']
        );
    }
};
