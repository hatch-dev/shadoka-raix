<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services\Export;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The codebook: what the instrument is, how it scores, and what its numbers may
 * not be used to claim.
 *
 * Generated entirely from the published instrument row, which is the fix for two
 * defects the reference codebook carried:
 *
 *  1. It documented a `coverage` rule — "a dimension is covered when two or more
 *     respondents from the same organization score it at or above Developing" —
 *     and nothing anywhere computed it. It appeared in the codebook AND in the
 *     JSON export's `rule` string, so a researcher would have gone looking for a
 *     field that does not exist. No rule is documented here unless it is
 *     implemented; if coverage is added later it gets a line at the same time
 *     and not before.
 *
 *  2. It described the cadence — annual, quarterly below defined, immediate on
 *     incident — which this instrument does not implement either. The Director
 *     Scorecard does compute a cadence and its codebook may say so; this one
 *     does not, so this one does not.
 *
 * Nothing here is written by hand that the database can state.
 */
final class CodebookExporter
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    public function filename(): string
    {
        $instrument = $this->instrument();

        return sprintf('enterprise-scorecard-codebook-v%s.json', $instrument['version'] ?? 'unknown');
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
        $itemCount    = array_sum(array_map(static fn (array $d): int => count($d['items']), $dimensions));
        $minimumN     = (int) $this->app->config('scoring.correlation_min_n', 30);

        return json_encode([
            // Name and version come from the row, so they cannot disagree with
            // the content underneath them.
            'instrument'    => $instrument['name'],
            'subtitle'      => $instrument['subtitle'],
            'version'       => $instrument['version'],
            'published_at'  => $instrument['published_at'],
            'generated_at'  => date('c'),
            'dimension_count' => count($dimensions),
            'item_count'      => $itemCount,

            'scoring' => [
                'scale' => array_map(
                    static fn (array $rung): array => [
                        'value'      => (int) $rung['value'],
                        'name'       => $rung['name'],
                        'definition' => $rung['description'],
                        // The cut points as stored on this version, so a reader
                        // can reproduce any level in the dataset exactly.
                        'band_min'   => $rung['band_min'] === null ? null : (float) $rung['band_min'],
                        'band_max'   => $rung['band_max'] === null ? null : (float) $rung['band_max'],
                    ],
                    $rungs
                ),
                'item_mark'        => 'one of 1 to 5, on a scale whose two ends are written out '
                                    . 'for that specific question',
                'dimension_score'  => 'unweighted mean of the dimension\'s five answered questions',
                'overall_score'    => 'unweighted mean of the scored dimension means; with all thirty '
                                    . 'questions answered, which submission requires, this equals the '
                                    . 'mean of all thirty marks',
                'organisation_level' => 'the band of the overall score',
                'floor'            => 'the weakest scored dimension and its score. Reported alongside '
                                    . 'the level and used throughout analytics. It does NOT set the '
                                    . 'level — that is the Director Scorecard\'s rule, not this one\'s',
                'unscored'         => 'a dimension with no answers is left out of the overall mean '
                                    . 'rather than counted as 1, and cannot be the floor',
                'band_boundaries'  => 'bands are [min, max) — a score exactly on a cut point lands in '
                                    . 'the HIGHER band, so 2.60 is Developing',
                'rule_text'        => $instrument['rule_text'],
            ],

            'dimensions' => array_map(
                static fn (array $dimension): array => [
                    'position'          => (int) $dimension['position'],
                    'code'              => $dimension['code'],
                    'name'              => $dimension['name'],
                    'description'       => $dimension['description'],
                    'question_to_press' => $dimension['pressing_question'],
                    'failing_signature' => $dimension['failure_line'],
                    'items' => array_map(
                        static fn (array $item): array => [
                            'n'        => (int) $item['position'],
                            'label'    => $item['label'],
                            'question' => $item['question'],
                            'anchor_1' => [
                                'label' => $item['anchor_low_label'],
                                'text'  => $item['anchor_low_text'],
                            ],
                            'anchor_5' => [
                                'label' => $item['anchor_high_label'],
                                'text'  => $item['anchor_high_text'],
                            ],
                        ],
                        $dimension['items']
                    ),
                ],
                $dimensions
            ),

            'reference_lists' => $this->referenceOptions($instrumentId),

            'identification' => [
                'required_fields' => [
                    'respondent name', 'email', 'phone',
                    'organization name', 'organization size',
                ],
                'validation' => 'format only: a two-part name (except in scripts where spacing does '
                              . 'not carry that meaning), a parseable address on a non-disposable '
                              . 'domain, 7 to 15 digits, a non-empty organization name, and a size '
                              . 'band from the instrument\'s own list',
                'verified'   => false,
                'note'       => 'no external verification is performed; every identity field is '
                              . 'self-asserted',
                'consent'    => 'a respondent accepts stated terms before the questionnaire opens; '
                              . 'the timestamp and the URL of the terms shown are stored on the record',
            ],

            'synthetic_records' => [
                'flagged_by'    => 'is_synthetic = 1, exported as "synthetic": true with an "archetype"',
                'excluded_from' => [
                    'benchmarks shown to a respondent',
                    'correlations, unconditionally',
                    'exports, unless the include-synthetic toggle is on',
                    'the "most recent submission" panel',
                ],
                'not_generated' => [
                    'agenda_meetings' => 'left null; generating it from a record\'s own score would '
                                       . 'manufacture the correlation the analytics screen reports',
                    'education_hours' => 'left null, for the same reason',
                    'consent'         => 'left null; nobody consented to anything',
                ],
            ],

            'caveats' => [
                'self-reported by an executive about their own organization, and unverified',
                'identity fields are self-asserted, not checked against any register',
                'self-selected sample',
                'correlations withheld below n=' . $minimumN,
                'associations only; the design cannot establish direction of effect',
                'not empirically validated',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /* ------------------------------------------------------------------ */

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

    /** @return array<string, list<string>> */
    private function referenceOptions(int $instrumentId): array
    {
        $grouped = [];

        foreach (Database::select(sprintf(
            'SELECT `group_key`, `value` FROM `%s` WHERE `instrument_id` = ? AND `is_active` = 1
           ORDER BY `group_key`, `position`',
            $this->app->table('reference_options')
        ), [$instrumentId]) as $row) {
            $grouped[$row['group_key']][] = $row['value'];
        }

        return $grouped;
    }
}
