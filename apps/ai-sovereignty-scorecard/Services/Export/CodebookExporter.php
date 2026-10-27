<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Services\Export;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The codebook: what the instrument is, how it scores, and what its numbers may
 * not be used to claim.
 *
 * The reference's `exportCodebook()`, key for key and in its order. What that
 * build read from its DIMS and RUNGS constants is read here from the published
 * instrument rows — the level names and definitions, the band cut points, every
 * dimension, question and anchor — so the file cannot describe a version the
 * database no longer holds.
 *
 * The prose values are the reference's own and are reproduced as written, on
 * request, including two that describe rules nothing computes: `coverage` and
 * `cadence`. The same `coverage` sentence is in the JSON export's `rule` string.
 * A researcher who goes looking for either in the data will not find it.
 *
 * Printed with two-space indentation, as `JSON.stringify(…, null, 2)` did.
 */
final class CodebookExporter
{
    /** The reference's `instrument` value. */
    private const TITLE = 'AI Sovereignty Scorecard · Enterprise AI Sovereignty';

    public function __construct(private readonly AppManifest $app)
    {
    }

    public function filename(): string
    {
        return 'ai-sovereignty-scorecard-codebook.json';
    }

    public function json(): string
    {
        $instrument = $this->instrument();

        if ($instrument === []) {
            return json_encode(['error' => 'No published instrument.'], JSON_PRETTY_PRINT);
        }

        $instrumentId = (int) $instrument['id'];
        $rungs        = $this->rungs($instrumentId);
        $dimensions   = $this->dimensions($instrumentId);
        $minimumN     = (int) $this->app->config('scoring.correlation_min_n', 30);

        // "unweighted mean of four item marks" — the count is the instrument's.
        $perDimension = $dimensions === [] ? 0 : (int) round(
            array_sum(array_map(static fn (array $d): int => count($d['items']), $dimensions)) / count($dimensions)
        );

        $document = [
            'instrument' => self::TITLE,
            'version'    => $instrument['version'],
            'scoring'    => [
                'scale' => array_map(
                    static fn (array $rung): array => [
                        'value'      => (int) $rung['value'],
                        'name'       => $rung['name'],
                        'definition' => $rung['description'],
                    ],
                    $rungs
                ),
                'dimension_score'    => 'unweighted mean of ' . self::word($perDimension) . ' item marks',
                'organization_level' => 'band of the overall average of all answers',
                'floor'              => 'the weakest scored dimension and its score, reported in analytics only',
                'coverage'           => 'a dimension is covered when two or more respondents from the same '
                                      . 'organization score it at or above Developing',
                'bands'              => $this->bands($rungs),
                'cadence'            => ['full' => 'annual', 'below_defined' => 'quarterly', 'incident' => 'immediate'],
            ],
            'dimensions' => array_map(
                static fn (array $dimension): array => [
                    'code'              => $dimension['code'],
                    'name'              => $dimension['name'],
                    'description'       => $dimension['description'],
                    'question_to_press' => $dimension['pressing_question'],
                    'failing_signature' => $dimension['failure_line'],
                    'items'             => array_map(
                        static fn (array $item): array => [
                            'n'        => (int) $item['position'],
                            'label'    => $item['label'],
                            'question' => $item['question'],
                            'anchor_1' => $item['anchor_low_text'],
                            'anchor_5' => $item['anchor_high_text'],
                        ],
                        $dimension['items']
                    ),
                ],
                $dimensions
            ),
            'identification' => [
                'required_fields' => ['full name', 'email', 'phone', 'organization', 'organization size'],
                'validation'      => 'format only: a two-part name, a parseable address on a non-disposable '
                                   . 'domain, 7 to 15 digits, a non-empty organization, a size band from the '
                                   . 'fixed list',
                'verified'        => false,
                'note'            => 'no external verification is performed; every identity field is self-asserted',
            ],
            'caveats' => [
                'self-reported and unverified',
                'identity fields are self-asserted, not checked against any register',
                'self-selected sample',
                'correlations withheld below n=' . $minimumN,
                'not empirically validated',
            ],
        ];

        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        // PHP indents by four; the reference's file is indented by two. Every
        // newline in the output is structural — string values escape theirs.
        return (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)),
            $json
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * The reference's `bands` object — `{Undeveloped:'<1.80', Emerging:'1.80–2.59',
     * …, Embedded:'≥4.20'}` — written from the stored [min, max) cut points.
     *
     * @param list<array<string, mixed>> $rungs
     * @return array<string, string>
     */
    private function bands(array $rungs): array
    {
        $out = [];

        foreach ($rungs as $rung) {
            $min = $rung['band_min'] === null ? null : (float) $rung['band_min'];
            $max = $rung['band_max'] === null ? null : (float) $rung['band_max'];

            $out[$rung['name']] = match (true) {
                $min === null && $max !== null => '<' . number_format($max, 2),
                $max === null && $min !== null => '≥' . number_format($min, 2),
                default                        => number_format((float) $min, 2) . '–' . number_format((float) $max - 0.01, 2),
            };
        }

        return $out;
    }

    private static function word(int $n): string
    {
        return [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven'][$n]
            ?? (string) $n;
    }

    /** @return array<string, mixed> */
    private function instrument(): array
    {
        return Database::selectOne(sprintf(
            'SELECT * FROM `%s` WHERE `status` = \'published\' ORDER BY `published_at` DESC LIMIT 1',
            $this->app->table('instruments')
        )) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function rungs(int $instrumentId): array
    {
        return Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`', $this->app->table('rungs')),
            [$instrumentId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function dimensions(int $instrumentId): array
    {
        $dimensions = Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`', $this->app->table('dimensions')),
            [$instrumentId]
        );

        foreach ($dimensions as $index => $dimension) {
            $dimensions[$index]['items'] = Database::select(
                sprintf('SELECT * FROM `%s` WHERE `dimension_id` = ? ORDER BY `position`', $this->app->table('items')),
                [$dimension['id']]
            );
        }

        return $dimensions;
    }
}
