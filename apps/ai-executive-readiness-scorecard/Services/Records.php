<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * Completed questionnaires, as records in the reference's own shape.
 *
 * The reference computed every admin screen in the browser from an array of
 * records: `{id, timestamp, year, org:{…}, responses:{self, leadership},
 * scores:{self, leadership}, difference, synthetic, userName, …}`. Every figure
 * on its Analytics, Research data, console and PDFs is a function of that
 * array. Porting those screens faithfully is far simpler, and far less likely to
 * drift, if they are functions of the same array here — so this class builds it
 * from the database, once per request, and the Stats class reproduces the
 * reference's aggregation functions over it.
 *
 * Scores are recomputed from the raw ratings through PairedScoring rather than
 * read from the stored summary columns. The stored columns are rounded to four
 * places for querying; the reference aggregates unrounded values, and a pooled
 * mean of rounded means can differ from it in the last displayed digit.
 *
 * Internal fields a screen needs but the reference record did not carry — the
 * row id for links, the token, the organisation id — are under keys starting
 * with an underscore, and export() strips them.
 */
final class Records
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $cache = [];

    public function __construct(private readonly AppManifest $app)
    {
    }

    /**
     * Every completed questionnaire, oldest first (the reference's storage
     * order: records were appended as they were saved).
     *
     * @return list<array<string, mixed>>
     */
    public function all(bool $includeSynthetic = true): array
    {
        $key = $this->app->slug . ':' . ($includeSynthetic ? 'all' : 'real');

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $rows = Database::select(sprintf(
            'SELECT * FROM `%s`
              WHERE `status` = \'complete\'%s
           ORDER BY `submitted_at`, `id`',
            $this->app->table('submissions'),
            $includeSynthetic ? '' : ' AND `is_synthetic` = 0'
        ));

        return self::$cache[$key] = $this->build($rows);
    }

    /** One completed questionnaire, or null. @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ? AND `status` = \'complete\'', $this->app->table('submissions')),
            [$id]
        );

        return $row === null ? null : ($this->build([$row])[0] ?? null);
    }

    /** One submission row, whatever its status, as a record. @return array<string, mixed> */
    public function fromRow(array $row): array
    {
        return $this->build([$row])[0];
    }

    /** Forget what this request has read. Tests call it after writing. */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * A record as the reference's JSON export wrote it: internal keys removed.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function export(array $record): array
    {
        $record = array_filter(
            $record,
            static fn (string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY
        );

        /*
         * The reference built each dimension's ratings as an object indexed by
         * question (`responses.self.JDG = {0: 3, 1: 2, 2: 4}`), and so wrote them
         * to JSON as {"0":3,…}, not as an array. The codebook documents that
         * shape ("zero-based item indexes"), so the export keeps it.
         */
        foreach ($record['responses'] as $scope => $byCode) {
            foreach ($byCode as $code => $marks) {
                $record['responses'][$scope][$code] = (object) $marks;
            }
        }

        foreach ($record['difference']['items'] as $code => $values) {
            $record['difference']['items'][$code] = (object) $values;
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

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        // All ratings for these rows in one query. The ids are integers read
        // from this app's own table, so nothing from a request reaches the SQL.
        $ratings = [];
        foreach (Database::select(sprintf(
            'SELECT `submission_id`, `item_id`, `scope`, `mark` FROM `%s` WHERE `submission_id` IN (%s)',
            $this->app->table('submission_responses'),
            implode(',', $ids)
        )) as $rating) {
            $ratings[(int) $rating['submission_id']][] = $rating;
        }

        $data        = new InstrumentData($this->app);
        $unspecified = (string) $this->app->config('result.unspecified_label', 'Not specified');
        $records     = [];

        foreach ($rows as $row) {
            $instrumentId = (int) $row['instrument_id'];
            $index        = $data->itemIndex($instrumentId);
            $scoring      = $data->scoring($instrumentId);

            $responses = ['self' => [], 'leadership' => []];
            foreach ($scoring->dimensions() as $dimension) {
                $responses['self'][$dimension['code']]       = [];
                $responses['leadership'][$dimension['code']] = [];
            }

            foreach ($ratings[(int) $row['id']] ?? [] as $rating) {
                if (!isset($index[(int) $rating['item_id']])) {
                    continue;
                }

                [$code, $i] = $index[(int) $rating['item_id']];
                $responses[(string) $rating['scope']][$code][$i] = (int) $rating['mark'];
            }

            foreach ($responses as &$byCode) {
                foreach ($byCode as &$marks) {
                    ksort($marks);
                }
                unset($marks);
            }
            unset($byCode);

            $scored = $scoring->scorePair($responses);

            // The reference stores a level's NAME on a record, not the rung.
            foreach ($scored['scores'] as &$profile) {
                $profile['level'] = $profile['level']['name'] ?? null;
            }
            unset($profile);

            $synthetic = (bool) $row['is_synthetic'];
            $timestamp = self::iso((string) ($row['submitted_at'] ?? $row['created_at']));

            $org = [
                'org'     => (string) $row['organisation_name'],
                'name'    => (string) $row['respondent_name'],
                'email'   => (string) $row['respondent_email'],
                'phone'   => (string) $row['respondent_phone'],
                'role'    => $row['role'] === null ? '' : (string) $row['role'],
                'sector'  => $row['sector'] === null || $row['sector'] === '' ? $unspecified : (string) $row['sector'],
                'size'    => (string) $row['organisation_size'],
                'year'    => (int) $row['assessment_year'],
                'systems' => $row['systems_inventory'] === null ? null : (int) $row['systems_inventory'],
                'tenure'  => $row['tenure'] === null || $row['tenure'] === '' ? null : (string) $row['tenure'],
                'agenda'  => $row['agenda_meetings'] === null ? null : (int) $row['agenda_meetings'],
                'edu'     => $row['education_hours'] === null ? null : 0 + (float) $row['education_hours'],
            ];

            // A real questionnaire carries its consent first, as the reference's
            // profile did; a generated one has none — nobody consented to anything.
            if ($synthetic) {
                // synthRecord() wrote tenure last: {…, systems, agenda, edu, tenure}.
                $tenure = $org['tenure'];
                unset($org['tenure']);
                $org['tenure'] = $tenure;
            } else {
                $org = ['consent' => $row['consent_agreed_at'] === null ? null : [
                    'agreed' => true,
                    'at'     => self::iso((string) $row['consent_agreed_at']),
                    'terms'  => (string) $row['consent_terms_url'],
                ]] + $org;
            }

            $record = [
                'instrument' => 'ai-executive-readiness',
                'version'    => (string) $data->instrument($instrumentId)['version'],
                'id'         => 'exr-' . $row['id'],
                'timestamp'  => $timestamp,
                'year'       => (int) $row['assessment_year'],
                'org'        => $org,
                'responses'  => $responses,
                'scores'     => $scored['scores'],
                'difference' => $scored['difference'],
                'synthetic'  => $synthetic,
                'userName'   => (string) $row['respondent_name'],
                'userEmail'  => (string) $row['respondent_email'],
                'userPhone'  => (string) $row['respondent_phone'],
            ];

            if ($synthetic && $row['archetype'] !== null) {
                $record['archetype'] = (string) $row['archetype'];
            }

            $record['_id']              = (int) $row['id'];
            $record['_token']           = (string) $row['public_token'];
            $record['_organisation_id'] = $row['organisation_id'] === null ? null : (int) $row['organisation_id'];
            $record['_instrument_id']   = $instrumentId;
            $record['_sector_given']    = !($row['sector'] === null || $row['sector'] === '');

            $records[] = $record;
        }

        return $records;
    }

    /**
     * A stored DATETIME as the reference's `toISOString()`: UTC, milliseconds, Z.
     *
     * The column holds app-timezone wall time (the platform aligns MySQL to it),
     * so it is read in that zone and converted.
     */
    public static function iso(string $datetime): string
    {
        $time = strtotime($datetime);

        return $time === false ? '' : gmdate('Y-m-d\TH:i:s.000\Z', $time);
    }
}
