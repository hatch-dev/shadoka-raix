<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The console's scorecards, as records in the page's own shape.
 *
 * The page computes every admin screen in the browser from an array of
 * records: `{id, timestamp, year, org:{…}, dims, mean, level, responses,
 * artifacts, synthetic, userName, userEmail, userPhone}`. Every figure on its
 * Analytics, Research data, Console and PDFs is a function of that array.
 * Porting those screens faithfully is far simpler, and far less likely to
 * drift, if they are functions of the same array here — so this class builds
 * it from the relational he_submissions family, once per request, and Stats
 * reproduces the page's functions over it.
 *
 * The scores are the console's own (Services/Scoring/Scorer, from the raw
 * marks), never the ones the browser sent. The identity and profile fields are
 * the submission's columns.
 *
 * Internal fields a screen needs but the page's record did not carry — the
 * row id for links, the organisation id — are under keys starting with an
 * underscore, and export() strips them.
 */
final class Records
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $cache = [];

    public function __construct(private readonly AppManifest $app)
    {
    }

    /** Forget what this request has read. The mirror and tests call it after writing. */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Every scorecard, oldest first (the page's storage order).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $key = $this->app->slug;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $rows = Database::select(sprintf(
            "SELECT * FROM `%s` WHERE `status` = 'complete' ORDER BY `submitted_at`, `id`",
            $this->app->table('submissions')
        ));

        return self::$cache[$key] = $this->build($rows);
    }

    /** One scorecard by its submission id, or null. @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->all() as $record) {
            if ($record['_id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * A record as the page's JSON export writes it: internal keys removed, and
     * each skill's marks an object keyed "0".."2", as the page builds them.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function export(array $record): array
    {
        $record = array_filter($record, static fn ($key): bool => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);

        foreach (['responses', 'artifacts'] as $field) {
            if (isset($record[$field]) && is_array($record[$field])) {
                $record[$field] = (object) array_map(static fn ($marks): object => (object) $marks, $record[$field]);
            }
        }

        if (isset($record['dims']) && is_array($record['dims'])) {
            $record['dims'] = (object) $record['dims'];
        }

        return $record;
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param list<array<string, mixed>> $rows submission rows
     * @return list<array<string, mixed>>
     */
    private function build(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $instrument = new Instrument($this->app);
        $dims       = $instrument->dims();
        $index      = $instrument->itemIndex();
        $codeOf     = [];
        foreach ($dims as $dim) {
            $codeOf[$dim['_id']] = $dim['code'];
        }
        $rungName = [];
        foreach ($instrument->rungs() as $rung) {
            $rungName[$rung['v']] = $rung['name'];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in  = implode(',', $ids);   // integers read from this app's own table

        $marks = [];
        foreach (Database::select(sprintf(
            'SELECT `submission_id`, `item_id`, `mark` FROM `%s` WHERE `submission_id` IN (%s)',
            $this->app->table('submission_responses'),
            $in
        )) as $row) {
            $item = $index[(int) $row['item_id']] ?? null;
            if ($item !== null) {
                $marks[(int) $row['submission_id']][$item[0]][$item[1]] = (int) $row['mark'];
            }
        }

        $scores = [];
        foreach (Database::select(sprintf(
            'SELECT `submission_id`, `dimension_id`, `score` FROM `%s` WHERE `submission_id` IN (%s)',
            $this->app->table('submission_scores'),
            $in
        )) as $row) {
            $code = $codeOf[(int) $row['dimension_id']] ?? null;
            if ($code !== null) {
                $scores[(int) $row['submission_id']][$code] = (float) $row['score'];
            }
        }

        $number = static fn ($v) => $v === null ? null : (floor((float) $v) == (float) $v ? (int) $v : (float) $v);

        $records = [];

        foreach ($rows as $row) {
            $id        = (int) $row['id'];
            $synthetic = (int) $row['is_synthetic'] === 1;

            $recordDims = [];
            $responses  = [];
            $artifacts  = [];
            foreach ($dims as $dim) {
                $recordDims[$dim['code']] = $scores[$id][$dim['code']] ?? null;
                $byIndex = $marks[$id][$dim['code']] ?? [];
                ksort($byIndex);
                $responses[$dim['code']] = $byIndex;
                $artifacts[$dim['code']] = [];
            }

            $common = [
                'org'    => (string) ($row['organisation_name'] ?? ''),
                'name'   => (string) $row['respondent_name'],
                'email'  => (string) $row['respondent_email'],
                'phone'  => (string) ($row['respondent_phone'] ?? ''),
                'role'   => (string) ($row['role'] ?? ''),
                // The page writes "Not specified" for a blank sector.
                'sector' => $row['sector'] === null || $row['sector'] === '' ? 'Not specified' : (string) $row['sector'],
                'size'   => (string) ($row['organisation_size'] ?? ''),
                'year'   => (int) $row['assessment_year'],
            ];

            if ($synthetic) {
                // synthRecord(): {org, name, email, phone, role, sector, size, year, aiuse, edu, tenure}
                $org = $common + [
                    'aiuse'  => $number($row['ai_hours_per_week']),
                    'edu'    => $number($row['education_hours']),
                    'tenure' => (string) ($row['tenure'] ?? ''),
                ];
            } else {
                // The profile step: {consent, org, name, email, phone, role, sector, size, year, aiuse, tenure, edu}
                $org = ['consent' => $row['consent_agreed_at'] === null && $row['consent_notice'] === null ? null : [
                    'notice'    => (string) $row['consent_notice'],
                    'at'        => $row['consent_agreed_at'] === null ? null : self::iso((string) $row['consent_agreed_at']),
                    'scorecard' => true,
                    'research'  => (int) $row['consent_research'] === 1,
                    'terms'     => (string) $row['consent_terms_url'],
                ]] + $common + [
                    'aiuse'  => $number($row['ai_hours_per_week']),
                    'tenure' => $row['tenure'] === null || $row['tenure'] === '' ? null : (string) $row['tenure'],
                    'edu'    => $number($row['education_hours']),
                ];
            }

            $record = [
                'id'        => (string) ($row['record_id'] ?? ('he-' . $id)),
                'timestamp' => self::iso((string) ($row['submitted_at'] ?? $row['created_at'])),
                'year'      => (int) $row['assessment_year'],
                'org'       => $org,
                'dims'      => $recordDims,
                'mean'      => $row['mean_score'] === null ? null : (float) $row['mean_score'],
                'level'     => $row['level_value'] === null ? null : ($rungName[(int) $row['level_value']] ?? null),
                'responses' => $responses,
                'artifacts' => $artifacts,
                'synthetic' => $synthetic,
            ];

            if ($synthetic) {
                $record['archetype'] = $row['archetype'];
            }

            $record += [
                'userName'  => (string) $row['respondent_name'],
                'userEmail' => (string) $row['respondent_email'],
                'userPhone' => (string) ($row['respondent_phone'] ?? ''),
            ];

            $record['_id']              = $id;
            $record['_organisation_id'] = $row['organisation_id'] === null ? null : (int) $row['organisation_id'];
            $record['_seed_run_id']     = $row['seed_run_id'] === null ? null : (int) $row['seed_run_id'];

            $records[] = $record;
        }

        return $records;
    }

    /**
     * A stored DATETIME as the page's `toISOString()`: UTC, milliseconds, Z.
     * The column holds app-timezone wall time, so it is read in that zone.
     */
    public static function iso(string $datetime): string
    {
        $time = strtotime($datetime);

        return $time === false ? '' : gmdate('Y-m-d\TH:i:s.000\Z', $time);
    }
}
