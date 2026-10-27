<?php

declare(strict_types=1);

use Apps\AiExecutiveReadinessScorecard\Services\Scoring\PairedScoring;
use Apps\AiExecutiveReadinessScorecard\Services\Stats;
use Platform\Assessment\Bander;
use Platform\Core\Assert;

/**
 * The instrument's arithmetic, against the reference's scoreDims(), summarize()
 * and scorePair(), with no database.
 *
 * Every expected number below was worked by hand from the reference's rules:
 * each scope scored separately, a dimension is the mean of its three ratings,
 * the overall is the mean of all twelve, the level is the band of the UNROUNDED
 * overall, the floor is the lowest dimension with ties going to the first, and
 * the difference is self minus leadership.
 */
return static function (Assert $t): void {
    $bander = new Bander([
        ['value' => 1, 'name' => 'Undeveloped', 'colour_hex' => '#D2564E', 'band_min' => null, 'band_max' => 1.80],
        ['value' => 2, 'name' => 'Emerging',    'colour_hex' => '#8A9BB0', 'band_min' => 1.80, 'band_max' => 2.60],
        ['value' => 3, 'name' => 'Developing',  'colour_hex' => '#3D82C4', 'band_min' => 2.60, 'band_max' => 3.40],
        ['value' => 4, 'name' => 'Established', 'colour_hex' => '#4FA97C', 'band_min' => 3.40, 'band_max' => 4.20],
        ['value' => 5, 'name' => 'Embedded',    'colour_hex' => '#E8933F', 'band_min' => 4.20, 'band_max' => null],
    ]);

    $scoring = new PairedScoring([
        ['code' => 'JDG', 'items' => 3],
        ['code' => 'FLU', 'items' => 3],
        ['code' => 'STR', 'items' => 3],
        ['code' => 'CHG', 'items' => 3],
    ], $bander);

    $scope = static fn (array $jdg, array $flu, array $str, array $chg): array => [
        'JDG' => $jdg, 'FLU' => $flu, 'STR' => $str, 'CHG' => $chg,
    ];

    /* ---------- a complete pair ---------- */

    $responses = [
        'self'       => $scope([1, 2, 3], [2, 3, 4], [3, 4, 5], [4, 5, 5]),
        'leadership' => $scope([3, 3, 3], [3, 3, 3], [3, 3, 3], [3, 3, 3]),
    ];

    $r    = $scoring->scorePair($responses);
    $self = $r['scores']['self'];

    $t->same('self JDG is the mean of its three', 2.0, $self['dims']['JDG']);
    $t->true('self CHG is 14/3', abs($self['dims']['CHG'] - 14 / 3) < 1e-12);
    $t->true('self overall is the mean of all twelve (41/12)', abs($self['mean'] - 41 / 12) < 1e-12);
    $t->same('3.4167 is Established', 'Established', $self['level']['name']);
    $t->same('floor is the lowest dimension', 'JDG', $self['floorCode']);
    $t->same('leaders all 3 is Developing', 'Developing', $r['scores']['leadership']['level']['name']);
    $t->same('a tie on the floor goes to the FIRST dimension', 'JDG', $r['scores']['leadership']['floorCode']);
    $t->true('overall difference is self minus leadership', abs($r['difference']['mean'] - (41 / 12 - 3)) < 1e-12);
    $t->same('dimension difference', -1.0, $r['difference']['dims']['JDG']);
    $t->same('item difference', -2, $r['difference']['items']['JDG'][0]);
    $t->same('item difference, positive', 2, $r['difference']['items']['CHG'][2]);
    $t->true('complete pair is complete', $scoring->isComplete($responses));
    $t->same('24 ratings per questionnaire', 24, $scoring->ratingsPerQuestionnaire());

    /* ---------- the floor tie: later equal dimension does not take it ---------- */

    $tie = $scoring->summarize(['JDG' => 3.0, 'FLU' => 2.0, 'STR' => 2.0, 'CHG' => 4.0]);
    $t->same('FLU and STR tie; FLU is first', 'FLU', $tie['floorCode']);

    /* ---------- bands are [min, max): a score on a cut point goes up ---------- */

    $t->same('1.79 Undeveloped', 'Undeveloped', $bander->name(1.79));
    $t->same('1.80 Emerging', 'Emerging', $bander->name(1.80));
    $t->same('3.39 Developing', 'Developing', $bander->name(3.39));
    $t->same('3.40 Established', 'Established', $bander->name(3.40));
    $t->same('4.20 Embedded', 'Embedded', $bander->name(4.20));

    /* ---------- partial and invalid ratings ---------- */

    $partial = [
        'self'       => $scope([1, 2, 3], [2, 3], [3, 4, 5], [4, 5, 5]),
        'leadership' => $scope([3, 3, 3], [3, 3, 3], [3, 3, 3], [3, 3, 3]),
    ];
    $p = $scoring->scorePair($partial);

    $t->same('a dimension missing a rating is unscored', null, $p['scores']['self']['dims']['FLU']);
    $t->same('a partial profile has no overall', null, $p['scores']['self']['mean']);
    $t->same('…and no level', null, $p['scores']['self']['level']);
    $t->same('…but still a floor', 'JDG', $p['scores']['self']['floorCode']);
    $t->same('no overall difference without both overalls', null, $p['difference']['mean']);
    $t->same('no dimension difference where one side is unscored', null, $p['difference']['dims']['FLU']);
    $t->true('partial is not complete', !$scoring->isComplete($partial));

    $invalid = $responses;
    $invalid['leadership']['STR'][1] = 6;
    $t->same('a 6 is not a valid rating', null, $scoring->scorePair($invalid)['scores']['leadership']['dims']['STR']);
    $invalid['leadership']['STR'][1] = '4';
    $t->same('a string is not a valid rating', null, $scoring->scorePair($invalid)['scores']['leadership']['dims']['STR']);

    $empty = $scoring->scorePair(['self' => [], 'leadership' => []]);
    $t->same('nothing answered: no floor', null, $empty['scores']['self']['floor']);

    /* ---------- the reference's display helpers ---------- */

    $t->same('fmt', '3.42', Stats::fmt(41 / 12));
    $t->same('fmt null', '—', Stats::fmt(null));
    $t->same('delta positive', '+0.42', Stats::delta(41 / 12 - 3));
    $t->same('delta negative', '-1.00', Stats::delta(-1.0));
    $t->same('delta rounds tiny to 0.00', '0.00', Stats::delta(0.004));
    $t->same('share', '1 (33%)', Stats::share(1, 3));
    $t->same('share of nothing', '0 (0%)', Stats::share(0, 0));
    $t->same('sd of one value is 0', 0.0, Stats::sd([4]));
    $t->same('population sd', 1.0, Stats::sd([1, 3]));

    $perfect = Stats::pearson([1, 2, 3, 4, 5], [2, 4, 6, 8, 10]);
    $t->true('pearson of a straight line is 1', abs($perfect['r'] - 1) < 1e-9);
    $t->same('pearson needs four points', null, Stats::pearson([1, 2, 3], [1, 2, 3]));
    $t->same('pearson is undefined with no variation', null, Stats::pearson([1, 2, 3, 4], [5, 5, 5, 5]));

    $r2 = Stats::pearson([1, 2, 3, 4, 5, 6], [2, 1, 4, 3, 6, 5]);
    $t->true('pearson r for a known series (0.8286)', abs($r2['r'] - 0.8285714285714286) < 1e-9);
    $t->true('Fisher interval brackets r', $r2['lo'] < $r2['r'] && $r2['r'] < $r2['hi']);

    /* ---------- pooling ---------- */

    $stats   = new Stats($scoring);
    $recordA = ['scores' => $r['scores'], 'difference' => $r['difference']];
    $recordB = $scoring->scorePair([
        'self'       => $scope([5, 5, 5], [5, 5, 5], [5, 5, 5], [5, 5, 5]),
        'leadership' => $scope([1, 1, 1], [1, 1, 1], [1, 1, 1], [1, 1, 1]),
    ]);

    foreach ([&$recordA, &$recordB] as &$rec) {
        foreach ($rec['scores'] as &$profile) {
            $profile['level'] = $profile['level']['name'] ?? $profile['level'];
        }
    }
    unset($rec, $profile);

    $group = $stats->groupScores([$recordA, $recordB]);
    $t->same('pooled dimension is the mean of the two', 3.5, $group['self']['dims']['JDG']);
    $t->same('pooled leaders overall', 2.0, $group['leadership']['mean']);
    $t->same('pooled level from the pooled overall', 'Emerging', $group['leadership']['level']['name']);

    $levels = $stats->levelRows([$recordA, $recordB]);
    $t->same('level rows, one per rung', 5, count($levels));
    $t->same('Embedded self count', ['Embedded', '1 (50%)', '0 (0%)'], $levels[4]);

    $agg = $stats->aggregate([$recordA, $recordB], 'self');
    $t->same('aggregate n', 2, $agg['JDG']['n']);
    $t->same('aggregate min / max', [2.0, 5.0], [$agg['JDG']['min'], $agg['JDG']['max']]);
    $t->same('aggregate sd (population)', 1.5, $agg['JDG']['sd']);
};
