<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services;

use Platform\Assessment\Bander;
use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * Read-side aggregates for the admin analytics screens.
 *
 * Two rules this class enforces rather than leaves to its callers:
 *
 *  1. Synthetic records are excluded unless explicitly asked for, and the
 *     filter is applied here rather than re-implemented per query. The legacy
 *     build flagged demo records but re-wrote the exclusion at each call site,
 *     which is a rule you eventually forget somewhere.
 *
 *  2. Correlations follow the synthetic toggle like everything else, as in the
 *     reference (chosen 25 Sep 2026). The seeder ties demo "meetings with AI"
 *     and "hours of education" to each record's archetype, as the reference's
 *     does, so a coefficient over demo records is partly the generator's own.
 */
final class Analytics
{
    /** @var list<int>|null resolved once, then reused by every query */
    private ?array $filteredIds = null;

    /**
     * @param array{year?:string, sector?:string, band?:string} $filters
     *        from the dashboard's filter bar; 'all' or absent means no filter
     */
    public function __construct(
        private readonly AppManifest $app,
        private readonly bool $includeSynthetic = false,
        private readonly array $filters = [],
    ) {
    }

    /** The WHERE fragment every query shares. */
    private function scope(): string
    {
        $where = "s.`status` = 'complete'";

        if (!$this->includeSynthetic) {
            $where .= ' AND s.`is_synthetic` = 0';
        }

        /*
         * Year, sector and level are resolved to a list of submission ids by
         * one parameterised query, and the ids are then interpolated here.
         *
         * Two reasons. A dozen aggregate queries share this fragment and each
         * carries its own bindings in its own order; threading three more
         * through all of them is a lot of surface for a filter bar. And what
         * lands in the SQL is a list of integers this class read out of the
         * database itself — nothing from the request reaches the string.
         *
         * The cost is an IN list proportional to the dataset. This is a
         * research corpus of scorecards, not an event stream; if that ever
         * stops being true, this is the place that has to change.
         */
        if ($this->isFiltered()) {
            $ids = $this->filteredIds();

            // No match must mean "no records", not "no filter".
            $where .= $ids === []
                ? ' AND 1 = 0'
                : ' AND s.`id` IN (' . implode(',', $ids) . ')';
        }

        return $where;
    }

    /** Is any filter-bar control actually set? */
    private function isFiltered(): bool
    {
        foreach (['year', 'sector', 'band'] as $key) {
            $value = (string) ($this->filters[$key] ?? 'all');
            if ($value !== '' && $value !== 'all') {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> the submissions the filter bar allows through */
    private function filteredIds(): array
    {
        if ($this->filteredIds !== null) {
            return $this->filteredIds;
        }

        $sql = sprintf(
            'SELECT s.`id` FROM `%s` s LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id` WHERE 1 = 1',
            $this->submissions()
        );
        $bindings = [];

        $year = (string) ($this->filters['year'] ?? 'all');
        if ($year !== 'all' && $year !== '' && ctype_digit($year)) {
            $sql .= ' AND s.`assessment_year` = ?';
            $bindings[] = (int) $year;
        }

        $sector = (string) ($this->filters['sector'] ?? 'all');
        if ($sector !== 'all' && $sector !== '') {
            // The director's own answer first, the board's as a fallback.
            $sql .= ' AND COALESCE(s.`sector`, o.`sector`) = ?';
            $bindings[] = $sector;
        }

        $band = (string) ($this->filters['band'] ?? 'all');
        if ($band !== 'all' && $band !== '' && ctype_digit($band)) {
            $sql .= ' AND s.`level_value` = ?';
            $bindings[] = (int) $band;
        }

        return $this->filteredIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            Database::select($sql, $bindings)
        );
    }

    /**
     * How many directors could put a number on the AI systems they know of.
     *
     * A blank is meaningful here and is reported as one: it means the director
     * could not say, not that the answer is zero.
     *
     * @return array{answered:int, total:int, median:float|null}
     */
    public function systemsAwareness(): array
    {
        $values = array_map(
            static fn (array $r): float => (float) $r['systems_seen'],
            Database::select(sprintf(
                'SELECT s.`systems_seen` FROM `%s` s WHERE %s AND s.`systems_seen` IS NOT NULL
              ORDER BY s.`systems_seen`',
                $this->submissions(),
                $this->scope()
            ))
        );

        $total = (int) Database::scalar(sprintf(
            'SELECT COUNT(*) FROM `%s` s WHERE %s',
            $this->submissions(),
            $this->scope()
        ));

        $count  = count($values);
        $median = null;

        if ($count > 0) {
            $median = $count % 2 === 1
                ? $values[intdiv($count, 2)]
                : round(($values[$count / 2 - 1] + $values[$count / 2]) / 2, 1);
        }

        return ['answered' => $count, 'total' => $total, 'median' => $median];
    }

    /**
     * The prose read of the dataset, in the reference's words.
     *
     * That build asked an API for this and fell back to these sentences when
     * the call was off or failed — which it always was, since its own
     * LIVE_ANALYSIS flag shipped false and the request carried no credentials.
     * The fallback is therefore what the instrument actually says, so it is
     * what is written here. Composed from the figures already on the page, so
     * the prose cannot claim something the charts do not show.
     */
    public function datasetInsight(): string
    {
        $overview     = $this->overview();
        $competencies = $this->competencies();
        $n            = (int) $overview['n'];

        if ($n === 0) {
            return '';
        }

        $fmt = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);

        $scored = array_values(array_filter(
            $competencies,
            static fn (array $c): bool => $c['mean'] !== null
        ));

        // The competency with the lowest mean across the set.
        usort($scored, static fn (array $a, array $b): int => $a['mean'] <=> $b['mean']);
        $lowest = $scored[0] ?? null;

        // The one that caps the most directors' levels, which need not be it.
        $byFloor = $competencies;
        usort($byFloor, static fn (array $a, array $b): int => ($b['floor_count'] ?? 0) <=> ($a['floor_count'] ?? 0));
        $modal = ($byFloor[0]['floor_count'] ?? 0) > 0 ? $byFloor[0] : null;

        $middle = '';

        if ($lowest !== null && $modal !== null && $lowest['code'] === $modal['code']) {
            $middle = sprintf(
                '%s has the lowest mean across the set at %s and caps the level most often, for %d of %d directors.',
                $lowest['name'],
                $fmt($lowest['mean']),
                $modal['floor_count'],
                $n
            );
        } else {
            if ($lowest !== null) {
                $middle = sprintf(
                    '%s has the lowest mean across the set at %s.',
                    $lowest['name'],
                    $fmt($lowest['mean'])
                );
            }
            if ($modal !== null) {
                $middle .= ($middle === '' ? '' : ' ') . sprintf(
                    '%s caps the level most often, for %d of %d directors.',
                    $modal['name'],
                    $modal['floor_count'],
                    $n
                );
            }
        }

        return implode(' ', array_filter([
            sprintf(
                'Across %d scorecard%s the mean floor is %s and the mean average is %s, a gap of %s. '
                . 'That gap shows how much stronger directors are elsewhere than on their weakest '
                . 'competency, and it is what a rising average conceals.',
                $n,
                $n === 1 ? '' : 's',
                $fmt($overview['mean_floor']),
                $fmt($overview['mean_average']),
                $fmt($overview['gap'])
            ),
            $middle,
            'Every figure here is self-reported by people accustomed to being evaluated, and the '
            . 'sample is self-selected. Treat the ordering of the competencies as informative and '
            . 'the absolute levels as likely inflated.',
        ]));
    }

    /**
     * The values the filter bar offers, taken from the dataset itself so it can
     * never list a year or sector nothing was scored in.
     *
     * Deliberately NOT narrowed by the current filters: a Sector list that
     * shrank once you picked a year would make the other filters unreachable.
     *
     * @return array{years: list<int>, sectors: list<string>}
     */
    public function filterOptions(): array
    {
        $where = $this->includeSynthetic
            ? "s.`status` = 'complete'"
            : "s.`status` = 'complete' AND s.`is_synthetic` = 0";

        $years = array_map(
            static fn (array $r): int => (int) $r['assessment_year'],
            Database::select(sprintf(
                'SELECT DISTINCT s.`assessment_year` FROM `%s` s WHERE %s ORDER BY s.`assessment_year` DESC',
                $this->submissions(),
                $where
            ))
        );

        $sectors = array_map(
            static fn (array $r): string => (string) $r['sector'],
            Database::select(sprintf(
                /*
                 * LEFT JOIN, and the director's own answer first. An inner join
                 * on organisations drops every scorecard with no board name —
                 * which is every anonymous one — so this list came back empty
                 * and the Sector control offered nothing but "All".
                 */
                'SELECT DISTINCT COALESCE(s.`sector`, o.`sector`) AS `sector` FROM `%s` s
              LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id`
                  WHERE %s AND COALESCE(s.`sector`, o.`sector`) IS NOT NULL
                    AND COALESCE(s.`sector`, o.`sector`) <> \'\'
               ORDER BY `sector`',
                $this->submissions(),
                $where
            ))
        );

        return ['years' => $years, 'sectors' => $sectors];
    }

    private function submissions(): string
    {
        return $this->app->table('submissions');
    }

    /** Headline figures for the overview. */
    public function overview(): array
    {
        $row = Database::selectOne(sprintf(
            'SELECT COUNT(*) AS n,
                    COUNT(DISTINCT s.`organisation_id`) AS boards,
                    AVG(s.`floor_score`) AS mean_floor,
                    AVG(s.`mean_score`)  AS mean_average,
                    MIN(s.`submitted_at`) AS first_at,
                    MAX(s.`submitted_at`) AS last_at
               FROM `%s` s
              WHERE %s',
            $this->submissions(),
            $this->scope()
        ));

        $meanFloor   = $row['mean_floor'] === null ? null : round((float) $row['mean_floor'], 2);
        $meanAverage = $row['mean_average'] === null ? null : round((float) $row['mean_average'], 2);

        return [
            'n'            => (int) $row['n'],
            'boards'       => (int) $row['boards'],
            'mean_floor'   => $meanFloor,
            'mean_average' => $meanAverage,
            // The number the instrument exists to expose: how much stronger
            // directors are elsewhere than on their weakest competency.
            'gap'          => ($meanFloor !== null && $meanAverage !== null)
                ? round($meanAverage - $meanFloor, 2)
                : null,
            'first_at'     => $row['first_at'],
            'last_at'      => $row['last_at'],
        ];
    }

    /** How many directors sit at each level. */
    /**
     * The published instrument's rungs, lowest first.
     *
     * Public because the filter bar's Level control is built from them: the
     * ladder is the instrument's, so a dashboard must not name its own.
     *
     * @return list<array<string, mixed>>
     */
    public function rungs(): array
    {
        return Database::select(sprintf(
            'SELECT r.`value`, r.`name`, r.`description`, r.`colour_hex`, r.`band_min`, r.`band_max`
               FROM `%s` r
               JOIN `%s` i ON i.`id` = r.`instrument_id`
              WHERE i.`status` = \'published\'
           ORDER BY r.`value`',
            $this->app->table('rungs'),
            $this->app->table('instruments')
        ));
    }

    /**
     * The band a score falls in, by the instrument's own cut points.
     *
     * Uses the platform Bander rather than comparing against rung VALUES, which
     * is a different and wrong question: the bands are 1.75 / 2.75 / 3.75, so a
     * mean of 1.80 is Aware. Comparing against 1, 2, 3, 4 called it Unaware and
     * painted it red — this screen said the dataset was a whole level worse
     * than it is. One Bander, shared with the scorer, is why that cannot drift.
     */
    public function bander(): Bander
    {
        return new Bander($this->rungs());
    }

    public function levelDistribution(): array
    {
        $rungs = $this->rungs();

        $counts = [];
        foreach (Database::select(sprintf(
            'SELECT s.`level_value` AS v, COUNT(*) AS n FROM `%s` s WHERE %s GROUP BY s.`level_value`',
            $this->submissions(),
            $this->scope()
        )) as $row) {
            $counts[(int) $row['v']] = (int) $row['n'];
        }

        $total = array_sum($counts);

        return array_map(static function (array $rung) use ($counts, $total): array {
            $n = $counts[(int) $rung['value']] ?? 0;

            return [
                'value'       => (int) $rung['value'],
                'name'        => $rung['name'],
                // Carried from the instrument, not restated in a view: the
                // rungs were renamed once already, and any copy of their
                // wording in code is a copy that can drift.
                'description' => $rung['description'],
                'colour'      => $rung['colour_hex'],
                'n'           => $n,
                'percent'     => $total > 0 ? (int) round($n / $total * 100) : 0,
            ];
        }, $rungs);
    }

    /** Mean, spread and floor-count for every competency. */
    public function competencies(): array
    {
        $rows = Database::select(sprintf(
            'SELECT c.`id`, c.`code`, c.`name`, c.`position`,
                    COUNT(ss.`id`) AS n,
                    AVG(ss.`score`) AS mean_score,
                    MIN(ss.`score`) AS min_score,
                    MAX(ss.`score`) AS max_score,
                    STDDEV_POP(ss.`score`) AS sd
               FROM `%s` c
          LEFT JOIN `%s` ss ON ss.`competency_id` = c.`id`
          LEFT JOIN `%s` s  ON s.`id` = ss.`submission_id` AND %s
              WHERE c.`instrument_id` = (SELECT `id` FROM `%s` WHERE `status` = \'published\' ORDER BY `published_at` DESC LIMIT 1)
                AND (ss.`id` IS NULL OR s.`id` IS NOT NULL)
           GROUP BY c.`id`, c.`code`, c.`name`, c.`position`
           ORDER BY c.`position`',
            $this->app->table('competencies'),
            $this->app->table('submission_scores'),
            $this->submissions(),
            $this->scope(),
            $this->app->table('instruments')
        ));

        $floorCounts = [];
        foreach (Database::select(sprintf(
            'SELECT s.`floor_competency_id` AS id, COUNT(*) AS n
               FROM `%s` s WHERE %s AND s.`floor_competency_id` IS NOT NULL
           GROUP BY s.`floor_competency_id`',
            $this->submissions(),
            $this->scope()
        )) as $row) {
            $floorCounts[(int) $row['id']] = (int) $row['n'];
        }

        return array_map(static function (array $row) use ($floorCounts): array {
            return [
                'id'          => (int) $row['id'],
                'code'        => $row['code'],
                'name'        => $row['name'],
                'n'           => (int) $row['n'],
                'mean'        => $row['mean_score'] === null ? null : round((float) $row['mean_score'], 2),
                'min'         => $row['min_score'] === null ? null : round((float) $row['min_score'], 2),
                'max'         => $row['max_score'] === null ? null : round((float) $row['max_score'], 2),
                'sd'          => $row['sd'] === null ? null : round((float) $row['sd'], 2),
                'floor_count' => $floorCounts[(int) $row['id']] ?? 0,
            ];
        }, $rows);
    }

    /** Mean director floor, by sector. */
    public function bySector(): array
    {
        return array_map(
            static fn (array $row): array => [
                'sector' => $row['sector'] ?? 'Not specified',
                'n'      => (int) $row['n'],
                'mean_floor' => $row['mean_floor'] === null ? null : round((float) $row['mean_floor'], 2),
            ],
            Database::select(sprintf(
                'SELECT COALESCE(s.`sector`, o.`sector`) AS `sector`, COUNT(*) AS n, AVG(s.`floor_score`) AS mean_floor
                   FROM `%s` s
              LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id`
                  WHERE %s AND s.`floor_score` IS NOT NULL
               GROUP BY COALESCE(s.`sector`, o.`sector`)
               ORDER BY mean_floor DESC',
                $this->submissions(),
                $this->scope()
            ))
        );
    }

    /**
     * Associations between a director's floor and the figures they reported
     * about their own board.
     *
     * The records are the filtered set, synthetic included when the toggle is
     * on, as in the reference's paneCorr(recs). Chosen on 25 Sep 2026: the
     * seeder now generates these figures as the reference does, loosely tied
     * to each record's archetype target, so any coefficient drawn from demo
     * records is partly an artefact of the generator.
     *
     * Coefficients are withheld below the configured n. The scatter is still
     * worth showing: how thin the data is, is itself the result.
     */
    public function correlations(): array
    {
        $minimum = (int) $this->app->config('scoring.correlation_min_n', 30);

        $metrics = [
            'agenda_meetings' => 'Meetings with AI as a decision item',
            'education_hours' => 'Hours of AI education taken',
            'board_seats'     => 'Directors on the board',
        ];

        $results = [];

        foreach ($metrics as $column => $label) {
            $pairs = Database::select(sprintf(
                'SELECT s.`floor_score` AS x, s.`%s` AS y
                   FROM `%s` s
                  WHERE %s
                    AND s.`floor_score` IS NOT NULL
                    AND s.`%s` IS NOT NULL',
                $column,
                $this->submissions(),
                $this->scope(),
                $column
            ));

            $xs = array_map(static fn (array $p): float => (float) $p['x'], $pairs);
            $ys = array_map(static fn (array $p): float => (float) $p['y'], $pairs);

            $results[] = [
                'metric'    => $column,
                'label'     => $label,
                'n'         => count($pairs),
                'points'    => array_map(
                    static fn (array $p): array => ['x' => (float) $p['x'], 'y' => (float) $p['y']],
                    $pairs
                ),
                'reportable' => count($pairs) >= $minimum,
                'stat'       => count($pairs) >= $minimum ? self::pearson($xs, $ys) : null,
                'minimum'    => $minimum,
            ];
        }

        return $results;
    }

    /**
     * Pearson r with a Fisher-z 95% confidence interval.
     *
     * Returns null when the sample is too small or a variable does not vary, so
     * a caller cannot print a coefficient that means nothing.
     */
    public static function pearson(array $xs, array $ys): ?array
    {
        $n = count($xs);

        // n = 3 would make the Fisher standard error 1/sqrt(0).
        if ($n < 4) {
            return null;
        }

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $dy = $ys[$i] - $meanY;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        if ($sxx <= 0.0 || $syy <= 0.0) {
            return null;
        }

        $r = $sxy / sqrt($sxx * $syy);
        $r = max(-0.9999999, min(0.9999999, $r));

        $z  = 0.5 * log((1 + $r) / (1 - $r));
        $se = 1 / sqrt($n - 3);

        $lo = tanh($z - 1.96 * $se);
        $hi = tanh($z + 1.96 * $se);

        return [
            'r'       => round($r, 3),
            'n'       => $n,
            'lo'      => round($lo, 3),
            'hi'      => round($hi, 3),
            // An interval spanning zero means the data cannot distinguish the
            // association from no association. Say so, next to the number.
            'crosses' => $lo < 0 && $hi > 0,
        ];
    }

    /**
     * The prose read of the correlations, in the reference's words.
     *
     * One sentence per figure that clears the reporting threshold, then the
     * caution. Composed from the coefficients already printed on the page, for
     * the same reason datasetInsight() is: the paragraph cannot then claim
     * something the cards do not show.
     *
     * Empty when nothing is reportable — the pane then says so in its own
     * words rather than printing a read of figures it is withholding.
     */
    public function correlationInsight(?array $correlations = null): string
    {
        $rows = array_values(array_filter(
            $correlations ?? $this->correlations(),
            static fn (array $c): bool => $c['reportable'] && $c['stat'] !== null
        ));

        if ($rows === []) {
            return '';
        }

        $lines = array_map(static function (array $row): string {
            $stat  = $row['stat'];
            $label = lcfirst((string) $row['label']);

            $line = sprintf(
                'Against the floor score, %s shows r = %s%s, with a 95%% confidence interval from %s to %s at n = %d.',
                $label,
                $stat['r'] > 0 ? '+' : '',
                number_format($stat['r'], 2),
                number_format($stat['lo'], 2),
                number_format($stat['hi'], 2),
                $stat['n']
            );

            return $stat['crosses']
                ? $line . ' That interval includes zero, so the data cannot distinguish this from no association.'
                : $line;
        }, $rows);

        $lines[] = 'These are associations between two self-reported figures in a self-selected sample. '
            . 'They cannot separate exposure that builds competence from competent directors who demand '
            . 'more exposure, and the interval shows how wide the uncertainty is. Report the interval with '
            . 'the coefficient, and describe the result as an association rather than an effect.';

        return implode(' ', $lines);
    }

    /**
     * Mean floor and mean average per year.
     *
     * The pattern this exists to expose: a rising average with a static floor
     * means the board got better at what it was already comfortable asking
     * about. The floor rule is what makes that visible; this is where you see
     * it happening over time.
     *
     * @return list<array<string, mixed>>
     */
    public function yearOverYear(): array
    {
        $rows = Database::select(sprintf(
            'SELECT s.`assessment_year` AS year,
                    COUNT(*) AS n,
                    AVG(s.`floor_score`) AS mean_floor,
                    AVG(s.`mean_score`)  AS mean_average
               FROM `%s` s
              WHERE %s AND s.`floor_score` IS NOT NULL
           GROUP BY s.`assessment_year`
           ORDER BY s.`assessment_year`',
            $this->submissions(),
            $this->scope()
        ));

        $previous = null;
        $series   = [];

        foreach ($rows as $row) {
            $floor   = round((float) $row['mean_floor'], 2);
            $average = round((float) $row['mean_average'], 2);

            $series[] = [
                'year'         => (int) $row['year'],
                'n'            => (int) $row['n'],
                'mean_floor'   => $floor,
                'mean_average' => $average,
                'gap'          => round($average - $floor, 2),
                'floor_change' => $previous === null ? null : round($floor - $previous['floor'], 2),
                'gap_change'   => $previous === null ? null : round(($average - $floor) - $previous['gap'], 2),
            ];

            $previous = ['floor' => $floor, 'gap' => $average - $floor];
        }

        return $series;
    }

    /**
     * Boards with more than one director scored, and how they moved.
     *
     * @return list<array<string, mixed>>
     */
    public function boards(int $limit = 50): array
    {
        return array_map(
            static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'name'         => $r['name'],
                'sector'       => $r['sector'],
                'directors'    => (int) $r['directors'],
                'years'        => (int) $r['years'],
                'mean_floor'   => $r['mean_floor'] === null ? null : round((float) $r['mean_floor'], 2),
                'mean_average' => $r['mean_average'] === null ? null : round((float) $r['mean_average'], 2),
                'weakest'      => $r['weakest'] === null ? null : round((float) $r['weakest'], 2),
            ],
            Database::select(sprintf(
                'SELECT o.`id`, o.`name`, o.`sector`,
                        COUNT(*) AS directors,
                        COUNT(DISTINCT s.`assessment_year`) AS years,
                        AVG(s.`floor_score`) AS mean_floor,
                        AVG(s.`mean_score`)  AS mean_average,
                        MIN(s.`floor_score`) AS weakest
                   FROM `%s` s
                   JOIN `organisations` o ON o.`id` = s.`organisation_id`
                  WHERE %s
               GROUP BY o.`id`, o.`name`, o.`sector`
               ORDER BY directors DESC, o.`name`
                  LIMIT %d',
                $this->submissions(),
                $this->scope(),
                max(1, min(200, $limit))
            ))
        );
    }

    /**
     * Boards that have scored in more than one assessment year.
     *
     * The reference's "Boards that re-scored" table: one row per board, one
     * column per year, and what the floor did between the first year and the
     * last. A board that has scored once is not in here — it has nothing to
     * compare against, and a row of dashes reads as a missing figure rather
     * than as a board that has simply not come round again yet.
     *
     * One correction to the reference. It labelled the floor move with
     * `byOrgYear[o][y][0].floorCode` — whichever director's record happened to
     * sort first in that year, which on a five-person board is close to
     * arbitrary. This reports the competency that caps the MOST directors in
     * that year, ties going to instrument order, which is the question the
     * column is actually asking.
     *
     * @return list<array<string, mixed>>
     */
    public function boardYears(): array
    {
        $rows = Database::select(sprintf(
            'SELECT o.`id`, o.`name`, o.`sector`, s.`assessment_year` AS year,
                    COUNT(*) AS n, AVG(s.`floor_score`) AS mean_floor
               FROM `%s` s
               JOIN `organisations` o ON o.`id` = s.`organisation_id`
              WHERE %s AND s.`floor_score` IS NOT NULL
           GROUP BY o.`id`, o.`name`, o.`sector`, s.`assessment_year`
           ORDER BY o.`name`, s.`assessment_year`',
            $this->submissions(),
            $this->scope()
        ));

        // The modal floor competency per board-year, in one pass.
        $modal = [];
        foreach (Database::select(sprintf(
            'SELECT s.`organisation_id` AS org, s.`assessment_year` AS year,
                    c.`code`, c.`position`, COUNT(*) AS n
               FROM `%s` s
               JOIN `%s` c ON c.`id` = s.`floor_competency_id`
              WHERE %s AND s.`organisation_id` IS NOT NULL
           GROUP BY s.`organisation_id`, s.`assessment_year`, c.`code`, c.`position`
           ORDER BY n DESC, c.`position`',
            $this->submissions(),
            $this->app->table('competencies'),
            $this->scope()
        )) as $row) {
            // Ordered most-common first, so the first code seen for a
            // board-year wins and later ones are its runners-up.
            $modal[(int) $row['org'] . ':' . (int) $row['year']] ??= (string) $row['code'];
        }

        $boards = [];

        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $year = (int) $row['year'];

            $boards[$id] ??= [
                'id'     => $id,
                'name'   => $row['name'],
                'sector' => $row['sector'],
                'years'  => [],
            ];

            $boards[$id]['years'][$year] = [
                'year'       => $year,
                'n'          => (int) $row['n'],
                'mean_floor' => round((float) $row['mean_floor'], 2),
                'floor_code' => $modal[$id . ':' . $year] ?? null,
            ];
        }

        $scored = [];

        foreach ($boards as $board) {
            if (count($board['years']) < 2) {
                continue;
            }

            ksort($board['years']);
            $first = reset($board['years']);
            $last  = end($board['years']);

            $board['change']     = round($last['mean_floor'] - $first['mean_floor'], 2);
            $board['from_code']  = $first['floor_code'];
            $board['to_code']    = $last['floor_code'];
            $board['first_year'] = $first['year'];
            $board['last_year']  = $last['year'];

            $scored[] = $board;
        }

        usort($scored, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $scored;
    }

    /**
     * One board: every director who scored, and the competency means across them.
     *
     * @return array<string, mixed>|null
     */
    public function board(int $organisationId): ?array
    {
        $organisation = Database::selectOne(
            'SELECT * FROM `organisations` WHERE `id` = ?',
            [$organisationId]
        );

        if ($organisation === null) {
            return null;
        }

        $directors = Database::select(sprintf(
            'SELECT s.`id`, s.`director_name`, s.`seat_role`, s.`assessment_year`,
                    s.`floor_score`, s.`mean_score`, s.`level_value`, s.`is_synthetic`,
                    fc.`code` AS floor_code
               FROM `%s` s
          LEFT JOIN `%s` fc ON fc.`id` = s.`floor_competency_id`
              WHERE s.`organisation_id` = ? AND %s
           ORDER BY s.`assessment_year` DESC, s.`floor_score`',
            $this->submissions(),
            $this->app->table('competencies'),
            str_replace('s.`is_synthetic`', 's.`is_synthetic`', $this->scope())
        ), [$organisationId]);

        // Competency means across this board's directors, plus how many of them
        // reach Practiced on each — the "coverage" question a board actually has.
        $threshold = (float) $this->app->config('scoring.practiced_threshold', 2.75);

        $competencies = Database::select(sprintf(
            'SELECT c.`code`, c.`name`, c.`position`,
                    COUNT(ss.`id`) AS n,
                    AVG(ss.`score`) AS mean_score,
                    MIN(ss.`score`) AS min_score,
                    MAX(ss.`score`) AS max_score,
                    SUM(CASE WHEN ss.`score` >= ? THEN 1 ELSE 0 END) AS at_practiced
               FROM `%s` c
          LEFT JOIN `%s` ss ON ss.`competency_id` = c.`id`
          LEFT JOIN `%s` s  ON s.`id` = ss.`submission_id`
                           AND s.`organisation_id` = ?
                           AND %s
              WHERE c.`instrument_id` = (SELECT `id` FROM `%s` WHERE `status` = \'published\'
                                      ORDER BY `published_at` DESC LIMIT 1)
                AND (ss.`id` IS NULL OR s.`id` IS NOT NULL)
           GROUP BY c.`id`, c.`code`, c.`name`, c.`position`
           ORDER BY c.`position`',
            $this->app->table('competencies'),
            $this->app->table('submission_scores'),
            $this->submissions(),
            $this->scope(),
            $this->app->table('instruments')
        ), [$threshold, $organisationId]);

        return [
            'organisation' => $organisation,
            'directors'    => $directors,
            'competencies' => array_map(
                static fn (array $c): array => [
                    'code'         => $c['code'],
                    'name'         => $c['name'],
                    'n'            => (int) $c['n'],
                    'mean'         => $c['mean_score'] === null ? null : round((float) $c['mean_score'], 2),
                    // The spread across this board's directors, for the ticks
                    // the printed bars carry.
                    'min'          => $c['min_score'] === null ? null : round((float) $c['min_score'], 2),
                    'max'          => $c['max_score'] === null ? null : round((float) $c['max_score'], 2),
                    'at_practiced' => (int) $c['at_practiced'],
                ],
                $competencies
            ),
        ];
    }

    /**
     * One board, year by year: the competency means its directors produced in
     * each assessment year, and the floor those means imply.
     *
     * This is the board profile the reference opened over the dashboard — and
     * the floor here is NOT the mean of the directors' own floors. The board is
     * aggregated first (mean per competency across its directors that year) and
     * the floor rule is applied to that, which is the board-level question:
     * where is this board weakest, not how weak is its weakest member.
     *
     * @return list<array<string, mixed>> oldest year first
     */
    public function boardByYear(int $organisationId): array
    {
        $rows = Database::select(sprintf(
            'SELECT s.`assessment_year` AS year, c.`code`, c.`name`, c.`position`,
                    COUNT(ss.`id`) AS n,
                    AVG(ss.`score`) AS mean_score,
                    MIN(ss.`score`) AS min_score,
                    MAX(ss.`score`) AS max_score
               FROM `%s` s
               JOIN `%s` ss ON ss.`submission_id` = s.`id`
               JOIN `%s` c  ON c.`id` = ss.`competency_id`
              WHERE s.`organisation_id` = ? AND %s
           GROUP BY s.`assessment_year`, c.`code`, c.`name`, c.`position`
           ORDER BY s.`assessment_year`, c.`position`',
            $this->submissions(),
            $this->app->table('submission_scores'),
            $this->app->table('competencies'),
            $this->scope()
        ), [$organisationId]);

        $directors = [];
        foreach (Database::select(sprintf(
            'SELECT s.`assessment_year` AS year, COUNT(*) AS n
               FROM `%s` s WHERE s.`organisation_id` = ? AND %s
           GROUP BY s.`assessment_year`',
            $this->submissions(),
            $this->scope()
        ), [$organisationId]) as $row) {
            $directors[(int) $row['year']] = (int) $row['n'];
        }

        $years = [];

        foreach ($rows as $row) {
            $year = (int) $row['year'];

            $years[$year] ??= [
                'year'         => $year,
                'directors'    => $directors[$year] ?? 0,
                'competencies' => [],
            ];

            $years[$year]['competencies'][] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'n'    => (int) $row['n'],
                'mean' => $row['mean_score'] === null ? null : round((float) $row['mean_score'], 2),
                'min'  => $row['min_score'] === null ? null : round((float) $row['min_score'], 2),
                'max'  => $row['max_score'] === null ? null : round((float) $row['max_score'], 2),
            ];
        }

        ksort($years);

        foreach ($years as $year => $profile) {
            $scored = array_values(array_filter(
                $profile['competencies'],
                static fn (array $c): bool => $c['mean'] !== null
            ));

            // Instrument order is the query's order, and a strict comparison
            // keeps the FIRST competency on a tie — the same rule the scorer
            // applies to a director, applied here to a board.
            $floor = null;
            foreach ($scored as $competency) {
                if ($floor === null || $competency['mean'] < $floor['mean']) {
                    $floor = $competency;
                }
            }

            $years[$year]['floor']      = $floor['mean'] ?? null;
            $years[$year]['floor_code'] = $floor['code'] ?? null;
            $years[$year]['mean']       = $scored === []
                ? null
                : round(array_sum(array_column($scored, 'mean')) / count($scored), 2);
        }

        return array_values($years);
    }

    /**
     * Per-statement breakdown for one competency.
     *
     * A competency mean of 3.00 can be five 3s or a 1 and four 3.5s. This is
     * the only view that tells those apart, and the weakest item is usually the
     * most actionable thing in the whole dataset.
     *
     * @return array<string, mixed>|null
     */
    public function items(string $code): ?array
    {
        $competency = Database::selectOne(sprintf(
            'SELECT c.* FROM `%s` c
               JOIN `%s` i ON i.`id` = c.`instrument_id`
              WHERE c.`code` = ? AND i.`status` = \'published\'
           ORDER BY i.`published_at` DESC LIMIT 1',
            $this->app->table('competencies'),
            $this->app->table('instruments')
        ), [$code]);

        if ($competency === null) {
            return null;
        }

        $items = Database::select(sprintf(
            'SELECT i.`id`, i.`position`, i.`statement`,
                    COUNT(r.`id`) AS n,
                    AVG(r.`mark`) AS mean_mark,
                    SUM(CASE WHEN r.`mark` = 1 THEN 1 ELSE 0 END) AS m1,
                    SUM(CASE WHEN r.`mark` = 2 THEN 1 ELSE 0 END) AS m2,
                    SUM(CASE WHEN r.`mark` = 3 THEN 1 ELSE 0 END) AS m3,
                    SUM(CASE WHEN r.`mark` = 4 THEN 1 ELSE 0 END) AS m4,
                    SUM(CASE WHEN r.`evidence_note` IS NOT NULL AND r.`evidence_note` <> \'\' THEN 1 ELSE 0 END) AS with_evidence
               FROM `%s` i
          LEFT JOIN `%s` r ON r.`item_id` = i.`id`
          LEFT JOIN `%s` s ON s.`id` = r.`submission_id` AND %s
              WHERE i.`competency_id` = ?
                AND (r.`id` IS NULL OR s.`id` IS NOT NULL)
           GROUP BY i.`id`, i.`position`, i.`statement`
           ORDER BY i.`position`',
            $this->app->table('items'),
            $this->app->table('submission_responses'),
            $this->submissions(),
            $this->scope()
        ), [$competency['id']]);

        $mapped = array_map(
            static fn (array $i): array => [
                'position'      => (int) $i['position'],
                'statement'     => $i['statement'],
                'n'             => (int) $i['n'],
                'mean'          => $i['mean_mark'] === null ? null : round((float) $i['mean_mark'], 2),
                'distribution'  => [(int) $i['m1'], (int) $i['m2'], (int) $i['m3'], (int) $i['m4']],
                'with_evidence' => (int) $i['with_evidence'],
            ],
            $items
        );

        $scored = array_filter($mapped, static fn (array $i): bool => $i['mean'] !== null);
        usort($scored, static fn (array $a, array $b): int => $a['mean'] <=> $b['mean']);

        $totalMarks = array_sum(array_map(static fn (array $i): int => $i['n'], $mapped));
        $atAware    = array_sum(array_map(static fn (array $i): int => $i['distribution'][1], $mapped));

        return [
            'competency' => $competency,
            'items'      => $mapped,
            'weakest'    => $scored[0] ?? null,
            // A third or more sitting at Aware is the "understands the value,
            // has acted on none of it" signature the instrument watches for.
            'aware_share' => $totalMarks > 0 ? round($atAware / $totalMarks, 3) : null,
        ];
    }

    /**
     * One competency's mean, by sector.
     *
     * The dataset-wide sector chart answers "which sectors have the weakest
     * directors"; this answers "who is weak on THIS", which is a different
     * ordering and the one the Competencies pane needs beside an item
     * breakdown.
     *
     * @return list<array{sector:string, n:int, mean:float}>
     */
    public function competencyBySector(string $code): array
    {
        if ($code === '') {
            return [];
        }

        return array_map(
            static fn (array $r): array => [
                'sector' => (string) ($r['sector'] ?? 'Not specified'),
                'n'      => (int) $r['n'],
                'mean'   => round((float) $r['mean_score'], 2),
            ],
            Database::select(sprintf(
                'SELECT COALESCE(s.`sector`, o.`sector`) AS `sector`,
                        COUNT(*) AS n, AVG(ss.`score`) AS mean_score
                   FROM `%s` ss
                   JOIN `%s` s ON s.`id` = ss.`submission_id` AND %s
                   JOIN `%s` c ON c.`id` = ss.`competency_id`
              LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id`
                  WHERE c.`code` = ?
                    AND c.`instrument_id` = (SELECT `id` FROM `%s` WHERE `status` = \'published\'
                                          ORDER BY `published_at` DESC LIMIT 1)
               GROUP BY COALESCE(s.`sector`, o.`sector`)
               ORDER BY mean_score DESC',
                $this->app->table('submission_scores'),
                $this->submissions(),
                $this->scope(),
                $this->app->table('competencies'),
                $this->app->table('instruments')
            ), [$code])
        );
    }

    /** Counts used to label the synthetic toggle honestly. */
    public function counts(): array
    {
        $row = Database::selectOne(sprintf(
            'SELECT
                SUM(CASE WHEN `is_synthetic` = 0 AND `status` = \'complete\' THEN 1 ELSE 0 END) AS real_complete,
                SUM(CASE WHEN `is_synthetic` = 1 THEN 1 ELSE 0 END) AS synthetic,
                SUM(CASE WHEN `status` = \'in_progress\' THEN 1 ELSE 0 END) AS in_progress
              FROM `%s`',
            $this->submissions()
        ));

        return [
            'real'        => (int) ($row['real_complete'] ?? 0),
            'synthetic'   => (int) ($row['synthetic'] ?? 0),
            'in_progress' => (int) ($row['in_progress'] ?? 0),
        ];
    }
}
