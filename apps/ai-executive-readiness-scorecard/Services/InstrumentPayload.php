<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * The published instrument, shaped for the browser.
 *
 * This replaces the hard-coded DIMS / RUNGS / SECTORS / SIZES / ROLES / TENURES
 * arrays and the INSTRUCTIONS / COMPARISON_NOTE / DISCLAIMER constants in the
 * reference single-file build. The front end no longer carries the instrument,
 * so publishing a new version changes the questionnaire without a deploy.
 *
 * It lives here rather than inside the API controller because two callers need
 * exactly the same bytes: `/api/instrument`, and the assessment page, which
 * inlines it so the first step can render on first paint instead of after a
 * round trip. One builder means the page and the endpoint cannot drift.
 */
final class InstrumentPayload
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    /** @return array<string, mixed>|null null when nothing is published */
    public function build(): ?array
    {
        $data = new InstrumentData($this->app);

        try {
            $instrument = $data->published();
        } catch (RuntimeException) {
            return null;
        }

        $instrumentId = (int) $instrument['id'];

        return [
            'instrument' => [
                'version'      => $instrument['version'],
                'name'         => $instrument['name'],
                'subtitle'     => $instrument['subtitle'],
                'scale'        => $instrument['scale'],
                'scoring_rule' => $instrument['scoring_rule'],
                'rule_text'    => $instrument['rule_text'],
            ],

            // The reference's three sentences, from the instrument row.
            'text' => [
                'instructions'    => $instrument['instructions'],
                'comparison_note' => $instrument['comparison_note'],
                'disclaimer'      => $instrument['disclaimer'],
            ],

            // The two ratings every question collects, and what each is called.
            'scopes' => Scopes::all(),

            'rungs' => array_map(
                static fn (array $r): array => [
                    'value'       => (int) $r['value'],
                    'name'        => $r['name'],
                    'description' => $r['description'],
                    'colour'      => $r['colour_hex'],
                    'band_min'    => $r['band_min'] === null ? null : (float) $r['band_min'],
                    'band_max'    => $r['band_max'] === null ? null : (float) $r['band_max'],
                ],
                $data->rungs($instrumentId)
            ),

            'dimensions' => array_map(
                static fn (array $d): array => [
                    'id'           => (int) $d['id'],
                    'position'     => (int) $d['position'],
                    'code'         => $d['code'],
                    'name'         => $d['name'],
                    'description'  => $d['description'],
                    'summary_line' => $d['summary_line'],
                    'guide'        => [
                        'what' => $d['guide_what'],
                        'why'  => $d['guide_why'],
                        'how'  => $d['guide_how'],
                    ],
                    'items' => array_map(
                        static fn (array $item): array => [
                            'id'       => (int) $item['id'],
                            'position' => (int) $item['position'],
                            'label'    => $item['label'],
                            'question' => $item['question'],
                            // The short words beside 1 and 5, shared by both
                            // ratings…
                            'low_label'  => $item['anchor_low_label'],
                            'high_label' => $item['anchor_high_label'],
                            // …and what a 1 and a 5 MEAN, which differs between
                            // rating yourself and rating the leaders.
                            'anchors' => [
                                'self' => [
                                    'low'  => $item['self_low_text'],
                                    'high' => $item['self_high_text'],
                                ],
                                'leadership' => [
                                    'low'  => $item['leadership_low_text'],
                                    'high' => $item['leadership_high_text'],
                                ],
                            ],
                        ],
                        $d['items']
                    ),
                ],
                $data->dimensions($instrumentId)
            ),

            'reference' => $this->referenceOptions($instrumentId),
            'rules'     => [
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
