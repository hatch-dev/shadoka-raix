<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The published instrument, shaped for the browser.
 *
 * This is what replaces the hard-coded DIMS / RUNGS / FLOOR_TEXT arrays in the
 * legacy single-file build. The front end no longer carries the instrument.
 *
 * It lives here rather than inside the API controller because two callers need
 * exactly the same bytes: `/api/instrument`, and the assessment page, which
 * inlines it so the questionnaire can render on first paint instead of after a
 * round trip. One builder means the page and the endpoint cannot drift.
 *
 * Deliberately excluded: floor_narrative. That text is part of a director's
 * result, not part of the questionnaire, and it is assembled server-side after
 * scoring — so a respondent cannot read the interpretation before answering.
 */
final class InstrumentPayload
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    /** @return array<string, mixed>|null null when nothing is published */
    public function build(): ?array
    {
        $instrument = Database::selectOne(sprintf(
            'SELECT `id`, `version`, `name`, `subtitle`, `scale`, `scoring_rule`, `rule_text`
               FROM `%s`
              WHERE `status` = ?
           ORDER BY `published_at` DESC
              LIMIT 1',
            $this->app->table('instruments')
        ), ['published']);

        if ($instrument === null) {
            return null;
        }

        $instrumentId = (int) $instrument['id'];

        $rungs = array_map(
            static fn (array $r): array => [
                'value'       => (int) $r['value'],
                'name'        => $r['name'],
                'description' => $r['description'],
                'colour'      => $r['colour_hex'],
                'band_min'    => $r['band_min'] === null ? null : (float) $r['band_min'],
                'band_max'    => $r['band_max'] === null ? null : (float) $r['band_max'],
            ],
            Database::select(
                sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`', $this->app->table('rungs')),
                [$instrumentId]
            )
        );

        $competencies = Database::select(sprintf(
            'SELECT `id`, `position`, `code`, `name`, `description`, `board_question`, `failure_line`,
                    `guide_what`, `guide_why`, `guide_how`, `guide_example`
               FROM `%s`
              WHERE `instrument_id` = ?
           ORDER BY `position`',
            $this->app->table('competencies')
        ), [$instrumentId]);

        // Two queries for all items rather than one per competency.
        $itemsByCompetency = [];
        if ($competencies !== []) {
            $ids          = array_column($competencies, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            foreach (Database::select(sprintf(
                'SELECT `id`, `competency_id`, `position`, `statement`
                   FROM `%s`
                  WHERE `competency_id` IN (%s)
               ORDER BY `competency_id`, `position`',
                $this->app->table('items'),
                $placeholders
            ), $ids) as $item) {
                $itemsByCompetency[(int) $item['competency_id']][] = [
                    'id'        => (int) $item['id'],
                    'position'  => (int) $item['position'],
                    'statement' => $item['statement'],
                ];
            }
        }

        return [
            'instrument' => [
                'version'      => $instrument['version'],
                'name'         => $instrument['name'],
                'subtitle'     => $instrument['subtitle'],
                'scale'        => $instrument['scale'],
                'scoring_rule' => $instrument['scoring_rule'],
                'rule_text'    => $instrument['rule_text'],
            ],
            'rungs'        => $rungs,
            'competencies' => array_map(
                static function (array $c) use ($itemsByCompetency): array {
                    return [
                        'id'             => (int) $c['id'],
                        'position'       => (int) $c['position'],
                        'code'           => $c['code'],
                        'name'           => $c['name'],
                        'description'    => $c['description'],
                        'board_question' => $c['board_question'],
                        'failure_line'   => $c['failure_line'],
                        'guide'          => [
                            'what'    => $c['guide_what'],
                            'why'     => $c['guide_why'],
                            'how'     => $c['guide_how'],
                            'example' => $c['guide_example'],
                        ],
                        'items' => $itemsByCompetency[(int) $c['id']] ?? [],
                    ];
                },
                $competencies
            ),
            'reference' => $this->referenceOptions($instrumentId),
            'rules'     => [
                'practiced_threshold'  => $this->app->config('scoring.practiced_threshold', 2.75),
                'evidence_prompt_from' => $this->app->config('scoring.evidence_prompt_from', 3),
                // Quoted in the profile form's note about the optional figures,
                // so the page says the real threshold rather than a number
                // typed into a sentence and left to drift.
                'correlation_min_n'    => $this->app->config('scoring.correlation_min_n', 30),
            ],
            // The "Before you begin" gate. Wording from config/result.php.
            'consent' => [
                'terms_url'   => $this->app->config('result.consent.terms_url'),
                'privacy_url' => $this->app->config('result.consent.privacy_url'),
                'paragraphs'  => array_map(
                    static fn (string $p): string => strtr($p, ['{owner}' => (string) config('app.owner')]),
                    (array) $this->app->config('result.consent.paragraphs', [])
                ),
                'checkbox' => $this->app->config('result.consent.checkbox'),
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function referenceOptions(int $instrumentId): array
    {
        $grouped = [];

        foreach (Database::select(sprintf(
            'SELECT `group_key`, `value`
               FROM `%s`
              WHERE `instrument_id` = ? AND `is_active` = 1
           ORDER BY `group_key`, `position`',
            $this->app->table('reference_options')
        ), [$instrumentId]) as $row) {
            $grouped[$row['group_key']][] = $row['value'];
        }

        return $grouped;
    }
}
