<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services\Export;

use Apps\AiExecutiveReadinessScorecard\Services\InstrumentData;
use Apps\AiExecutiveReadinessScorecard\Services\Records;
use Apps\AiExecutiveReadinessScorecard\Services\Scopes;
use Platform\Core\AppManifest;

/**
 * The three downloads — the reference's exportCSV(), exportJSON() and
 * exportCodebook() — with the reference's columns, keys, wording and filenames.
 *
 * All three are built from Records, the same array every admin screen reads, so
 * a download cannot disagree with the screen it was pressed on.
 */
final class Exports
{
    private const INSTRUMENT = 'AI Executive Readiness — The Enterprise Scorecard';

    public function __construct(private readonly AppManifest $app)
    {
    }

    /** @return list<array<string, mixed>> */
    public function records(bool $includeSynthetic): array
    {
        return (new Records($this->app))->all($includeSynthetic);
    }

    public function filename(string $extension): string
    {
        return 'ai-executive-readiness-' . date('Y-m-d') . '.' . $extension;
    }

    /**
     * exportCSV(): identity and context, then per scope the summary and every
     * dimension followed by its items, then the differences.
     *
     * @param list<array<string, mixed>> $records
     */
    public function csv(array $records): string
    {
        $fields = [
            ['id',                      static fn (array $r) => $r['id']],
            ['timestamp',               static fn (array $r) => $r['timestamp']],
            ['year',                    static fn (array $r) => $r['year']],
            ['synthetic',               static fn (array $r) => $r['synthetic'] ? 1 : 0],
            ['organization',            static fn (array $r) => $r['org']['org']],
            ['organization_size',       static fn (array $r) => $r['org']['size']],
            ['respondent',              static fn (array $r) => $r['userName']],
            ['email',                   static fn (array $r) => $r['userEmail']],
            ['phone',                   static fn (array $r) => $r['userPhone']],
            ['consent_at',              static fn (array $r) => $r['org']['consent']['at'] ?? null],
            ['role',                    static fn (array $r) => $r['org']['role']],
            ['sector',                  static fn (array $r) => $r['org']['sector']],
            ['tenure',                  static fn (array $r) => $r['org']['tenure']],
            ['ai_systems_on_inventory', static fn (array $r) => $r['org']['systems']],
            ['ai_decision_meetings',    static fn (array $r) => $r['org']['agenda']],
            ['ai_education_hours',      static fn (array $r) => $r['org']['edu']],
        ];

        $dimensions = $this->dimensions();

        foreach (Scopes::KEYS as $s) {
            foreach (['mean', 'level', 'floor', 'floorCode'] as $k) {
                $fields[] = [$s . '_' . $k, static fn (array $r) => $r['scores'][$s][$k]];
            }

            foreach ($dimensions as $d) {
                $code     = $d['code'];
                $fields[] = [$s . '_' . $code, static fn (array $r) => $r['scores'][$s]['dims'][$code]];

                for ($i = 0; $i < $d['items']; $i++) {
                    $fields[] = [$s . '_' . $code . ($i + 1), static fn (array $r) => $r['responses'][$s][$code][$i] ?? null];
                }
            }
        }

        $fields[] = ['difference_overall', static fn (array $r) => $r['difference']['mean']];

        foreach ($dimensions as $d) {
            $code     = $d['code'];
            $fields[] = ['difference_' . $code, static fn (array $r) => $r['difference']['dims'][$code]];

            for ($i = 0; $i < $d['items']; $i++) {
                $fields[] = ['difference_' . $code . ($i + 1), static fn (array $r) => $r['difference']['items'][$code][$i]];
            }
        }

        $lines   = [implode(',', array_map([self::class, 'cell'], array_column($fields, 0)))];
        foreach ($records as $record) {
            $lines[] = implode(',', array_map(static fn (array $f): string => self::cell($f[1]($record)), $fields));
        }

        return implode("\n", $lines);
    }

    /**
     * csvCell(): always quoted, quotes doubled, and a leading = + @ tab or CR
     * prefixed with an apostrophe so a spreadsheet does not run it as a formula.
     * Numbers are written the way JavaScript's String() writes them.
     */
    public static function cell(mixed $value): string
    {
        $s = match (true) {
            $value === null  => '',
            is_bool($value)  => $value ? 'true' : 'false',
            is_float($value) => self::jsNumber($value),
            default          => (string) $value,
        };

        return '"' . (preg_match('/^[=+@\t\r]/', $s) ? "'" : '') . str_replace('"', '""', $s) . '"';
    }

    /** A float as JavaScript prints it: integers without ".0", the rest in full. */
    private static function jsNumber(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        // json_encode writes the shortest round-trip form, as JavaScript does.
        return (string) json_encode($value);
    }

    /**
     * exportJSON(): a header and the records, as the reference's browser stored
     * them.
     *
     * @param list<array<string, mixed>> $records
     */
    public function json(array $records, bool $includeSynthetic): string
    {
        return self::pretty([
            'instrument'         => self::INSTRUMENT,
            'version'            => $this->instrument()['version'],
            'exported'           => gmdate('Y-m-d\TH:i:s.000\Z'),
            'includes_synthetic' => $includeSynthetic,
            'n'                  => count($records),
            'scoring'            => 'Each scope is scored separately. Domain means use three ratings. Overall means use 12 ratings. Differences are self minus leadership, calculated before rounding.',
            'records'            => array_map([Records::class, 'export'], $records),
        ]);
    }

    /** exportCodebook(): the instrument, its levels and its rules, in the reference's words. */
    public function codebook(): string
    {
        $data       = new InstrumentData($this->app);
        $instrument = $this->instrument();
        $id         = (int) $instrument['id'];
        $rungs      = $data->rungs($id);
        $scopes     = [];

        foreach (Scopes::all() as $scope) {
            $scopes[$scope['key']] = $scope['label'];
        }

        $last = count($rungs) - 1;

        return self::pretty([
            'instrument'                => self::INSTRUMENT,
            'version'                   => $instrument['version'],
            'questions'                 => array_sum(array_map(static fn (array $d): int => count($d['items']), $data->dimensions($id))),
            'ratings_per_questionnaire' => $data->scoring($id)->ratingsPerQuestionnaire(),
            'scopes'                    => $scopes,
            'instructions'              => $instrument['instructions'],
            'dimensions'                => array_map(static fn (array $d): array => [
                'code'        => $d['code'],
                'name'        => $d['name'],
                'description' => $d['description'],
                'items'       => array_map(static fn (array $item, int $i): array => [
                    'id'         => $d['code'] . ($i + 1),
                    'question'   => $item['question'],
                    'low_label'  => $item['anchor_low_label'],
                    'high_label' => $item['anchor_high_label'],
                    'anchors'    => [
                        'self'       => ['a1' => $item['self_low_text'], 'a5' => $item['self_high_text']],
                        'leadership' => ['a1' => $item['leadership_low_text'], 'a5' => $item['leadership_high_text']],
                    ],
                ], $d['items'], array_keys($d['items'])),
            ], $data->dimensions($id)),
            'levels' => array_map(static fn (array $r, int $i): array => [
                'name'            => $r['name'],
                'description'     => $r['description'],
                'lower_inclusive' => $i === 0 ? 1 : (float) $r['band_min'],
                'upper'           => $i === $last ? 5 : (float) $r['band_max'],
                'upper_inclusive' => $i === $last,
            ], $rungs, array_keys($rungs)),
            'scoring' => [
                'domain'      => 'Arithmetic mean of three valid integer ratings within the same scope.',
                'overall'     => 'Arithmetic mean of 12 ratings within the same scope. All 24 ratings are required to complete a questionnaire.',
                'rounding'    => 'Store unrounded scores. Assign levels and compute differences before display rounding to two decimal places.',
                'difference'  => 'Self minus leadership for each item, domain, and overall score. Range -4 to +4.',
                'floor'       => 'Lowest domain score within each scope. If tied, floorCode identifies the first tied domain in questionnaire order.',
                'aggregation' => 'Each questionnaire contributes once to each scope. Repeated assessments can contribute separately. Counts identify questionnaires, respondents, and organizations.',
                'correlation' => 'Latest filtered assessment per respondent, with at least 30 paired observations. Descriptive Pearson correlation with Fisher 95% interval. Repeated organizations may still create dependence.',
            ],
            'fields' => [
                'responses'  => 'responses.self and responses.leadership contain dimension codes and zero-based item indexes.',
                'scores'     => 'scores.self and scores.leadership contain dims, mean, level, floor, and floorCode.',
                'difference' => 'difference.mean, difference.dims and difference.items store self minus leadership.',
                'synthetic'  => 'True for generated demo data. These are never observations of real leaders.',
            ],
            'caveats' => [
                $instrument['disclaimer'],
                'Both profiles are reported by the same respondent.',
                'Differences describe perceptions, not demonstrated performance.',
                'The instrument has not been empirically validated.',
                'Samples are self-selected. Associations do not establish causation.',
            ],
        ]);
    }

    /**
     * JSON.stringify(value, null, 2): two-space indentation, slashes and
     * non-ASCII left as they are.
     */
    private static function pretty(array $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', strlen($m[0]) / 4), $json);
    }

    /** @return array<string, mixed> */
    private function instrument(): array
    {
        return (new InstrumentData($this->app))->published();
    }

    /** @return list<array{code:string, items:int}> */
    private function dimensions(): array
    {
        $data = new InstrumentData($this->app);

        return $data->scoring((int) $data->published()['id'])->dimensions();
    }
}
