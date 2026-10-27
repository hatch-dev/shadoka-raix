<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

use Platform\Core\AppManifest;

/**
 * The page's downloads, server side: exportCSV(), exportJSON(), the
 * identified (restricted) pair, and exportCodebook() — same columns, same
 * envelope, same file names, same cell quoting.
 *
 * The default exports are pseudonymized, as on the page: no names, contact
 * details or organization names; organizations appear as codes (O001 …) in
 * order of their first record. The identified exports exist only to return
 * scorecards, handle requests or delete records, and the console asks before
 * giving one.
 */
final class Exports
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;

    private Instrument $instrument;

    public function __construct(private readonly AppManifest $app)
    {
        $this->instrument = new Instrument($app);
    }

    /** The records an export covers: the include-synthetic toggle applied. @return list<array<string, mixed>> */
    public function records(bool $includeSynthetic): array
    {
        return array_values(array_filter(
            (new Records($this->app))->all(),
            static fn (array $r): bool => $includeSynthetic || !$r['synthetic']
        ));
    }

    /** 'human-edge-scorecard-research-2026-10-09.csv' */
    public static function filename(string $kind, string $extension): string
    {
        return 'human-edge-scorecard-' . $kind . '-' . gmdate('Y-m-d') . '.' . $extension;
    }

    /** csvCell(v): always quoted, quotes doubled, null and undefined empty. */
    private static function cell(mixed $value): string
    {
        return '"' . str_replace('"', '""', self::jsString($value)) . '"';
    }

    /** String(v) as JavaScript writes it. */
    public static function jsString(mixed $value): string
    {
        return match (true) {
            $value === null  => '',
            $value === true  => 'true',
            $value === false => 'false',
            is_int($value)   => (string) $value,
            is_float($value) => (string) json_encode($value),   // the shortest round-trip form, as JavaScript prints it
            default          => (string) $value,
        };
    }

    /** The per-skill and per-question columns both CSVs end with. @return list<string> */
    private function markHeaders(): array
    {
        $headers = array_column($this->instrument->dims(), 'code');

        foreach ($this->instrument->dims() as $dim) {
            foreach ($dim['qs'] as $i => $q) {
                $headers[] = $dim['code'] . '_' . ($i + 1);
            }
        }

        return $headers;
    }

    /** @return list<mixed> */
    private function markCells(array $r): array
    {
        $cells = [];
        foreach ($this->instrument->dims() as $dim) {
            $cells[] = $r['dims'][$dim['code']] ?? '';
        }
        foreach ($this->instrument->dims() as $dim) {
            foreach ($dim['qs'] as $i => $q) {
                $cells[] = $r['responses'][$dim['code']][$i] ?? '';
            }
        }

        return $cells;
    }

    /** exportCSV(): pseudonymized. */
    public function csv(array $recs): string
    {
        $code = Stats::orgCodes($recs);
        $hdr  = array_merge(['id', 'timestamp', 'year', 'synthetic', 'org_code', 'notice_version', 'organization_size', 'role', 'sector',
            'ai_hours_per_week', 'ai_education_hours_12m', 'years_in_role', 'level', 'mean'], $this->markHeaders());

        $rows = [$hdr];
        foreach ($recs as $r) {
            $org    = $r['org'];
            $rows[] = array_merge([
                $r['id'], $r['timestamp'], $r['year'], $r['synthetic'] ? 1 : 0, $code($r), $org['consent']['notice'] ?? '',
                $org['size'] ?? null, $org['role'] ?? null, $org['sector'] ?? null, $org['aiuse'] ?? '', $org['edu'] ?? '',
                ($org['tenure'] ?? '') ?: '', $r['level'] ?? '', $r['mean'] ?? '',
            ], $this->markCells($r));
        }

        return $this->lines($rows);
    }

    /** exportCSVIdentified(): names, contact details and organization names. */
    public function csvIdentified(array $recs): string
    {
        $hdr = array_merge(['id', 'timestamp', 'year', 'synthetic', 'organization', 'organization_size', 'respondent', 'email', 'phone',
            'consent_at', 'role', 'sector', 'ai_hours_per_week', 'ai_education_hours_12m', 'years_in_role', 'level', 'mean'], $this->markHeaders());

        $rows = [$hdr];
        foreach ($recs as $r) {
            $org    = $r['org'];
            $rows[] = array_merge([
                $r['id'], $r['timestamp'], $r['year'], $r['synthetic'] ? 1 : 0, $org['org'] ?? null, $org['size'] ?? null,
                ($r['userName'] ?? '') ?: ($org['name'] ?? null), ($r['userEmail'] ?? '') ?: (($org['email'] ?? '') ?: ''),
                ($r['userPhone'] ?? '') ?: (($org['phone'] ?? '') ?: ''), $org['consent']['at'] ?? '',
                $org['role'] ?? null, $org['sector'] ?? null, $org['aiuse'] ?? '', $org['edu'] ?? '', ($org['tenure'] ?? '') ?: '',
                $r['level'] ?? '', $r['mean'] ?? '',
            ], $this->markCells($r));
        }

        return $this->lines($rows);
    }

    /** @param list<list<mixed>> $rows */
    private function lines(array $rows): string
    {
        return implode("\n", array_map(static fn (array $row): string => implode(',', array_map([self::class, 'cell'], $row)), $rows));
    }

    /** The JSON exports' envelope, in the page's key order. @return array<string, mixed> */
    private function envelope(bool $includeSynthetic, int $n): array
    {
        return [
            'instrument'         => 'Human Edge Scorecard',
            'version'            => '1.0',
            'scale'              => 'five-level practice scale',
            'rule'               => 'level = band of the overall average of all answers',
            'exported'           => gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', ((int) floor(microtime(true) * 1000)) % 1000) . 'Z',
            'includes_synthetic' => $includeSynthetic,
            'n'                  => $n,
        ];
    }

    /** exportJSON(): pseudonymized, organizations as codes. */
    public function json(array $recs, bool $includeSynthetic): string
    {
        $code = Stats::orgCodes($recs);
        $out  = [];

        foreach ($recs as $r) {
            $o = Records::export($r);
            unset($o['userName'], $o['userEmail'], $o['userPhone'], $o['artifacts']);
            if (isset($o['org']) && is_array($o['org'])) {
                unset($o['org']['org'], $o['org']['name'], $o['org']['email'], $o['org']['phone']);
            }
            $o['org_code'] = $code($r);
            $out[] = $o;
        }

        return self::pretty($this->envelope($includeSynthetic, count($recs)) + ['pseudonymized' => true, 'records' => $out]);
    }

    /** exportJSONIdentified(): the whole records. */
    public function jsonIdentified(array $recs, bool $includeSynthetic): string
    {
        return self::pretty($this->envelope($includeSynthetic, count($recs)) + ['records' => array_map([Records::class, 'export'], $recs)]);
    }

    /** exportCodebook(). */
    public function codebook(): string
    {
        $rungs = $this->instrument->rungs();
        $edges = $this->instrument->bandEdges();
        $last  = count($rungs) - 1;

        $bands = [];
        foreach ($rungs as $i => $r) {
            $bands[$r['name']] = match (true) {
                $i === 0     => '<' . Stats::toFixed($edges[0], 2),
                $i === $last => '≥' . Stats::toFixed($edges[$i - 1], 2),
                default      => Stats::toFixed($edges[$i - 1], 2) . '–' . Stats::toFixed($edges[$i] - .01, 2),
            };
        }

        return self::pretty([
            'instrument' => 'Human Edge Scorecard',
            'version'    => '1.0',
            'scoring'    => [
                'scale'           => array_map(static fn (array $r): array => ['value' => $r['v'], 'name' => $r['name'], 'definition' => $r['desc']], $rungs),
                'dimension_score' => 'unweighted mean of three item marks',
                'level'           => 'band of the overall average of all answers',
                'bands'           => $bands,
                'administration'  => 'single research wave',
            ],
            'dimensions' => array_map(static fn (array $d): array => [
                'code' => $d['code'], 'name' => $d['name'], 'description' => $d['desc'], 'question_to_press' => $d['ask'], 'failing_signature' => $d['fail'],
                'items' => array_map(static fn (array $q, int $i): array => [
                    'n' => $i + 1, 'label' => $q['t'], 'question' => $q['q'], 'anchor_1' => $q['a1'], 'anchor_5' => $q['a5'],
                ], $d['qs'], array_keys($d['qs'])),
            ], $this->instrument->dims()),
            'identification' => [
                'required_fields' => ['full name', 'email', 'phone', 'organization', 'organization size'],
                'validation'      => 'format only: a two-part name, a parseable address on a non-disposable domain, 7 to 15 digits, a non-empty organization, a size band from the fixed list',
                'verified'        => false,
                'note'            => 'no external verification is performed; every identity field is self-asserted',
            ],
            'caveats' => ['self-reported and unverified', 'identity fields are self-asserted, not checked against any register', 'self-selected sample',
                'correlations withheld below n=' . (int) $this->app->config('scoring.correlation_min_n', 30), 'not empirically validated'],
        ]);
    }

    /** JSON.stringify(value, null, 2): two-space indent, slashes and Unicode as they are. */
    public static function pretty(mixed $value): string
    {
        $json = json_encode($value, self::JSON_FLAGS);

        return (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    }
}
