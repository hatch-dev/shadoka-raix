<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Services\Export;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The codebook: what the instrument is, how it scores, and what its numbers
 * may not be used to claim.
 *
 * Generated entirely from the published instrument row. That is the fix for two
 * defects the legacy codebook carried:
 *
 *  1. It was hard-coded, and said `instrument: "RAI-X Responsible AI Scorecard",
 *     version: "10.0"` while exporting the seven DIRECTOR competencies — the
 *     name of a different, organisation-level instrument stamped on this one's
 *     content. Anyone citing that file would have cited the wrong instrument.
 *
 *  2. It documented a `board_coverage` rule — "a competency is covered when two
 *     or more directors score at or above practiced" — that nothing anywhere
 *     computed. A researcher reading the codebook would go looking for a field
 *     that did not exist.
 *
 * So: nothing here is written by hand that the database can state, and no rule
 * is documented unless it is implemented. If board coverage is added later, it
 * gets a line here at the same time and not before.
 */
final class CodebookExporter
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    public function filename(): string
    {
        $instrument = $this->instrument();

        return sprintf('raix-codebook-v%s.json', $instrument['version'] ?? 'unknown');
    }

    public function json(): string
    {
        $instrument = $this->instrument();

        if ($instrument === []) {
            return json_encode(['error' => 'No published instrument.'], JSON_PRETTY_PRINT);
        }

        $instrumentId = (int) $instrument['id'];
        $rungs        = $this->rungs($instrumentId);
        $competencies = $this->competencies($instrumentId);
        $itemCount    = array_sum(array_map(static fn (array $c): int => count($c['items']), $competencies));

        return json_encode([
            // Name and version come from the row, so they cannot disagree with
            // the content underneath them.
            'instrument'   => $instrument['name'],
            'version'      => $instrument['version'],
            'status'       => $instrument['status'],
            'published_at' => $instrument['published_at'],
            'generated_at' => date('c'),
            'owner'        => config('app.owner'),

            'structure' => [
                'competencies'       => count($competencies),
                'items'              => $itemCount,
                'items_per_competency' => count($competencies) > 0
                    ? (int) ($itemCount / count($competencies))
                    : 0,
                'scale_points'       => count($rungs),
            ],

            'scale' => array_map(
                static fn (array $r): array => [
                    'value'      => (int) $r['value'],
                    'name'       => $r['name'],
                    'definition' => $r['description'],
                    'band'       => [
                        'min' => $r['band_min'] === null ? null : (float) $r['band_min'],
                        'max' => $r['band_max'] === null ? null : (float) $r['band_max'],
                    ],
                ],
                $rungs
            ),

            'scoring' => [
                'competency_score' => 'unweighted mean of the competency\'s answered items',
                'director_level'   => $instrument['rule_text'],
                'rule_key'         => $instrument['scoring_rule'],
                'band_bounds'      => 'inclusive below, exclusive above: a score exactly on a '
                                    . 'cut point falls in the higher band',
                'unscored'         => 'a competency with no answered items is unscored; it is '
                                    . 'excluded from both the mean and the floor, and cannot set the level',
                'ties'             => 'when two competencies tie for weakest, the earlier one in '
                                    . 'instrument order is reported as the floor',
                'evidence_field'   => 'optional free text inviting the director to note when and '
                                    . 'where the act happened; never enforced and never verified',
                'remit_rule'       => 'a director scores every competency; committee remit is '
                                    . 'recorded as context, never as an exemption',
                'cadence'          => [
                    'full'            => 'annual, alongside the board evaluation',
                    'below_practiced' => 'every ' . (int) $this->app->config('scoring.cadence.below_practiced', 3)
                                       . ' months for any competency below '
                                       . $this->app->config('scoring.practiced_threshold', 2.75),
                    'on_incident'     => 'immediately after any material AI incident at the company',
                ],
            ],

            'competencies' => array_map(
                static fn (array $c): array => [
                    'position'           => (int) $c['position'],
                    'code'               => $c['code'],
                    'name'               => $c['name'],
                    'description'        => $c['description'],
                    'question_to_press'  => $c['board_question'],
                    'failing_signature'  => $c['failure_line'],
                    'items'              => array_map(
                        static fn (array $i): array => [
                            'position'  => (int) $i['position'],
                            'id'        => $c['code'] . '_' . $i['position'],
                            'statement' => $i['statement'],
                        ],
                        $c['items']
                    ),
                ],
                $competencies
            ),

            'caveats' => [
                'Scores are self-reported by people accustomed to being evaluated, '
                . 'and the sample is self-selected. Treat the ordering of the '
                . 'competencies as informative and the absolute levels as likely inflated.',
                'Scores describe a director\'s conduct. They are not a measure of '
                . 'competence in law, nor of fiduciary standing.',
                'Correlations are withheld below n = '
                . (int) $this->app->config('scoring.correlation_min_n', 30)
                . ', and are reported as associations rather than effects.',
                'Synthetic demo records are flagged in every export and are excluded '
                . 'from correlations unconditionally.',
                'The instrument has not been empirically validated.',
            ],

            'notes' => [
                'This codebook is generated from the published instrument row, so it '
                . 'cannot describe wording other than the wording actually in use.',
                'A published instrument version is immutable. Changing an item means '
                . 'publishing a new version; submissions stay pinned to the version '
                . 'they were taken under.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function instrument(): array
    {
        return Database::selectOne(sprintf(
            'SELECT * FROM `%s` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
            $this->app->table('instruments')
        ), ['published']) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function rungs(int $instrumentId): array
    {
        return Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`',
            $this->app->table('rungs')
        ), [$instrumentId]);
    }

    /** @return list<array<string, mixed>> */
    private function competencies(int $instrumentId): array
    {
        $competencies = Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->app->table('competencies')
        ), [$instrumentId]);

        foreach ($competencies as $index => $competency) {
            $competencies[$index]['items'] = Database::select(sprintf(
                'SELECT `position`, `statement` FROM `%s` WHERE `competency_id` = ? ORDER BY `position`',
                $this->app->table('items')
            ), [$competency['id']]);
        }

        return $competencies;
    }
}
