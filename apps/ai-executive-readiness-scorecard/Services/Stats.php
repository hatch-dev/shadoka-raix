<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services;

use Apps\AiExecutiveReadinessScorecard\Services\Scoring\PairedScoring;
use Platform\Assessment\Bander;

/**
 * The reference's aggregation functions, over Records::all()-shaped arrays.
 *
 * Each method is named after, and computes exactly what, its namesake in the
 * reference build did: aggregate(), aggDims(), groupScores(), levelRows(),
 * comparisonRows(), pearson(), respondentKey(). Keeping the names means a
 * question about a number on screen can be answered by reading the reference
 * and this file side by side.
 *
 * Two JavaScript behaviours are reproduced on purpose, because they decide what
 * appears:
 *
 *  - `mean([])` is null, and `sd()` of fewer than two values is 0 (population
 *    SD, not sample).
 *  - Counts are formatted "n (p%)" with `Math.round`, which rounds .5 up —
 *    PHP's round() rounds .5 away from zero, the same for these non-negative
 *    shares.
 */
final class Stats
{
    public function __construct(private readonly PairedScoring $scoring)
    {
    }

    public function bander(): Bander
    {
        return $this->scoring->bander();
    }

    /** @return list<string> dimension codes in questionnaire order */
    public function codes(): array
    {
        return array_column($this->scoring->dimensions(), 'code');
    }

    /* ------------------------------------------------------------ basics -- */

    /** @param list<float|int> $values */
    public static function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** Population SD; 0 below two values. @param list<float|int> $values */
    public static function sd(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $m = array_sum($values) / count($values);

        return sqrt(array_sum(array_map(static fn ($v): float => ($v - $m) ** 2, $values)) / count($values));
    }

    /** fmt(): two decimals, or an em dash. */
    public static function fmt(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }

    /** deltaText(): signed, with 0.00 for anything that rounds to zero. */
    public static function delta(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (abs($value) < 0.005) {
            return '0.00';
        }

        return ($value > 0 ? '+' : '') . number_format($value, 2);
    }

    /** "n (p%)", the reference's proportion. */
    public static function share(int $n, int $total): string
    {
        return $n . ' (' . ($total > 0 ? (int) round($n / $total * 100) : 0) . '%)';
    }

    /* -------------------------------------------------------- identities -- */

    /** respondentKey(): organisation plus email (or name, or id), lowercased. */
    public static function respondentKey(array $record): string
    {
        $who = $record['userEmail'] ?: ($record['org']['email'] ?? '') ?: $record['userName'] ?: $record['id'];

        return mb_strtolower(trim((string) $record['org']['org'])) . '|' . mb_strtolower(trim((string) $who));
    }

    /** @param list<array<string, mixed>> $records */
    public static function respondentCount(array $records): int
    {
        return count(array_unique(array_map([self::class, 'respondentKey'], $records)));
    }

    /** @param list<array<string, mixed>> $records */
    public static function orgCount(array $records): int
    {
        return count(array_unique(array_map(
            static fn (array $r): string => mb_strtolower(trim((string) $r['org']['org'])),
            $records
        )));
    }

    /** byNewest(): newest first; ties keep their order. @param list<array<string,mixed>> $records */
    public static function newestFirst(array $records): array
    {
        $indexed = array_map(null, array_keys($records), $records);

        usort($indexed, static function (array $a, array $b): int {
            $order = strcmp((string) $b[1]['timestamp'], (string) $a[1]['timestamp']);

            return $order !== 0 ? $order : $a[0] <=> $b[0];
        });

        return array_map(static fn (array $pair): array => $pair[1], $indexed);
    }

    /* ---------------------------------------------------------- pooling -- */

    /**
     * aggregate(): per dimension, n / mean / min / max / sd of the scope's
     * dimension scores across the records.
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, array{n:int, mean:?float, min:?float, max:?float, sd:?float}>
     */
    public function aggregate(array $records, string $scope = 'self'): array
    {
        $out = [];

        foreach ($this->codes() as $code) {
            $values = [];
            foreach ($records as $record) {
                $value = $record['scores'][$scope]['dims'][$code] ?? null;
                if ($value !== null) {
                    $values[] = (float) $value;
                }
            }

            $out[$code] = [
                'n'    => count($values),
                'mean' => self::mean($values),
                'min'  => $values === [] ? null : min($values),
                'max'  => $values === [] ? null : max($values),
                'sd'   => $values === [] ? null : self::sd($values),
            ];
        }

        return $out;
    }

    /**
     * Both scopes' aggregates at once — the `spread` argument pairedBars() takes.
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function spread(array $records): array
    {
        return [
            'self'       => $this->aggregate($records, 'self'),
            'leadership' => $this->aggregate($records, 'leadership'),
        ];
    }

    /**
     * groupScores(): for each scope, the dimension means pooled across records,
     * then summarize() over those — so the pooled overall is the mean of the
     * four pooled dimension means, and its level is that number's band.
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, array<string, mixed>>
     */
    public function groupScores(array $records): array
    {
        $out = [];

        foreach (PairedScoring::SCOPES as $scope) {
            $dims = array_map(
                static fn (array $a): ?float => $a['mean'],
                $this->aggregate($records, $scope)
            );

            $out[$scope] = ['dims' => $dims] + $this->scoring->summarize($dims);
        }

        return $out;
    }

    /**
     * levelRows(): one row per level, "n (p%)" of questionnaires at it in each
     * scope.
     *
     * @param list<array<string, mixed>> $records
     * @return list<array{0:string, 1:string, 2:string}>
     */
    public function levelRows(array $records): array
    {
        $rows  = [];
        $total = count($records);

        foreach ($this->bander()->rungs() as $rung) {
            $row = [$rung['name']];

            foreach (PairedScoring::SCOPES as $scope) {
                $n = count(array_filter(
                    $records,
                    static fn (array $r): bool => ($r['scores'][$scope]['level'] ?? null) === $rung['name']
                ));

                $row[] = self::share($n, $total);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Level counts per scope, for the Overview's bar chart.
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, list<int>> scope → one count per rung
     */
    public function levelCounts(array $records): array
    {
        $out = [];

        foreach (PairedScoring::SCOPES as $scope) {
            $out[$scope] = array_map(
                static fn (array $rung): int => count(array_filter(
                    $records,
                    static fn (array $r): bool => ($r['scores'][$scope]['level'] ?? null) === $rung['name']
                )),
                $this->bander()->rungs()
            );
        }

        return $out;
    }

    /**
     * comparisonRows(): the difference measures — overall, each dimension and
     * each question — with mean, SD, range and how often self came out higher,
     * equal or lower.
     *
     * @param list<array<string, mixed>> $records
     * @param list<array<string, mixed>> $dimensions InstrumentData dimensions (with items)
     * @return list<array<string, mixed>>
     */
    public function comparisonRows(array $records, array $dimensions): array
    {
        $metrics = [['Overall', static fn (array $r): ?float => $r['difference']['mean']]];

        foreach ($dimensions as $dimension) {
            $code = (string) $dimension['code'];

            $metrics[] = [
                $code . ' · ' . $dimension['name'],
                static fn (array $r): ?float => $r['difference']['dims'][$code],
            ];

            foreach ($dimension['items'] as $i => $item) {
                $metrics[] = [
                    $code . ' ' . ($i + 1) . ' · ' . $item['label'],
                    static fn (array $r): ?float => $r['difference']['items'][$code][$i] === null
                        ? null
                        : (float) $r['difference']['items'][$code][$i],
                ];
            }
        }

        $rows = [];

        foreach ($metrics as [$label, $get]) {
            $values = array_map(static fn (array $r): float => (float) $get($r), $records);

            $rows[] = [
                'label'  => $label,
                'n'      => count($values),
                'mean'   => self::mean($values),
                'sd'     => self::sd($values),
                'min'    => $values === [] ? null : min($values),
                'max'    => $values === [] ? null : max($values),
                'higher' => count(array_filter($values, static fn (float $v): bool => $v > 1e-9)),
                'equal'  => count(array_filter($values, static fn (float $v): bool => abs($v) < 1e-9)),
                'lower'  => count(array_filter($values, static fn (float $v): bool => $v < -1e-9)),
            ];
        }

        return $rows;
    }

    /**
     * pearson(): r with a Fisher 95% interval; null below four points or when
     * either variable does not vary.
     *
     * @param list<float> $xs
     * @param list<float> $ys
     * @return array{r:float, n:int, lo:float, hi:float}|null
     */
    public static function pearson(array $xs, array $ys): ?array
    {
        $n = count($xs);

        if ($n < 4) {
            return null;
        }

        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;

        $xy = 0.0;
        $xx = 0.0;
        $yy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $a   = $xs[$i] - $mx;
            $b   = $ys[$i] - $my;
            $xy += $a * $b;
            $xx += $a * $a;
            $yy += $b * $b;
        }

        if ($xx == 0.0 || $yy == 0.0) {
            return null;
        }

        $r  = max(-1.0, min(1.0, $xy / sqrt($xx * $yy)));
        $z  = atanh(max(-0.999999999999, min(0.999999999999, $r)));
        $se = 1 / sqrt($n - 3);

        return ['r' => $r, 'n' => $n, 'lo' => tanh($z - 1.96 * $se), 'hi' => tanh($z + 1.96 * $se)];
    }

    /**
     * Each respondent's LATEST questionnaire — the reference's
     * `[...new Map(recs.slice().sort(byNewest).reverse().map(r => [key, r])).values()]`:
     * oldest to newest into a map, so the newest wins, in first-seen order.
     *
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public static function latestPerRespondent(array $records): array
    {
        $map = [];

        foreach (array_reverse(self::newestFirst($records)) as $record) {
            $map[self::respondentKey($record)] = $record;
        }

        return array_values($map);
    }
}
