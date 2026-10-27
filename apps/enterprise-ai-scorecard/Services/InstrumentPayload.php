<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The published instrument, shaped for the browser.
 *
 * This is what replaces the hard-coded DIMS / RUNGS / SECTORS / SIZES / ROLES /
 * TENURES arrays in the reference single-file build. The front end no longer
 * carries the instrument, so publishing a new version changes the questionnaire
 * without a deploy.
 *
 * It lives here rather than inside the API controller because two callers need
 * exactly the same bytes: `/api/instrument`, and the assessment page, which
 * inlines it so the first dimension can render on first paint instead of after a
 * round trip. One builder means the page and the endpoint cannot drift.
 *
 * Deliberately excluded: floor_narrative. That text is part of a result, not part
 * of the questionnaire, and it is assembled server-side after scoring — so a
 * respondent cannot read the interpretation before answering.
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

        $dimensions = Database::select(sprintf(
            'SELECT `id`, `position`, `code`, `name`, `description`, `pressing_question`,
                    `summary_line`, `failure_line`, `guide_what`, `guide_why`, `guide_how`
               FROM `%s`
              WHERE `instrument_id` = ?
           ORDER BY `position`',
            $this->app->table('dimensions')
        ), [$instrumentId]);

        // Two queries for all questions rather than one per dimension.
        $itemsByDimension = [];
        if ($dimensions !== []) {
            $ids          = array_column($dimensions, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            foreach (Database::select(sprintf(
                'SELECT `id`, `dimension_id`, `position`, `label`, `question`,
                        `anchor_low_label`, `anchor_low_text`, `anchor_high_label`, `anchor_high_text`
                   FROM `%s`
                  WHERE `dimension_id` IN (%s)
               ORDER BY `dimension_id`, `position`',
                $this->app->table('items'),
                $placeholders
            ), $ids) as $item) {
                $itemsByDimension[(int) $item['dimension_id']][] = [
                    'id'       => (int) $item['id'],
                    'position' => (int) $item['position'],
                    'label'    => $item['label'],
                    'question' => $item['question'],
                    // The two written ends. Without these the number a
                    // respondent picks has no defined meaning on that question.
                    'low'      => ['label' => $item['anchor_low_label'],  'text' => $item['anchor_low_text']],
                    'high'     => ['label' => $item['anchor_high_label'], 'text' => $item['anchor_high_text']],
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
            'rungs'      => $rungs,
            'dimensions' => array_map(
                static function (array $d) use ($itemsByDimension): array {
                    return [
                        'id'                => (int) $d['id'],
                        'position'          => (int) $d['position'],
                        'code'              => $d['code'],
                        'name'              => $d['name'],
                        'description'       => $d['description'],
                        'pressing_question' => $d['pressing_question'],
                        'summary_line'      => $d['summary_line'],
                        'failure_line'      => $d['failure_line'],
                        /*
                         * The guide panels. `how` contains one anchor tag per
                         * dimension, pointing at the published 90-day plan it
                         * cites — that markup is part of the instrument's own
                         * text, seeded from the reference and editable only
                         * through the instrument, never respondent input. The
                         * assessment page is the one place in this app that
                         * assigns instrument text with innerHTML, and it does so
                         * only for these three fields.
                         */
                        'guide' => [
                            'what' => $d['guide_what'],
                            'why'  => $d['guide_why'],
                            'how'  => $d['guide_how'],
                        ],
                        'items' => $itemsByDimension[(int) $d['id']] ?? [],
                    ];
                },
                $dimensions
            ),
            'reference' => $this->referenceOptions($instrumentId),
            'rules'     => [
                // Quoted in the profile form's note about the optional figures,
                // so the page states the real threshold rather than a number
                // typed into a sentence and left to drift.
                'correlation_min_n' => $this->app->config('scoring.correlation_min_n', 30),
            ],
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
