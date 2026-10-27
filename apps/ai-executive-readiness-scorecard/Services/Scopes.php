<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services;

/**
 * The two ratings every question collects, and every name each goes by.
 *
 * The reference names a scope differently depending on who is reading:
 * "You" beside the buttons and on a respondent's own results, "Respondents’
 * self-ratings" wherever several questionnaires are pooled, "Self-ratings" in a
 * filter or a table header, "Self" in a PDF bar label. Those are four distinct
 * strings in its source (SCOPE_LABEL, GROUP_LABEL, and two inline literals), and
 * they are collected here so a view never has to guess which one it wants.
 *
 * The colours are the reference's SCOPE_COLOR: gold for self, blue for the
 * leaders, on every chart, bar and PDF. instrument_integrity_test.php checks the
 * labels and colours against the extracted instrument.
 */
final class Scopes
{
    public const KEYS = ['self', 'leadership'];

    private const DEFINITIONS = [
        'self' => [
            'key'         => 'self',
            // SCOPE_LABEL — beside the buttons, and on one respondent's results.
            'label'       => 'You',
            // GROUP_LABEL — wherever questionnaires are pooled.
            'group_label' => 'Respondents’ self-ratings',
            // The analytics filter, table headers and chart series.
            'set_label'   => 'Self-ratings',
            // The PDF's bar labels and the terse table columns.
            'short_label' => 'Self',
            // The results card heading.
            'card_title'  => 'Your executive readiness',
            // The group PDF's card heading.
            'group_card'  => 'Mean of respondents’ self-ratings',
            'colour'      => '#E8933F',
        ],
        'leadership' => [
            'key'         => 'leadership',
            'label'       => 'Your organization’s leaders',
            'group_label' => 'Respondents’ assessments of the organization’s leaders',
            'set_label'   => 'Ratings of leaders',
            'short_label' => 'Leaders',
            'card_title'  => 'Your assessment of your organization’s leaders',
            'group_card'  => 'Mean of respondents’ ratings of leaders',
            'colour'      => '#5A9FDA',
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<array<string, string>> both scopes, in questionnaire order */
    public static function all(): array
    {
        return array_values(self::DEFINITIONS);
    }

    /** @return array<string, string> */
    public static function get(string $key): array
    {
        return self::DEFINITIONS[$key] ?? self::DEFINITIONS['self'];
    }

    public static function valid(mixed $key): bool
    {
        return is_string($key) && isset(self::DEFINITIONS[$key]);
    }
}
