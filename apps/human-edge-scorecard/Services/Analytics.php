<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

use Platform\Core\AppManifest;

/**
 * The page's renderAnalytics() and its six panes — Index, Skills, Practices,
 * Segments, AI use and Data quality — computed on the server over Records.
 *
 * Each pane method returns the figures its view prints, worked out by the same
 * steps as the page's paneIndex(), paneSkill(), panePractices(),
 * paneSegments(), paneAI()/paneCorr() and paneQuality(), in the same order and
 * with the same rounding (Stats). The filters are the page's `F`: tab,
 * sector, synthetic, dim and seg — URL parameters here, so a view can be
 * linked to and reloaded.
 */
final class Analytics
{
    public const TABS = [
        'index'     => 'Index',
        'skill'     => 'Skills',
        'practices' => 'Practices',
        'segments'  => 'Segments',
        'ai'        => 'AI use',
        'quality'   => 'Data quality',
    ];

    private Instrument $instrument;

    private Stats $stats;

    /** @var array<string, mixed>|null the extracted reference lists and thresholds */
    private ?array $reference = null;

    public function __construct(private readonly AppManifest $app)
    {
        $this->instrument = new Instrument($app);
        $this->stats      = new Stats($this->instrument);
    }

    public function stats(): Stats
    {
        return $this->stats;
    }

    public function instrument(): Instrument
    {
        return $this->instrument;
    }

    /** Every scorecard in the console (all are research-consented or synthetic). @return list<array<string, mixed>> */
    public function all(): array
    {
        return (new Records($this->app))->all();
    }

    /** @return array{real:int, synthetic:int} */
    public function counts(): array
    {
        $all  = $this->all();
        $real = count(array_filter($all, static fn (array $r): bool => !$r['synthetic']));

        return ['real' => $real, 'synthetic' => count($all) - $real];
    }

    /**
     * The include-synthetic toggle, with the page's autoIncludeSynthetic():
     * a dataset holding nothing but demo records opens with them shown rather
     * than on an empty screen. An explicit choice in the URL always wins.
     */
    public function includeSynthetic(array $query): bool
    {
        if (array_key_exists('synthetic', $query)) {
            return $query['synthetic'] === '1';
        }

        $counts = $this->counts();

        return $counts['real'] === 0 && $counts['synthetic'] > 0;
    }

    /** The page's F. @return array{tab:string, sector:string, synthetic:bool, dim:string, seg:string} */
    public function filters(array $query): array
    {
        $tab = (string) ($query['tab'] ?? 'index');
        $dim = strtoupper((string) ($query['dim'] ?? ''));
        $seg = (string) ($query['seg'] ?? 'role');

        return [
            'tab'       => isset(self::TABS[$tab]) ? $tab : 'index',
            'sector'    => (string) ($query['sector'] ?? 'all'),
            'synthetic' => $this->includeSynthetic($query),
            'dim'       => isset($this->instrument->dimBy()[$dim]) ? $dim : (string) $this->instrument->dims()[0]['code'],
            'seg'       => isset($this->segments()[$seg]) ? $seg : 'role',
        ];
    }

    /** analyticsRecords(): synthetic and sector filters applied. @return list<array<string, mixed>> */
    public function filtered(array $f): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool =>
            ($f['synthetic'] || !$r['synthetic'])
            && ($f['sector'] === 'all' || ($r['org']['sector'] ?? null) === $f['sector'])));
    }

    /** The Sector filter's options: every sector in the dataset, sorted. @return list<string> */
    public function sectorOptions(): array
    {
        $sectors = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => (string) ($r['org']['sector'] ?? ''),
            $this->all()
        ))));
        sort($sectors, SORT_STRING);

        return $sectors;
    }

    /** The extracted lists the panes use (METRICS, AI_BANDS, AI_ITEMS) and thresholds. @return array<string, mixed> */
    public function reference(): array
    {
        if ($this->reference === null) {
            $data = json_decode((string) file_get_contents($this->app->path('database/seeds/data/instrument-v1.json')), true, 512, JSON_THROW_ON_ERROR);
            $this->reference = $data['reference'] + ['thresholds' => $data['thresholds']];
        }

        return $this->reference;
    }

    public function corrMinN(): int
    {
        return (int) $this->app->config('scoring.correlation_min_n', 30);
    }

    public function segMinN(): int
    {
        return (int) $this->app->config('scoring.segment_min_n', 5);
    }

    /** METRICS: [{k, label}]. @return list<array{k:string, label:string}> */
    public function metrics(): array
    {
        return array_map(static fn (array $m): array => ['k' => $m['key'], 'label' => $m['label']], $this->reference()['correlation_metrics']);
    }

    /* ------------------------------------------------------------------ */
    /* Index                                                                */
    /* ------------------------------------------------------------------ */

    /** paneIndex(). @return array<string, mixed> */
    public function index(array $recs): array
    {
        $rows = [['name' => 'Overall', 'sub' => 'All eighteen answers', 'vals' => array_column($recs, 'mean')]];

        foreach ($this->instrument->dims() as $dim) {
            $rows[] = ['name' => $dim['name'], 'sub' => $dim['sub'], 'vals' => array_map(static fn (array $r) => $r['dims'][$dim['code']] ?? null, $recs)];
        }

        foreach ($rows as &$row) {
            $row['shares'] = $this->stats->levelShares($row['vals']);
            $row['d']      = Stats::describe($row['vals']);
        }
        unset($row);

        return ['overall' => Stats::describe(array_column($recs, 'mean')), 'rows' => $rows];
    }

    /* ------------------------------------------------------------------ */
    /* Skills                                                               */
    /* ------------------------------------------------------------------ */

    /** paneSkill(). @return array<string, mixed> */
    public function skill(array $recs, string $code): array
    {
        $dim  = $this->instrument->dimBy()[$code];
        $vals = array_map(static fn (array $r) => $r['dims'][$code] ?? null, $recs);
        $nums = Stats::nums($vals);

        // A skill score is the mean of three answers: one of thirteen values from 1 to 5.
        $hist = [];
        for ($i = 0; $i < 13; $i++) {
            $x = 1 + $i / 3;
            $hist[] = ['x' => $x, 'n' => count(array_filter($nums, static fn (float $v): bool => abs($v - $x) < .01))];
        }
        $top = max(1, ...array_column($hist, 'n'));

        $items = [];
        foreach ($dim['qs'] as $i => $q) {
            $v = $this->itemVals($recs, $code, $i);
            $items[] = [
                'q'    => $q,
                'n'    => count($v),
                'st'   => Stats::describe($v),
                'dist' => array_map(static fn (int $k): int => count(array_filter($v, static fn (float $x): bool => $x == $k)), [1, 2, 3, 4, 5]),
            ];
        }
        usort($items, static fn (array $a, array $b): int => ($a['st']['mean'] ?? 9) <=> ($b['st']['mean'] ?? 9));

        return [
            'dim'   => $dim,
            'd'     => Stats::describe($vals),
            'sh'    => $this->stats->levelShares($vals),
            'hist'  => $hist,
            'top'   => $top,
            'items' => $items,
        ];
    }

    /** itemVals(recs, code, i): the answers to one question. @return list<float> */
    public function itemVals(array $recs, string $code, int $i): array
    {
        return Stats::nums(array_map(static fn (array $r) => $r['responses'][$code][$i] ?? null, $recs));
    }

    /** lab(x): 1, 1.33, 1.67, 2 … @return string */
    public static function histLabel(float $x): string
    {
        return abs($x - round($x)) < .01 ? (string) (int) round($x) : Stats::toFixed($x, 2);
    }

    /* ------------------------------------------------------------------ */
    /* Practices                                                            */
    /* ------------------------------------------------------------------ */

    /** panePractices(): all eighteen, lowest mean first. @return list<array<string, mixed>> */
    public function practices(array $recs): array
    {
        $picks     = [];
        $withSteps = 0;

        foreach ($recs as $r) {
            $p = $this->stats->nextStepPicks($r);
            if ($p !== []) {
                $withSteps++;
            }
            foreach ($p as $x) {
                $key = $x['code'] . '_' . $x['qi'];
                $picks[$key] = ($picks[$key] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($this->instrument->dims() as $dim) {
            foreach ($dim['qs'] as $i => $q) {
                $v = $this->itemVals($recs, $dim['code'], $i);
                $n = count($v);
                $rows[] = [
                    'd'    => $dim,
                    'q'    => $q,
                    'st'   => Stats::describe($v),
                    'low'  => $n ? count(array_filter($v, static fn (float $x): bool => $x <= 2)) / $n : null,
                    'top'  => $n ? count(array_filter($v, static fn (float $x): bool => $x == 5)) / $n : null,
                    'next' => $withSteps ? ($picks[$dim['code'] . '_' . $i] ?? 0) / $withSteps : null,
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => ($a['st']['mean'] ?? 9) <=> ($b['st']['mean'] ?? 9));

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Segments                                                             */
    /* ------------------------------------------------------------------ */

    /** aiBand(h) */
    public function aiBand(mixed $hours): string
    {
        $bands = $this->reference()['ai_bands'];

        return match (true) {
            $hours === null => 'Not given',
            (float) $hours == 0.0 => $bands[0],
            (float) $hours <= 5 => $bands[1],
            (float) $hours <= 15 => $bands[2],
            default => $bands[3],
        };
    }

    /** SEGMENTS: key => [label, keyOf, order]. @return array<string, array{0:string, 1:\Closure, 2:list<string>}> */
    public function segments(): array
    {
        $given = static fn (?string $v): string => $v === null || $v === '' ? 'Not given' : $v;

        return [
            'role'   => ['Role', static fn (array $r): string => $given($r['org']['role'] ?? null), $this->instrument->options('roles')],
            'size'   => ['Organization size', static fn (array $r): string => $given($r['org']['size'] ?? null), $this->instrument->options('organisation_sizes')],
            'sector' => ['Sector', static fn (array $r): string => $given($r['org']['sector'] ?? null), $this->instrument->options('sectors')],
            'tenure' => ['Years in role', static fn (array $r): string => $given($r['org']['tenure'] ?? null), $this->instrument->options('tenures')],
            'aiuse'  => ['AI use', fn (array $r): string => $this->aiBand($r['org']['aiuse'] ?? null), $this->reference()['ai_bands']],
        ];
    }

    /** paneSegments(). @return array<string, mixed> */
    public function segmentTable(array $recs, string $key): array
    {
        [$label, $keyOf, $order] = $this->segments()[$key];

        $groups = [];
        foreach ($recs as $r) {
            $groups[$keyOf($r)][] = $r;
        }

        $others = array_values(array_filter(array_keys($groups), static fn ($g): bool => !in_array((string) $g, $order, true)));
        sort($others, SORT_STRING);
        $names = array_merge(array_values(array_filter($order, static fn (string $g): bool => isset($groups[$g]))), array_map('strval', $others));

        $rows = [];
        foreach ($names as $name) {
            $rs = $groups[$name];
            if (count($rs) < $this->segMinN()) {
                $rows[] = ['name' => $name, 'n' => count($rs), 'suppressed' => true];
                continue;
            }

            $medians = [];
            foreach ($this->instrument->dims() as $dim) {
                $medians[$dim['code']] = Stats::quantile(array_map(static fn (array $r) => $r['dims'][$dim['code']] ?? null, $rs), .5);
            }

            $rows[] = ['name' => $name, 'n' => count($rs), 'suppressed' => false, 'o' => Stats::describe(array_column($rs, 'mean')), 'medians' => $medians];
        }

        return ['key' => $key, 'label' => $label, 'rows' => $rows];
    }

    /* ------------------------------------------------------------------ */
    /* AI use                                                               */
    /* ------------------------------------------------------------------ */

    /** pairsFor(recs, metric, get): [xs, ys]. @return array{0:list<float>, 1:list<float>} */
    public static function pairsFor(array $recs, string $metric, \Closure $get): array
    {
        $xs = [];
        $ys = [];

        foreach ($recs as $r) {
            $y = $r['org'][$metric] ?? null;
            $x = $get($r);
            if ($y !== null && (is_int($x) || is_float($x)) && is_finite((float) $x)) {
                $xs[] = (float) $x;
                $ys[] = (float) $y;
            }
        }

        return [$xs, $ys];
    }

    /** corrCell(xs, ys): withheld below the minimum, else r and its interval. @return array<string, mixed> */
    public function corrCell(array $xs, array $ys): array
    {
        if (count($xs) < $this->corrMinN()) {
            return ['withheld' => true, 'n' => count($xs)];
        }

        return ['withheld' => false, 'n' => count($xs), 'st' => Stats::pearson($xs, $ys)];
    }

    /** paneCorr(): the overall score against each metric, with its scatter. @return list<array<string, mixed>> */
    public function correlations(array $recs): array
    {
        $rows = [];

        foreach ($this->metrics() as $m) {
            $pairs = [];
            foreach ($recs as $r) {
                if (($r['org'][$m['k']] ?? null) !== null && $r['mean'] !== null) {
                    $pairs[] = ['x' => (float) $r['mean'], 'y' => (float) $r['org'][$m['k']]];
                }
            }

            $stat = count($pairs) >= 3 ? Stats::pearson(array_column($pairs, 'x'), array_column($pairs, 'y')) : null;

            // The dashed fit line, drawn only where the coefficient is reported.
            $fit = null;
            if (count($pairs) >= $this->corrMinN() && $stat !== null) {
                $xs  = array_column($pairs, 'x');
                $ys  = array_column($pairs, 'y');
                $mx  = Stats::mean($xs);
                $my  = Stats::mean($ys);
                $sxy = $sxx = 0.0;
                foreach ($xs as $i => $x) {
                    $sxy += ($x - $mx) * ($ys[$i] - $my);
                    $sxx += ($x - $mx) ** 2;
                }
                $b   = $sxy / $sxx;
                $a   = $my - $b * $mx;
                $x0  = min($xs);
                $x1  = max($xs);
                $fit = [['x' => $x0, 'y' => $a + $b * $x0], ['x' => $x1, 'y' => $a + $b * $x1]];
            }

            $rows[] = ['m' => $m, 'pairs' => $pairs, 'stat' => $stat, 'ok' => count($pairs) >= $this->corrMinN(), 'fit' => $fit];
        }

        return $rows;
    }

    /** paneAI(): each skill and each practice against each metric. @return array<string, mixed> */
    public function ai(array $recs): array
    {
        $aiItems = $this->reference()['ai_items'];
        $dimBy   = $this->instrument->dimBy();
        $names   = array_map(static function (string $k) use ($dimBy): string {
            [$c, $i] = explode('_', $k);

            return $dimBy[$c]['qs'][(int) $i]['t'];
        }, $aiItems);

        $skills = [];
        foreach ($this->instrument->dims() as $dim) {
            $cells = [];
            foreach ($this->metrics() as $m) {
                [$x, $y] = self::pairsFor($recs, $m['k'], static fn (array $r) => $r['dims'][$dim['code']] ?? null);
                $cells[] = $this->corrCell($x, $y);
            }
            $skills[] = ['d' => $dim, 'cells' => $cells];
        }

        $practices = [];
        foreach ($this->instrument->dims() as $dim) {
            foreach ($dim['qs'] as $i => $q) {
                $cells = [];
                foreach ($this->metrics() as $m) {
                    [$x, $y] = self::pairsFor($recs, $m['k'], static fn (array $r) => $r['responses'][$dim['code']][$i] ?? null);
                    $cells[] = $this->corrCell($x, $y);
                }
                $practices[] = ['d' => $dim, 'q' => $q, 'hl' => in_array($dim['code'] . '_' . $i, $aiItems, true), 'cells' => $cells];
            }
        }

        return ['names' => $names, 'skills' => $skills, 'practices' => $practices, 'corr' => $this->correlations($recs)];
    }

    /* ------------------------------------------------------------------ */
    /* Data quality                                                         */
    /* ------------------------------------------------------------------ */

    /** paneQuality(). @return array<string, mixed> */
    public function quality(array $recs): array
    {
        $dims   = $this->instrument->dims();
        $matrix = [];
        $off    = [];

        foreach ($dims as $i => $a) {
            foreach ($dims as $j => $b) {
                if ($i === $j) {
                    $matrix[$i][$j] = null;
                    continue;
                }
                $xs = $ys = [];
                foreach ($recs as $r) {
                    $va = $r['dims'][$a['code']] ?? null;
                    $vb = $r['dims'][$b['code']] ?? null;
                    if ((is_int($va) || is_float($va)) && (is_int($vb) || is_float($vb))) {
                        $xs[] = (float) $va;
                        $ys[] = (float) $vb;
                    }
                }
                $st = Stats::pearson($xs, $ys);
                $matrix[$i][$j] = $st === null ? null : $st['r'];
                if ($j > $i && $matrix[$i][$j] !== null) {
                    $off[] = $matrix[$i][$j];
                }
            }
        }

        $alphas = [];
        foreach ($dims as $dim) {
            $rows = [];
            foreach ($recs as $r) {
                $row = [];
                foreach ([0, 1, 2] as $i) {
                    $row[] = $r['responses'][$dim['code']][$i] ?? null;
                }
                if (!in_array(null, $row, true)) {
                    $rows[] = array_map('floatval', $row);
                }
            }
            $alphas[] = ['d' => $dim, 'n' => count($rows), 'a' => Stats::cronbach($rows)];
        }

        $full = [];
        foreach ($recs as $r) {
            $row = [];
            foreach ($dims as $dim) {
                foreach ([0, 1, 2] as $i) {
                    $row[] = $r['responses'][$dim['code']][$i] ?? null;
                }
            }
            if (!in_array(null, $row, true)) {
                $full[] = $row;
            }
        }

        $same  = count(array_filter($full, static fn (array $a): bool => count(array_unique($a)) === 1));
        $high  = count(array_filter($full, static fn (array $a): bool => min($a) >= 4));
        $all   = array_merge(...($full ?: [[]]));
        $fives = count(array_filter($all, static fn ($x): bool => $x === 5));

        return [
            'matrix' => $matrix,
            'offMean' => $off === [] ? null : Stats::mean($off),
            'alphas' => $alphas,
            'full'   => count($full),
            'same'   => $same,
            'high'   => $high,
            'answers' => count($all),
            'fives'  => $fives,
        ];
    }

    /** shade(v): the matrix cell's blue, by strength. */
    public static function shade(?float $v): string
    {
        return $v === null ? 'transparent' : 'rgba(61,130,196,' . Stats::toFixed(min(.85, abs($v)), 2) . ')';
    }

    /* ------------------------------------------------------------------ */
    /* Organization                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * openOrg(name): every scorecard naming this organization, oldest first,
     * its per-skill means and spread, and the level of their average.
     *
     * @return array<string, mixed>|null
     */
    public function organisation(string $name): ?array
    {
        if ($name === '') {
            return null;   // a blank organization name means none given, never an organization
        }

        $recs = array_values(array_filter($this->all(), static fn (array $r): bool => ($r['org']['org'] ?? '') === $name));

        if ($recs === []) {
            return null;
        }

        usort($recs, static fn (array $a, array $b): int => strtotime((string) $a['timestamp']) <=> strtotime((string) $b['timestamp']));

        $agg  = $this->stats->aggregate($recs);
        $dims = Stats::aggDims($agg);

        return [
            'name'      => $name,
            'recs'      => $recs,
            'agg'       => $agg,
            'dims'      => $dims,
            'summary'   => $this->stats->summarize($dims),
            'sector'    => (string) ($recs[0]['org']['sector'] ?? ''),
            'size'      => (string) ($recs[0]['org']['size'] ?? ''),
            'synthetic' => (bool) array_filter($recs, static fn (array $r): bool => $r['synthetic']),
        ];
    }
}
