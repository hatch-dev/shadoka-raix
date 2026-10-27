<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services;

use Apps\AiExecutiveReadinessScorecard\Services\Scoring\PairedScoring;
use Platform\Core\AppManifest;

/**
 * What the admin screens compute, pane by pane — the reference's
 * renderAnalytics(), paneStats(), paneYoY(), paneDims(), paneCorr(),
 * paneCompare(), renderData(), openOrg() and writeGroupPDF(), over the records
 * Records::all() builds.
 *
 * The reference held every record in the browser and filtered them in
 * analyticsRecords(); this holds them for one request and filters them the same
 * way. The filters are URL parameters rather than JavaScript state, so a pane
 * can be linked to and reloaded.
 */
final class Analytics
{
    public const TABS = [
        'stats'   => 'Overview',
        'yoy'     => 'Year-over-year',
        'dims'    => 'Dimensions',
        'corr'    => 'Correlation',
        'compare' => 'Comparison',
    ];

    /** The reference's METRICS, in its order. */
    public const METRICS = [
        ['k' => 'agenda',  'label' => 'Executive meetings with AI as a decision item'],
        ['k' => 'edu',     'label' => 'Hours of AI education taken'],
        ['k' => 'systems', 'label' => 'AI systems on the inventory'],
    ];

    private ?Stats $stats = null;

    public function __construct(private readonly AppManifest $app)
    {
    }

    /* ------------------------------------------------------------ inputs -- */

    /** @return list<array<string, mixed>> every completed questionnaire, synthetic included */
    public function all(): array
    {
        return (new Records($this->app))->all(true);
    }

    public function stats(): Stats
    {
        if ($this->stats === null) {
            $data        = new InstrumentData($this->app);
            $this->stats = new Stats($data->scoring((int) $data->published()['id']));
        }

        return $this->stats;
    }

    /** The published instrument's dimensions, each with its items. @return list<array<string, mixed>> */
    public function dimensions(): array
    {
        $data = new InstrumentData($this->app);

        return $data->dimensions((int) $data->published()['id']);
    }

    /** @return array{real:int, synthetic:int} */
    public function counts(): array
    {
        $all       = $this->all();
        $synthetic = count(array_filter($all, static fn (array $r): bool => $r['synthetic']));

        return ['real' => count($all) - $synthetic, 'synthetic' => $synthetic];
    }

    /**
     * Whether synthetic records are in: the parameter when it is given,
     * otherwise ON exactly when the dataset holds records and none of them is
     * real — the reference's autoIncludeSynthetic(), so a demo-only dataset does
     * not open on an empty screen. An explicit synthetic=0 still wins.
     */
    public function includeSynthetic(array $query): bool
    {
        if (array_key_exists('synthetic', $query)) {
            return $query['synthetic'] === '1';
        }

        $counts = $this->counts();

        return $counts['synthetic'] > 0 && $counts['real'] === 0;
    }

    /**
     * The reference's `F`, read from the query string and made safe: a value
     * that names nothing falls back to its default rather than emptying a pane.
     *
     * @return array{tab:string, year:string, sector:string, band:string, scope:string, dim:string, synthetic:bool}
     */
    public function filters(array $query): array
    {
        $tab   = (string) ($query['tab'] ?? 'stats');
        $scope = (string) ($query['scope'] ?? 'self');
        $dim   = strtoupper((string) ($query['dim'] ?? 'all'));
        $band  = (string) ($query['band'] ?? 'all');

        $levels = array_column($this->stats()->bander()->rungs(), 'name');

        return [
            'tab'       => isset(self::TABS[$tab]) ? $tab : 'stats',
            'year'      => (string) ($query['year'] ?? 'all'),
            'sector'    => (string) ($query['sector'] ?? 'all'),
            'band'      => in_array($band, $levels, true) ? $band : 'all',
            'scope'     => Scopes::valid($scope) ? $scope : 'self',
            'dim'       => in_array($dim, $this->stats()->codes(), true) ? $dim : 'all',
            'synthetic' => $this->includeSynthetic($query),
        ];
    }

    /**
     * analyticsRecords(): the records the filters leave.
     *
     * @param array<string, mixed> $f
     * @return list<array<string, mixed>>
     */
    public function filtered(array $f): array
    {
        return array_values(array_filter($this->all(), static function (array $r) use ($f): bool {
            return ($f['synthetic'] || !$r['synthetic'])
                && ($f['year'] === 'all' || (string) $r['year'] === $f['year'])
                && ($f['sector'] === 'all' || $r['org']['sector'] === $f['sector'])
                && ($f['band'] === 'all' || $r['scores'][$f['scope']]['level'] === $f['band']);
        }));
    }

    /** filterDescription(), word for word. @param array<string, mixed> $f */
    public function filterDescription(array $f): string
    {
        return sprintf(
            'Year: %s; sector: %s; level: %s%s; synthetic records: %s.',
            $f['year'],
            $f['sector'],
            $f['band'],
            $f['band'] === 'all' ? '' : ' (' . ($f['scope'] === 'self' ? 'self-ratings' : 'ratings of leaders') . ')',
            $f['synthetic'] ? 'included' : 'excluded'
        );
    }

    /**
     * The filter bar's options — every year and sector in the WHOLE dataset, not
     * just the filtered part, as the reference built them from `all`.
     *
     * @return array{years:list<int>, sectors:list<string>}
     */
    public function filterOptions(): array
    {
        $all = $this->all();

        $years = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['year'], $all)));
        rsort($years);

        $sectors = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['org']['sector'], $all)));
        sort($sectors, SORT_STRING);

        return ['years' => $years, 'sectors' => $sectors];
    }

    /* ------------------------------------------------------------- panes -- */

    /**
     * paneStats(): the pooled profiles, level counts, the paired bars with
     * their spread, then by sector, where the floors sit, and the organizations.
     *
     * @param list<array<string, mixed>> $recs
     * @return array<string, mixed>
     */
    public function overview(array $recs): array
    {
        $stats   = $this->stats();
        $sectors = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['org']['sector'], $recs)));
        sort($sectors, SORT_STRING);

        $bySector = [];
        foreach ($sectors as $sector) {
            $group = array_values(array_filter($recs, static fn (array $r): bool => $r['org']['sector'] === $sector));
            $g     = $stats->groupScores($group);

            $bySector[] = [$sector, count($group), Stats::fmt($g['self']['mean']), Stats::fmt($g['leadership']['mean'])];
        }

        $floors = [];
        foreach ($this->dimensions() as $dimension) {
            $row = [$dimension['name']];
            foreach (PairedScoring::SCOPES as $scope) {
                $row[] = count(array_filter($recs, static fn (array $r): bool => $r['scores'][$scope]['floorCode'] === $dimension['code']));
            }
            $floors[] = $row;
        }

        $names = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['org']['org'], $recs)));
        sort($names, SORT_STRING);

        return [
            'questionnaires' => count($recs),
            'respondents'    => Stats::respondentCount($recs),
            'organisations'  => Stats::orgCount($recs),
            'scores'         => $stats->groupScores($recs),
            'spread'         => $stats->spread($recs),
            'level_rows'     => $stats->levelRows($recs),
            'level_counts'   => $stats->levelCounts($recs),
            'by_sector'      => $bySector,
            'floors'         => $floors,
            'names'          => $names,
        ];
    }

    /**
     * paneYoY(): annual means per scope, then the change among respondents who
     * answered in consecutive years (matched by organization and email; the
     * latest questionnaire in each year).
     *
     * @param list<array<string, mixed>> $recs
     * @return array<string, mixed>
     */
    public function yearOverYear(array $recs): array
    {
        $years = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['year'], $recs)));
        sort($years);

        $groups = array_map(
            static fn (int $y): array => array_values(array_filter($recs, static fn (array $r): bool => (int) $r['year'] === $y)),
            $years
        );

        $scopeMean = static fn (array $group, string $scope): ?float => Stats::mean(array_map(
            static fn (array $r): float => (float) $r['scores'][$scope]['mean'],
            $group
        ));

        $rows   = [];
        $series = ['self' => [], 'leadership' => []];

        foreach ($years as $i => $year) {
            $rows[] = [
                $year,
                count($groups[$i]),
                Stats::respondentCount($groups[$i]),
                Stats::orgCount($groups[$i]),
                Stats::fmt($scopeMean($groups[$i], 'self')),
                Stats::fmt($scopeMean($groups[$i], 'leadership')),
            ];

            foreach (PairedScoring::SCOPES as $scope) {
                $series[$scope][] = $scopeMean($groups[$i], $scope);
            }
        }

        $latest = static function (array $group): array {
            $map = [];
            foreach (array_reverse(Stats::newestFirst($group)) as $r) {
                $map[Stats::respondentKey($r)] = $r;
            }

            return $map;
        };

        $matched = [];
        for ($i = 1; $i < count($years); $i++) {
            $before = $latest($groups[$i - 1]);
            $after  = $latest($groups[$i]);

            $pairs = [];
            foreach ($after as $key => $r) {
                if (isset($before[$key])) {
                    $pairs[] = [$before[$key], $r];
                }
            }

            $row = [$years[$i - 1] . ' to ' . $years[$i], count($pairs)];
            foreach (PairedScoring::SCOPES as $scope) {
                $row[] = Stats::delta(Stats::mean(array_map(
                    static fn (array $p): float => (float) $p[1]['scores'][$scope]['mean'] - (float) $p[0]['scores'][$scope]['mean'],
                    $pairs
                )));
            }
            $matched[] = $row;
        }

        return ['years' => $years, 'rows' => $rows, 'series' => $series, 'matched' => $matched];
    }

    /**
     * paneDims(): for each question, the distribution of each scope's ratings.
     *
     * @param list<array<string, mixed>> $recs
     * @return list<array<string, mixed>>
     */
    public function dimensionDetail(array $recs, string $dim): array
    {
        $out = [];

        foreach ($this->dimensions() as $dimension) {
            if ($dim !== 'all' && $dimension['code'] !== $dim) {
                continue;
            }

            $code  = (string) $dimension['code'];
            $items = [];

            foreach ($dimension['items'] as $i => $item) {
                $rows = [];

                foreach (Scopes::all() as $scope) {
                    $values = array_map(static fn (array $r): int => (int) $r['responses'][$scope['key']][$code][$i], $recs);
                    $row    = [$scope['set_label'], count($values), Stats::fmt(Stats::mean($values)), Stats::fmt(Stats::sd($values))];

                    for ($n = 1; $n <= 5; $n++) {
                        $row[] = Stats::share(count(array_filter($values, static fn (int $v): bool => $v === $n)), count($values));
                    }

                    $rows[] = $row;
                }

                $items[] = ['number' => $code . ' ' . ($i + 1), 'question' => $item['question'], 'rows' => $rows];
            }

            $out[] = ['code' => $code, 'name' => $dimension['name'], 'items' => $items];
        }

        return $out;
    }

    /**
     * paneCorr(): each respondent's latest questionnaire, the chosen scope's
     * overall score against each reported figure. The coefficient is withheld
     * below the threshold; the scatter is drawn regardless.
     *
     * @param list<array<string, mixed>> $recs
     * @return list<array<string, mixed>>
     */
    public function correlations(array $recs, string $scope): array
    {
        $minimum = (int) $this->app->config('scoring.correlation_min_n', 30);
        $latest  = Stats::latestPerRespondent($recs);
        $out     = [];

        foreach (self::METRICS as $metric) {
            $pairs = [];
            foreach ($latest as $r) {
                $value = $r['org'][$metric['k']] ?? null;
                if (is_int($value) || is_float($value)) {
                    $pairs[] = ['x' => (float) $r['scores'][$scope]['mean'], 'y' => $value];
                }
            }

            $out[] = [
                'key'     => $metric['k'],
                'label'   => $metric['label'],
                'pairs'   => $pairs,
                'minimum' => $minimum,
                'stat'    => count($pairs) >= $minimum
                    ? Stats::pearson(array_column($pairs, 'x'), array_map('floatval', array_column($pairs, 'y')))
                    : null,
            ];
        }

        return $out;
    }

    /**
     * paneCompare(): the difference measures.
     *
     * @param list<array<string, mixed>> $recs
     * @return array<string, mixed>
     */
    public function comparison(array $recs): array
    {
        return [
            'scores' => $this->stats()->groupScores($recs),
            'rows'   => $this->stats()->comparisonRows($recs, $this->dimensions()),
        ];
    }

    /**
     * openOrg(): one organization, by the name its respondents typed — the
     * reference matched `r.org.org === name` exactly.
     *
     * @param list<array<string, mixed>> $pool
     * @return list<array<string, mixed>>
     */
    public static function forOrganisation(array $pool, string $name): array
    {
        return array_values(array_filter($pool, static fn (array $r): bool => $r['org']['org'] === $name));
    }

    /* ------------------------------------------------------------ report -- */

    /**
     * writeGroupPDF()'s figures, for pdf.js to draw: the research report and
     * the organization report are the same document over different records.
     *
     * @param list<array<string, mixed>> $recs
     * @return array<string, mixed>
     */
    public function report(array $recs, string $title, string $description, string $filename): array
    {
        $stats      = $this->stats();
        $dimensions = $this->dimensions();
        $spread     = $stats->spread($recs);

        $spreadRows = [];
        foreach ($dimensions as $dimension) {
            foreach (Scopes::all() as $scope) {
                $a = $spread[$scope['key']][$dimension['code']];
                $spreadRows[] = [
                    $dimension['code'] . ' ' . $scope['set_label'],
                    $a['n'],
                    Stats::fmt($a['mean']),
                    Stats::fmt($a['min']),
                    Stats::fmt($a['max']),
                    Stats::fmt($a['sd']),
                ];
            }
        }

        return [
            'title'       => $title,
            'description' => $description,
            'filename'    => $filename,
            'counts'      => [
                'questionnaires' => count($recs),
                'respondents'    => Stats::respondentCount($recs),
                'organisations'  => Stats::orgCount($recs),
            ],
            'scores'          => $stats->groupScores($recs),
            'dimensions'      => array_map(static fn (array $d): array => ['code' => $d['code'], 'name' => $d['name']], $dimensions),
            'rungs'           => $stats->bander()->rungs(),
            'spread_rows'     => $spreadRows,
            'comparison_rows' => array_map(
                static fn (array $r): array => [$r['label'], Stats::delta($r['mean']), $r['higher'], $r['equal'], $r['lower']],
                $stats->comparisonRows($recs, $dimensions)
            ),
            'level_rows'  => $stats->levelRows($recs),
            'record_rows' => array_map(
                static fn (array $r): array => [
                    ($r['synthetic'] ? '[SYNTHETIC] ' : '') . $r['org']['org'] . ' / ' . $r['userName'],
                    $r['year'],
                    Stats::fmt($r['scores']['self']['mean']),
                    Stats::fmt($r['scores']['leadership']['mean']),
                    Stats::delta($r['difference']['mean']),
                ],
                Stats::newestFirst($recs)
            ),
            'pdf_footnote' => (string) $this->app->config('result.pdf_footnote'),
            'copyright'    => (string) config('app.copyright'),
        ];
    }

    /* ------------------------------------------------------------ search -- */

    /**
     * recHaystack(): what a record is found by — name, contact, organization,
     * role, sector and both levels, folded — plus the phone's digits alone.
     */
    public static function haystack(array $r): string
    {
        return self::fold(implode(' ', array_filter([
            $r['userName'], $r['org']['name'] ?? null, $r['userEmail'], $r['org']['email'] ?? null,
            $r['org']['org'], $r['org']['role'] ?? null, $r['org']['sector'] ?? null,
            $r['scores']['self']['level'], $r['scores']['leadership']['level'],
        ], static fn ($v): bool => $v !== null && $v !== '')))
            . ' ' . preg_replace('/\D/', '', (string) ($r['userPhone'] ?: ($r['org']['phone'] ?? '')));
    }

    /** matchRec(): every word of the query appears in the haystack. */
    public static function matches(array $r, string $query): bool
    {
        $needle = trim(self::fold($query));

        if ($needle === '') {
            return true;
        }

        $hay = self::haystack($r);

        foreach (preg_split('/\s+/u', $needle) ?: [] as $word) {
            if ($word !== '' && !str_contains($hay, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * norm(): lowercase with accents removed, so "jose" finds "José". Uses
     * Normalizer when intl is installed, and a transliteration table when it is
     * not (it is not on every XAMPP).
     */
    public static function fold(string $value): string
    {
        $value = mb_strtolower($value);

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);

            return (string) preg_replace('/\p{Mn}+/u', '', (string) $decomposed);
        }

        return strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ě' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ō' => 'o', 'ő' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'ś' => 's', 'š' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ř' => 'r', 'ł' => 'l',
        ]);
    }
}
