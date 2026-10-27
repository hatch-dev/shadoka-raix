<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services;

use Platform\Assessment\Bander;
use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * Read-side aggregates for the admin analytics and dataset screens.
 *
 * Four rules this class enforces rather than leaves to its callers:
 *
 *  1. Synthetic records are excluded unless explicitly asked for, and the filter
 *     is applied here rather than re-implemented per query. The reference build
 *     flagged demo records but re-wrote the exclusion at each call site, which is
 *     a rule you eventually forget somewhere.
 *
 *  2. Correlations NEVER include synthetic records, whatever the toggle says. The
 *     seeder does not generate the reported figures at all, so a synthetic row
 *     contributes no pair — but the exclusion is stated in the query as well,
 *     because "the generator happens not to fill those columns" is a property of
 *     the generator and this is a property of the analysis.
 *
 *  3. The LEVEL is banded from the average and the FLOOR is the weakest
 *     dimension. Both are reported, and every method that returns one names
 *     which it is. Reporting the floor as the level is this instrument's one
 *     silent-corruption case: the numbers would all look plausible.
 *
 *  4. A band comes from the platform Bander, using the instrument's own cut
 *     points, never from comparing a score against a rung VALUE. The edges are
 *     1.80 / 2.60 / 3.40 / 4.20, so a mean of 2.9 is Developing; comparing
 *     against 1, 2, 3, 4, 5 would call it Emerging and paint the whole dataset a
 *     level worse than it is.
 */
final class Analytics
{
    /** @var list<int>|null resolved once, then reused by every query */
    private ?array $filteredIds = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $rungs = null;

    /**
     * @param array{year?:string, sector?:string, band?:string} $filters from the
     *        dashboard's filter bar; 'all' or absent means no filter
     */
    public function __construct(
        private readonly AppManifest $app,
        private readonly bool $includeSynthetic = false,
        private readonly array $filters = [],
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Scope                                                               */
    /* ------------------------------------------------------------------ */

    /** The WHERE fragment every query shares. */
    private function scope(): string
    {
        $where = "s.`status` = 'complete'";

        if (!$this->includeSynthetic) {
            $where .= ' AND s.`is_synthetic` = 0';
        }

        /*
         * Year, sector and level are resolved to a list of submission ids by one
         * parameterised query, and the ids are then interpolated here.
         *
         * Two reasons. A dozen aggregate queries share this fragment and each
         * carries its own bindings in its own order; threading three more through
         * all of them is a lot of surface for a filter bar. And what lands in the
         * SQL is a list of integers this class read out of the database itself —
         * nothing from the request reaches the string.
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

        $sql      = sprintf('SELECT s.`id` FROM `%s` s WHERE 1 = 1', $this->submissions());
        $bindings = [];

        $year = (string) ($this->filters['year'] ?? 'all');
        if ($year !== 'all' && $year !== '' && ctype_digit($year)) {
            $sql .= ' AND s.`assessment_year` = ?';
            $bindings[] = (int) $year;
        }

        $sector = (string) ($this->filters['sector'] ?? 'all');
        if ($sector !== 'all' && $sector !== '') {
            $sql .= ' AND s.`sector` = ?';
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

    private function submissions(): string
    {
        return $this->app->table('submissions');
    }

    /* ------------------------------------------------------------------ */
    /* The instrument's ladder                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The published instrument's rungs, lowest first.
     *
     * Public because the filter bar's Level control and the level legend are both
     * built from them: the ladder is the instrument's, so a dashboard must not
     * name its own.
     *
     * @return list<array<string, mixed>>
     */
    public function rungs(): array
    {
        return $this->rungs ??= Database::select(sprintf(
            'SELECT r.`value`, r.`name`, r.`description`, r.`colour_hex`, r.`band_min`, r.`band_max`
               FROM `%s` r
               JOIN `%s` i ON i.`id` = r.`instrument_id`
              WHERE i.`status` = \'published\'
           ORDER BY r.`value`',
            $this->app->table('rungs'),
            $this->app->table('instruments')
        ));
    }

    public function bander(): Bander
    {
        return new Bander($this->rungs());
    }

    /* ------------------------------------------------------------------ */
    /* Counts and filter options                                           */
    /* ------------------------------------------------------------------ */

    /**
     * How many records exist, regardless of the filter bar.
     *
     * The dataset screen's "include N synthetic demo records" label needs the
     * count of records the toggle WOULD add, which by definition is outside the
     * current scope.
     *
     * @return array{real:int, synthetic:int, total:int}
     */
    public function counts(): array
    {
        $row = Database::selectOne(sprintf(
            'SELECT SUM(`is_synthetic` = 0) AS real_n, SUM(`is_synthetic` = 1) AS synthetic_n, COUNT(*) AS total
               FROM `%s` WHERE `status` = \'complete\'',
            $this->submissions()
        ));

        return [
            'real'      => (int) ($row['real_n'] ?? 0),
            'synthetic' => (int) ($row['synthetic_n'] ?? 0),
            'total'     => (int) ($row['total'] ?? 0),
        ];
    }

    /** @return array{years: list<int>, sectors: list<string>} */
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
                'SELECT DISTINCT s.`sector` FROM `%s` s
                  WHERE %s AND s.`sector` IS NOT NULL AND s.`sector` <> \'\'
               ORDER BY s.`sector`',
                $this->submissions(),
                $where
            ))
        );

        return ['years' => $years, 'sectors' => $sectors];
    }

    /* ------------------------------------------------------------------ */
    /* Overview                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Headline figures.
     *
     * `mean_average` is the average of the organisations' overall scores and is
     * what the levels are banded from. `mean_floor` is the average of their
     * weakest dimensions. `gap` is the difference, and is the number this
     * instrument exists to expose: how much stronger organisations are elsewhere
     * than on the dimension that will actually decide what they can deliver.
     *
     * @return array{n:int, organisations:int, mean_floor:?float, mean_average:?float,
     *               gap:?float, first_at:?string, last_at:?string}
     */
    public function overview(): array
    {
        $row = Database::selectOne(sprintf(
            'SELECT COUNT(*) AS n,
                    COUNT(DISTINCT COALESCE(s.`organisation_id`, -s.`id`)) AS organisations,
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
            'n'             => (int) $row['n'],
            'organisations' => (int) $row['organisations'],
            'mean_floor'    => $meanFloor,
            'mean_average'  => $meanAverage,
            'gap'           => ($meanFloor !== null && $meanAverage !== null)
                ? round($meanAverage - $meanFloor, 2)
                : null,
            'first_at'      => $row['first_at'],
            'last_at'       => $row['last_at'],
        ];
    }

    /**
     * How many organisations sit at each level.
     *
     * Banded from each record's overall average, which is what `level_value`
     * holds. @return list<array<string, mixed>>
     */
    public function levelDistribution(): array
    {
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
                'value' => (int) $rung['value'],
                'name'  => $rung['name'],
                // Carried from the instrument, not restated in a view: any copy
                // of this wording in code is a copy that can drift.
                'description' => $rung['description'],
                'colour'      => $rung['colour_hex'],
                'n'           => $n,
                'percent'     => $total > 0 ? (int) round($n / $total * 100) : 0,
            ];
        }, $this->rungs());
    }

    /**
     * Mean, spread and floor-count for every dimension.
     *
     * `floor_count` is how often this dimension is the weakest one — the
     * reference's "Where the floor sits" chart.
     *
     * @return list<array<string, mixed>>
     */
    public function dimensions(): array
    {
        $rows = Database::select(sprintf(
            'SELECT d.`id`, d.`code`, d.`name`, d.`position`, d.`summary_line`,
                    COUNT(ss.`id`) AS n,
                    AVG(ss.`score`) AS mean_score,
                    MIN(ss.`score`) AS min_score,
                    MAX(ss.`score`) AS max_score,
                    STDDEV_POP(ss.`score`) AS sd
               FROM `%s` d
          LEFT JOIN `%s` ss ON ss.`dimension_id` = d.`id`
          LEFT JOIN `%s` s  ON s.`id` = ss.`submission_id` AND %s
              WHERE d.`instrument_id` = (SELECT `id` FROM `%s` WHERE `status` = \'published\' ORDER BY `published_at` DESC LIMIT 1)
                AND (ss.`id` IS NULL OR s.`id` IS NOT NULL)
           GROUP BY d.`id`, d.`code`, d.`name`, d.`position`, d.`summary_line`
           ORDER BY d.`position`',
            $this->app->table('dimensions'),
            $this->app->table('submission_scores'),
            $this->submissions(),
            $this->scope(),
            $this->app->table('instruments')
        ));

        $floorCounts = [];
        foreach (Database::select(sprintf(
            'SELECT s.`floor_dimension_id` AS id, COUNT(*) AS n
               FROM `%s` s WHERE %s AND s.`floor_dimension_id` IS NOT NULL
           GROUP BY s.`floor_dimension_id`',
            $this->submissions(),
            $this->scope()
        )) as $row) {
            $floorCounts[(int) $row['id']] = (int) $row['n'];
        }

        return array_map(static function (array $row) use ($floorCounts): array {
            return [
                'id'           => (int) $row['id'],
                'code'         => $row['code'],
                'name'         => $row['name'],
                'summary_line' => $row['summary_line'],
                'n'            => (int) $row['n'],
                'mean'         => $row['mean_score'] === null ? null : round((float) $row['mean_score'], 2),
                'min'          => $row['min_score'] === null ? null : round((float) $row['min_score'], 2),
                'max'          => $row['max_score'] === null ? null : round((float) $row['max_score'], 2),
                'sd'           => $row['sd'] === null ? null : round((float) $row['sd'], 2),
                'floor_count'  => $floorCounts[(int) $row['id']] ?? 0,
            ];
        }, $rows);
    }

    /**
     * The weakest dimension across the whole filtered set.
     *
     * Not the average of the per-record floors: this is which dimension the
     * dataset as a whole scores lowest on, which is what the "Floor" tag marks
     * on an aggregate bar row.
     *
     * @param list<array<string, mixed>>|null $dimensions reuse an earlier call
     * @return array{code:?string, id:?int, mean:?float}
     */
    public function aggregateFloor(?array $dimensions = null): array
    {
        $dimensions = $dimensions ?? $this->dimensions();

        $floor = ['code' => null, 'id' => null, 'mean' => null];

        foreach ($dimensions as $dimension) {
            if ($dimension['mean'] === null) {
                continue;
            }

            // Strict less-than keeps the first dimension in instrument order on
            // a tie, so the tag does not wander on identical data.
            if ($floor['mean'] === null || $dimension['mean'] < $floor['mean']) {
                $floor = ['code' => $dimension['code'], 'id' => $dimension['id'], 'mean' => $dimension['mean']];
            }
        }

        return $floor;
    }

    /**
     * Mean overall score by sector.
     *
     * The reference's chart axis says "Mean overall score", so this returns the
     * average of `mean_score` and not of the floor.
     *
     * @return list<array{sector:string, n:int, mean:?float}>
     */
    public function bySector(): array
    {
        return array_map(
            static fn (array $row): array => [
                'sector' => ($row['sector'] === null || $row['sector'] === '') ? 'Not specified' : $row['sector'],
                'n'      => (int) $row['n'],
                'mean'   => $row['mean_score'] === null ? null : round((float) $row['mean_score'], 2),
            ],
            Database::select(sprintf(
                'SELECT s.`sector`, COUNT(*) AS n, AVG(s.`mean_score`) AS mean_score
                   FROM `%s` s
                  WHERE %s AND s.`mean_score` IS NOT NULL
               GROUP BY s.`sector`
               ORDER BY mean_score DESC',
                $this->submissions(),
                $this->scope()
            ))
        );
    }

    /**
     * How many respondents could put a number on the AI systems on an inventory.
     *
     * A blank is meaningful here and is reported as one: in most organisations no
     * such list has ever been compiled, which is not the same as zero systems.
     *
     * @return array{answered:int, total:int, median:?float}
     */
    public function systemsInventory(): array
    {
        $values = array_map(
            static fn (array $r): float => (float) $r['systems_inventory'],
            Database::select(sprintf(
                'SELECT s.`systems_inventory` FROM `%s` s
                  WHERE %s AND s.`systems_inventory` IS NOT NULL
               ORDER BY s.`systems_inventory`',
                $this->submissions(),
                $this->scope()
            ))
        );

        $total = (int) Database::scalar(sprintf(
            'SELECT COUNT(*) FROM `%s` s WHERE %s',
            $this->submissions(),
            $this->scope()
        ));

        return [
            'answered' => count($values),
            'total'    => $total,
            'median'   => self::median($values),
        ];
    }

    /** @param list<float> $values */
    public static function median(array $values): ?float
    {
        $n = count($values);

        if ($n === 0) {
            return null;
        }

        sort($values);

        return $n % 2 === 1
            ? $values[intdiv($n, 2)]
            : round(($values[$n / 2 - 1] + $values[$n / 2]) / 2, 2);
    }

    /* ------------------------------------------------------------------ */
    /* Year over year                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Mean floor and mean average per year, across everything in scope.
     *
     * The two lines converging is what the reference's caption means by practice
     * catching up with paperwork.
     *
     * @return list<array{year:int, n:int, mean_floor:?float, mean_average:?float}>
     */
    public function yearOverYear(): array
    {
        return array_map(
            static fn (array $row): array => [
                'year'         => (int) $row['assessment_year'],
                'n'            => (int) $row['n'],
                'mean_floor'   => $row['mean_floor'] === null ? null : round((float) $row['mean_floor'], 2),
                'mean_average' => $row['mean_average'] === null ? null : round((float) $row['mean_average'], 2),
            ],
            Database::select(sprintf(
                'SELECT s.`assessment_year`, COUNT(*) AS n,
                        AVG(s.`floor_score`) AS mean_floor, AVG(s.`mean_score`) AS mean_average
                   FROM `%s` s
                  WHERE %s
               GROUP BY s.`assessment_year`
               ORDER BY s.`assessment_year`',
                $this->submissions(),
                $this->scope()
            ))
        );
    }

    /**
     * Organisations that have scored in more than one year.
     *
     * Grouped by the platform organisation row where there is one, so three
     * spellings of a company name are one organisation. A record with no
     * organisation row cannot be tracked across years and is left out.
     *
     * @return list<array<string, mixed>>
     */
    public function rescoredOrganisations(): array
    {
        $rows = Database::select(sprintf(
            'SELECT s.`organisation_id`, o.`name`, s.`assessment_year`,
                    COUNT(*) AS n,
                    AVG(s.`mean_score`) AS mean_average,
                    MIN(s.`sector`) AS sector
               FROM `%s` s
               JOIN `organisations` o ON o.`id` = s.`organisation_id`
              WHERE %s AND s.`organisation_id` IS NOT NULL
           GROUP BY s.`organisation_id`, o.`name`, s.`assessment_year`
           ORDER BY o.`name`, s.`assessment_year`',
            $this->submissions(),
            $this->scope()
        ));

        // Floor codes per organisation-year, for the "weakest dimension" column.
        $floors = [];
        foreach (Database::select(sprintf(
            'SELECT s.`organisation_id`, s.`assessment_year`, d.`code`, COUNT(*) AS n
               FROM `%s` s
               JOIN `%s` d ON d.`id` = s.`floor_dimension_id`
              WHERE %s AND s.`organisation_id` IS NOT NULL
           GROUP BY s.`organisation_id`, s.`assessment_year`, d.`code`
           ORDER BY n DESC, d.`code`',
            $this->submissions(),
            $this->app->table('dimensions'),
            $this->scope()
        )) as $row) {
            $key = $row['organisation_id'] . ':' . $row['assessment_year'];
            // Ordered by count descending, so the first one wins: the dimension
            // most of that organisation's respondents scored lowest.
            $floors[$key] ??= $row['code'];
        }

        $byOrganisation = [];
        foreach ($rows as $row) {
            $id = (int) $row['organisation_id'];

            $byOrganisation[$id] ??= [
                'organisation_id' => $id,
                'name'            => $row['name'],
                'sector'          => $row['sector'],
                'years'           => [],
            ];

            $byOrganisation[$id]['years'][(int) $row['assessment_year']] = [
                'n'          => (int) $row['n'],
                'mean'       => $row['mean_average'] === null ? null : round((float) $row['mean_average'], 2),
                'floor_code' => $floors[$id . ':' . (int) $row['assessment_year']] ?? null,
            ];
        }

        // Only organisations that actually re-scored. One year is not a trend.
        $multi = array_values(array_filter(
            $byOrganisation,
            static fn (array $o): bool => count($o['years']) > 1
        ));

        foreach ($multi as &$organisation) {
            ksort($organisation['years']);
            $years = array_keys($organisation['years']);
            $first = $organisation['years'][$years[0]];
            $last  = $organisation['years'][$years[count($years) - 1]];

            $organisation['first_year'] = $years[0];
            $organisation['last_year']  = $years[count($years) - 1];
            $organisation['change']     = ($first['mean'] !== null && $last['mean'] !== null)
                ? round($last['mean'] - $first['mean'], 2)
                : null;
            $organisation['floor_from'] = $first['floor_code'];
            $organisation['floor_to']   = $last['floor_code'];
        }
        unset($organisation);

        usort($multi, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $multi;
    }

    /** Every year present in scope, ascending. @return list<int> */
    public function years(): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['assessment_year'],
            Database::select(sprintf(
                'SELECT DISTINCT s.`assessment_year` FROM `%s` s WHERE %s ORDER BY s.`assessment_year`',
                $this->submissions(),
                $this->scope()
            ))
        );
    }

    /* ------------------------------------------------------------------ */
    /* One dimension in detail                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The item breakdown for one dimension: every question, its mean, and how
     * the five marks are distributed across it.
     *
     * @return array<string, mixed>|null null when the code is not in the
     *         published instrument
     */
    public function dimension(string $code): ?array
    {
        $dimension = Database::selectOne(sprintf(
            'SELECT d.* FROM `%s` d
               JOIN `%s` i ON i.`id` = d.`instrument_id`
              WHERE i.`status` = \'published\' AND d.`code` = ?
              LIMIT 1',
            $this->app->table('dimensions'),
            $this->app->table('instruments')
        ), [$code]);

        if ($dimension === null) {
            return null;
        }

        $items = Database::select(sprintf(
            'SELECT i.`id`, i.`position`, i.`label`, i.`question`,
                    COUNT(r.`id`) AS n, AVG(r.`mark`) AS mean_mark,
                    SUM(r.`mark` = 1) AS m1, SUM(r.`mark` = 2) AS m2, SUM(r.`mark` = 3) AS m3,
                    SUM(r.`mark` = 4) AS m4, SUM(r.`mark` = 5) AS m5
               FROM `%s` i
          LEFT JOIN `%s` r ON r.`item_id` = i.`id`
          LEFT JOIN `%s` s ON s.`id` = r.`submission_id` AND %s
              WHERE i.`dimension_id` = ?
                AND (r.`id` IS NULL OR s.`id` IS NOT NULL)
           GROUP BY i.`id`, i.`position`, i.`label`, i.`question`
           ORDER BY i.`position`',
            $this->app->table('items'),
            $this->app->table('submission_responses'),
            $this->submissions(),
            $this->scope()
        ), [$dimension['id']]);

        $marks     = 0;
        $emerging  = 0;
        $prepared  = [];
        $weakest   = null;

        foreach ($items as $item) {
            $row = [
                'id'           => (int) $item['id'],
                'position'     => (int) $item['position'],
                'label'        => $item['label'],
                'question'     => $item['question'],
                'n'            => (int) $item['n'],
                'mean'         => $item['mean_mark'] === null ? null : round((float) $item['mean_mark'], 2),
                'distribution' => [
                    (int) $item['m1'], (int) $item['m2'], (int) $item['m3'],
                    (int) $item['m4'], (int) $item['m5'],
                ],
            ];

            $marks    += $row['n'];
            $emerging += $row['distribution'][1];   // marks at 2, Emerging

            if ($row['mean'] !== null && ($weakest === null || $row['mean'] < $weakest['mean'])) {
                $weakest = $row;
            }

            $prepared[] = $row;
        }

        $aggregate = null;
        foreach ($this->dimensions() as $candidate) {
            if ($candidate['code'] === $code) {
                $aggregate = $candidate;
                break;
            }
        }

        return [
            'dimension'       => $dimension,
            'aggregate'       => $aggregate,
            'items'           => $prepared,
            'weakest'         => $weakest,
            // The reference's "real but not held in place" warning fires above a
            // third of marks at Emerging: activity that exists but is carried by
            // individuals rather than by the organisation.
            'emerging_share'  => $marks > 0 ? round($emerging / $marks, 4) : 0.0,
            'warning_above'   => (float) $this->app->config('scoring.emerging_warning_share', 0.35),
            'by_sector'       => $this->dimensionBySector($code),
        ];
    }

    /** @return list<array{sector:string, n:int, mean:?float}> */
    public function dimensionBySector(string $code): array
    {
        return array_map(
            static fn (array $row): array => [
                'sector' => ($row['sector'] === null || $row['sector'] === '') ? 'Not specified' : $row['sector'],
                'n'      => (int) $row['n'],
                'mean'   => $row['mean_score'] === null ? null : round((float) $row['mean_score'], 2),
            ],
            Database::select(sprintf(
                'SELECT s.`sector`, COUNT(*) AS n, AVG(ss.`score`) AS mean_score
                   FROM `%s` ss
                   JOIN `%s` d ON d.`id` = ss.`dimension_id`
                   JOIN `%s` s ON s.`id` = ss.`submission_id`
                  WHERE d.`code` = ? AND %s
               GROUP BY s.`sector`
               ORDER BY mean_score DESC',
                $this->app->table('submission_scores'),
                $this->app->table('dimensions'),
                $this->submissions(),
                $this->scope()
            ), [$code])
        );
    }

    /* ------------------------------------------------------------------ */
    /* Correlation                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Associations between an organisation's overall score and the figures its
     * respondent reported about it.
     *
     * Scoped like every other pane (year, sector, level and the synthetic
     * toggle), as the reference's paneCorr() was. The seeder leaves meetings
     * and education hours NULL and draws the inventory independently of the
     * marks, so a synthetic scatter shows noise rather than a manufactured r,
     * and the lede above the tabs says when synthetic records are on screen.
     *
     * The reference's caption is reproduced on the screen because it is the
     * important part: these are two self-reports from the same person about the
     * same organisation, in a self-selected sample, and they cannot establish
     * that exposure builds readiness rather than the reverse.
     *
     * Coefficients are withheld below the configured n. The scatter is still
     * worth showing: how thin the data is, is itself the result.
     *
     * @return list<array<string, mixed>>
     */
    public function correlations(): array
    {
        $minimum = (int) $this->app->config('scoring.correlation_min_n', 30);

        // Labels come from the instrument's reference list, so the chart axes and
        // the profile form's questions cannot drift apart.
        $metrics = [
            'agenda_meetings'   => 'Executive meetings with AI as a decision item',
            'education_hours'   => 'Hours of AI education taken',
            'systems_inventory' => 'AI systems on the inventory',
        ];

        $results = [];

        foreach ($metrics as $column => $label) {
            $pairs = Database::select(sprintf(
                'SELECT s.`mean_score` AS x, s.`%s` AS y, s.`organisation_name` AS label
                   FROM `%s` s
                  WHERE %s
                    AND s.`mean_score` IS NOT NULL
                    AND s.`%s` IS NOT NULL',
                $column,
                $this->submissions(),
                $this->scope(),
                $column
            ));

            $xs = array_map(static fn (array $p): float => (float) $p['x'], $pairs);
            $ys = array_map(static fn (array $p): float => (float) $p['y'], $pairs);

            $results[] = [
                'metric'     => $column,
                'label'      => $label,
                'n'          => count($pairs),
                'points'     => array_map(
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
     * Returns null when the sample is too small or a variable does not vary, so a
     * caller cannot print a coefficient that means nothing. n = 3 would make the
     * Fisher standard error 1/sqrt(0).
     *
     * @param list<float> $xs
     * @param list<float> $ys
     * @return array{r:float, n:int, lo:float, hi:float, crosses:bool}|null
     */
    public static function pearson(array $xs, array $ys): ?array
    {
        $n = count($xs);

        if ($n < 4 || count($ys) !== $n) {
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

        if ($sxx === 0.0 || $syy === 0.0) {
            return null;
        }

        $r = $sxy / sqrt($sxx * $syy);

        // A perfect correlation makes the Fisher transform infinite. Report the
        // coefficient and no interval rather than printing "1 to 1".
        if (abs($r) >= 1.0) {
            return ['r' => round($r, 3), 'n' => $n, 'lo' => round($r, 3), 'hi' => round($r, 3), 'crosses' => false];
        }

        $z  = 0.5 * log((1 + $r) / (1 - $r));
        $se = 1 / sqrt($n - 3);
        $lo = tanh($z - 1.96 * $se);
        $hi = tanh($z + 1.96 * $se);

        return [
            'r'  => round($r, 3),
            'n'  => $n,
            'lo' => round($lo, 3),
            'hi' => round($hi, 3),
            // An interval spanning zero means the association is not
            // distinguishable from none, and the screen says so in those words.
            'crosses' => $lo < 0 && $hi > 0,
        ];
    }

    /**
     * The least-squares line for a scatter, so the chart does not fit its own.
     *
     * @param list<array{x:float,y:float}> $points
     * @return array{x0:float,y0:float,x1:float,y1:float}|null
     */
    public static function fitLine(array $points): ?array
    {
        $n = count($points);

        if ($n < 2) {
            return null;
        }

        $xs = array_column($points, 'x');
        $ys = array_column($points, 'y');

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $sxy = 0.0;
        $sxx = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $sxy += ($xs[$i] - $meanX) * ($ys[$i] - $meanY);
            $sxx += ($xs[$i] - $meanX) ** 2;
        }

        if ($sxx === 0.0) {
            return null;
        }

        $slope     = $sxy / $sxx;
        $intercept = $meanY - $slope * $meanX;

        $x0 = min($xs);
        $x1 = max($xs);

        return [
            'x0' => $x0,
            'y0' => $intercept + $slope * $x0,
            'x1' => $x1,
            'y1' => $intercept + $slope * $x1,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Organisations                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Everything about one organisation: its respondents, its dimension means
     * with the spread between them, and its movement year on year.
     *
     * @return array<string, mixed>|null
     */
    public function organisation(int $organisationId): ?array
    {
        $organisation = Database::selectOne(
            'SELECT `id`, `name`, `sector`, `size_band`, `is_synthetic` FROM `organisations` WHERE `id` = ?',
            [$organisationId]
        );

        if ($organisation === null) {
            return null;
        }

        $submissions = Database::select(sprintf(
            'SELECT s.`id`, s.`public_token`, s.`respondent_name`, s.`role`, s.`assessment_year`,
                    s.`mean_score`, s.`floor_score`, s.`level_value`, s.`is_synthetic`,
                    s.`submitted_at`, s.`organisation_size`, s.`sector`,
                    d.`code` AS floor_code
               FROM `%s` s
          LEFT JOIN `%s` d ON d.`id` = s.`floor_dimension_id`
              WHERE %s AND s.`organisation_id` = ?
           ORDER BY s.`assessment_year`, s.`submitted_at`',
            $this->submissions(),
            $this->app->table('dimensions'),
            $this->scope()
        ), [$organisationId]);

        if ($submissions === []) {
            return null;
        }

        $years = array_values(array_unique(array_map(
            static fn (array $s): int => (int) $s['assessment_year'],
            $submissions
        )));
        sort($years);

        $firstYear = $years[0];
        $lastYear  = $years[count($years) - 1];

        return [
            'organisation' => $organisation,
            'submissions'  => $submissions,
            'years'        => $years,
            'size'         => $submissions[0]['organisation_size'] ?? $organisation['size_band'],
            'sector'       => $submissions[0]['sector'] ?? $organisation['sector'],
            'synthetic'    => array_filter($submissions, static fn (array $s): bool => (int) $s['is_synthetic'] === 1) !== [],
            // The latest year is the organisation's current position; the first
            // is where it started. With one year, both are the same set.
            'latest'       => $this->organisationDimensions($organisationId, $lastYear),
            'first'        => $this->organisationDimensions($organisationId, $firstYear),
        ];
    }

    /**
     * One organisation's dimension means for one year, with the min-max range
     * across its respondents.
     *
     * The range is what makes a multi-respondent organisation worth looking at:
     * a wide spread on one dimension means its own executives do not agree about
     * what the organisation does.
     *
     * @return array{dimensions:list<array<string,mixed>>, mean:?float, floor:?float, floor_code:?string, n:int}
     */
    public function organisationDimensions(int $organisationId, int $year): array
    {
        $rows = Database::select(sprintf(
            'SELECT d.`id`, d.`code`, d.`name`, d.`position`,
                    COUNT(ss.`id`) AS n,
                    AVG(ss.`score`) AS mean_score,
                    MIN(ss.`score`) AS min_score,
                    MAX(ss.`score`) AS max_score
               FROM `%s` d
          LEFT JOIN `%s` ss ON ss.`dimension_id` = d.`id`
          LEFT JOIN `%s` s  ON s.`id` = ss.`submission_id`
                           AND s.`organisation_id` = ? AND s.`assessment_year` = ? AND %s
              WHERE d.`instrument_id` = (SELECT `id` FROM `%s` WHERE `status` = \'published\' ORDER BY `published_at` DESC LIMIT 1)
                AND (ss.`id` IS NULL OR s.`id` IS NOT NULL)
           GROUP BY d.`id`, d.`code`, d.`name`, d.`position`
           ORDER BY d.`position`',
            $this->app->table('dimensions'),
            $this->app->table('submission_scores'),
            $this->submissions(),
            $this->scope(),
            $this->app->table('instruments')
        ), [$organisationId, $year]);

        $dimensions = array_map(static fn (array $row): array => [
            'id'    => (int) $row['id'],
            'code'  => $row['code'],
            'name'  => $row['name'],
            'n'     => (int) $row['n'],
            'mean'  => $row['mean_score'] === null ? null : round((float) $row['mean_score'], 2),
            'min'   => $row['min_score'] === null ? null : round((float) $row['min_score'], 2),
            'max'   => $row['max_score'] === null ? null : round((float) $row['max_score'], 2),
        ], $rows);

        $scored = array_values(array_filter(
            array_column($dimensions, 'mean'),
            static fn (?float $m): bool => $m !== null
        ));

        $floor = $this->aggregateFloor($dimensions);

        $respondents = (int) Database::scalar(sprintf(
            'SELECT COUNT(*) FROM `%s` s WHERE %s AND s.`organisation_id` = ? AND s.`assessment_year` = ?',
            $this->submissions(),
            $this->scope()
        ), [$organisationId, $year]);

        return [
            'year'       => $year,
            'dimensions' => $dimensions,
            // Banded from the average, as everywhere else.
            'mean'       => $scored === [] ? null : round(array_sum($scored) / count($scored), 2),
            'floor'      => $floor['mean'],
            'floor_code' => $floor['code'],
            'n'          => $respondents,
        ];
    }

    /**
     * A plain reading of the dataset, for the overview pane.
     *
     * The reference asked an AI endpoint for this from the browser with no key,
     * so what actually rendered was its fallback: the counts and the gap, stated.
     * That is what this returns, composed from the figures on the same screen so
     * the paragraph cannot claim something the panels above it contradict.
     */
    public function datasetReading(): string
    {
        $overview   = $this->overview();
        $dimensions = $this->dimensions();

        if ($overview['n'] === 0) {
            return 'No scorecards match the current filters.';
        }

        $format = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);

        /*
         * The reference's fallback, sentence for sentence: "Across N submissions
         * the mean overall score is X and the mean floor, the weakest dimension,
         * is Y. {Dimension} is the most common cap."
         *
         * The dimension it names is the reference's: the one with the lowest
         * mean across the set (its `s.floorCode`), the same dimension the
         * Floor tag marks on the bars above, found by aggregateFloor() with the
         * same strict less-than so a tie resolves the same way.
         */
        $sentences = [sprintf(
            'Across %d submissions the mean overall score is %s and the mean floor, the weakest '
            . 'dimension, is %s.',
            $overview['n'],
            $format($overview['mean_average']),
            $format($overview['mean_floor'])
        )];

        $floor = $this->aggregateFloor($dimensions);

        foreach ($dimensions as $dimension) {
            if ($floor['code'] !== null && $dimension['code'] === $floor['code']) {
                $sentences[] = $dimension['name'] . ' is the most common cap.';
            }
        }

        return implode(' ', $sentences);
    }
}
