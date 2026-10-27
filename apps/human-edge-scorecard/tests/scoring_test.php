<?php

declare(strict_types=1);

use Apps\HumanEdgeScorecard\Services\Instrument;
use Apps\HumanEdgeScorecard\Services\Scoring\MeanRule;
use Apps\HumanEdgeScorecard\Services\Stats;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * The console scores and rounds exactly as the page does.
 *
 * The page rounds with toFixed() — the exact binary value, ties upward — and
 * bands with BAND_EDGES. PHP's round() disagrees on values like 1.005, so a
 * mean on an edge could land in a different level on the console than in the
 * browser. These cases pin the port.
 *
 * Pure computation: nothing in the database is touched.
 */
return static function (Assert $t): void {
    $app   = AppRegistry::get('human-edge-scorecard');
    $stats = new Stats(new Instrument($app));

    // Number.prototype.toFixed(2), as Node prints it for each.
    $cases = ['1.005' => '1.00', '2.675' => '2.67', '1.335' => '1.33', '0.125' => '0.13', '3.445' => '3.44', '2.5' => '2.50',
        '-0.005' => '-0.01', '0.995' => '0.99', '9.995' => '9.99', '0' => '0.00', '123.456' => '123.46', '-1.5' => '-1.50'];
    $wrong = [];
    foreach ($cases as $in => $out) {
        if (Stats::toFixed((float) $in, 2) !== $out) {
            $wrong[] = $in . ' gave ' . Stats::toFixed((float) $in, 2);
        }
    }
    $t->same('toFixed(2) matches JavaScript on every case', [], $wrong);
    $t->same('toFixed(3) of a third', '0.333', Stats::toFixed(1 / 3, 3));
    $t->same('Math.round(2.5) is 3, Math.round(-2.5) is -2', [3, -2], [Stats::jsRound(2.5), Stats::jsRound(-2.5)]);
    $t->same('pct()', ['33%', '—', '0%'], [Stats::pct(1 / 3), Stats::pct(null), Stats::pct(0)]);

    // band(): [1.80, 2.60, 3.40, 4.20) cut points.
    $bands = [];
    foreach ([1.0, 1.79, 1.8, 2.59, 2.6, 3.39, 3.4, 4.19, 4.2, 5.0] as $score) {
        $bands[] = $stats->band($score)['name'];
    }
    $t->same('band edges', ['Rare', 'Rare', 'Occasional', 'Occasional', 'Developing', 'Developing', 'Consistent', 'Consistent', 'Habitual', 'Habitual'], $bands);
    $t->same('band(null) is null', null, $stats->band(null));

    // summarize(): the mean of the scored skills, rounded like the page.
    $s = $stats->summarize(['JDG' => 3.67, 'ADP' => 3.0, 'EMP' => 4.0, 'CON' => 3.0, 'IMG' => 3.0, 'AUT' => 4.0]);
    $t->same('summarize: mean', 3.45, $s['mean']);
    $t->same('summarize: level', 'Consistent', $s['level']['name']);
    $u = $stats->summarize(['JDG' => 2.0, 'ADP' => null, 'EMP' => null, 'CON' => null, 'IMG' => null, 'AUT' => 4.0]);
    $t->same('summarize: an unscored skill is left out, not zero', 3.0, $u['mean']);

    // MeanRule, the stored copy of the same rule.
    $rule = (new MeanRule())->summarise([10 => 3.67, 11 => 3.0, 12 => 4.0, 13 => 3.0, 14 => 3.0, 15 => 4.0]);
    $t->same('MeanRule agrees with summarize()', 3.45, $rule->mean);
    $floor = (new MeanRule())->floor([10 => 3.0, 11 => 2.0, 12 => 2.0, 13 => null]);
    $t->same('the floor ties to the first skill', [2.0, 11], [$floor['score'], $floor['dimension_id']]);

    // describe(): n, mean, sample sd, normal-approximation CI, quartiles.
    $d = Stats::describe([1, 2, 3, 4, 5]);
    $t->same('describe: n, mean, median, q1, q3', [5, 3.0, 3.0, 2.0, 4.0], [$d['n'], $d['mean'], $d['median'], $d['q1'], $d['q3']]);
    $t->same('describe: 95% CI', ['1.61', '4.39'], [Stats::f2($d['lo']), Stats::f2($d['hi'])]);
    $t->same('describe of nothing', 0, Stats::describe([null, 'x'])['n']);

    // pearson(): r and its Fisher-z interval, rounded to three places.
    $p = Stats::pearson([1, 2, 3, 4, 5], [2, 4, 5, 4, 5]);
    $t->same('pearson r', 0.775, $p['r']);
    $t->same('pearson below three pairs is null', null, Stats::pearson([1, 2], [1, 2]));

    // cronbach(): a known value.
    $alpha = Stats::cronbach([[1, 2, 2], [2, 3, 3], [3, 3, 4], [4, 5, 5]]);
    $t->same('cronbach alpha (the page computes 0.98)', '0.98', Stats::f2($alpha));

    // nextStepPicks(): up to four, lowest first, at most two per skill.
    $rec   = ['responses' => ['JDG' => [1, 1, 1], 'ADP' => [2, 5, 5], 'EMP' => [5, 5, 5], 'CON' => [5, 5, 5], 'IMG' => [5, 5, 5], 'AUT' => [5, 5, 5]]];
    $picks = array_map(static fn (array $p): string => $p['code'] . '_' . $p['qi'], $stats->nextStepPicks($rec));
    $t->same('next steps: two from JDG, then ADP, then the next lowest', ['JDG_0', 'JDG_1', 'ADP_0', 'ADP_1'], $picks);

    // orgCodes(): numbered by earliest record, never alphabetically.
    $code = Stats::orgCodes([
        ['org' => ['org' => 'Zeta'], 'timestamp' => '2026-01-01T00:00:00.000Z'],
        ['org' => ['org' => 'Alpha'], 'timestamp' => '2026-02-01T00:00:00.000Z'],
        ['org' => ['org' => ''], 'timestamp' => '2025-01-01T00:00:00.000Z'],
    ]);
    $t->same('orgCodes by first record', ['O001', 'O002', ''], [$code(['org' => ['org' => 'Zeta']]), $code(['org' => ['org' => 'Alpha']]), $code(['org' => ['org' => '']])]);

    $t->true('search folds accents and matches phone digits', Stats::matches(['userName' => 'José Ñúñez', 'org' => ['phone' => '+1 555 0123']], 'jose 5550123'));
};
