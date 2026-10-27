<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services\Export;

use Platform\Assessment\Export\CsvWriter;
use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The dataset, as CSV or JSON.
 *
 * Both formats carry every one of the thirty item-level marks, not just the six
 * dimension scores: a dimension mean of 3.00 can be five 3s or a 1 and four 3.5s,
 * and a research export that cannot tell those apart is not a research export.
 *
 * Synthetic records are excluded unless explicitly asked for, and whichever way
 * that went is stated inside the file — so a spreadsheet that has been emailed
 * around still says what it contains.
 *
 * One thing the CSV carries that the sibling app's does not: the respondent's
 * name, email address and phone number. They are required fields on this
 * instrument and the console exists partly to reach the person who filled it in.
 * That makes every export of this dataset a file of contactable individuals, and
 * it is why `es.export` is a capability of its own rather than part of `es.view`.
 */
final class DatasetExporter
{
    public function __construct(
        private readonly AppManifest $app,
        private readonly bool $includeSynthetic = false,
    ) {
    }

    /** Filename stem, shared by both formats — the reference's, exactly. */
    public function filename(string $extension): string
    {
        return sprintf('enterprise-scorecard-%s.%s', date('Y-m-d'), $extension);
    }

    /* ------------------------------------------------------------------ */

    public function csv(): string
    {
        $instrument = $this->instrument();
        $dimensions = $this->dimensions((int) $instrument['id']);

        $csv = new CsvWriter();

        /*
         * The reference's seventeen columns, in its order and under its names,
         * then a column per dimension and a column per question: 53 in all.
         *
         * `floor` and `floor_dimension` sit next to `level` and `mean` as the
         * reference had them, and `level` is banded from `mean` — not from
         * `floor`. A reader who assumed otherwise would draw the opposite
         * conclusion from every row.
         */
        $headers = [
            'id', 'timestamp', 'year', 'synthetic',
            'organization', 'organization_size', 'respondent', 'email', 'phone',
            'consent_at', 'role', 'sector', 'ai_systems_on_inventory',
            'level', 'floor', 'floor_dimension', 'mean',
        ];

        foreach ($dimensions as $dimension) {
            $headers[] = $dimension['code'];
        }

        // One column per question: CODE_1 … CODE_5.
        foreach ($dimensions as $dimension) {
            foreach ($dimension['items'] as $item) {
                $headers[] = $dimension['code'] . '_' . $item['position'];
            }
        }

        $csv->headers($headers);

        foreach ($this->records((int) $instrument['id']) as $record) {
            $row = [
                $record['id'],
                $record['submitted_at'],
                $record['assessment_year'],
                (int) $record['is_synthetic'],
                $record['organisation_name'],
                $record['organisation_size'],
                $record['respondent_name'],
                $record['respondent_email'],
                $record['respondent_phone'],
                $record['consent_agreed_at'],
                $record['role'],
                $record['sector'],
                $record['systems_inventory'],
                $record['level_name'],
                $record['floor_score'],
                $record['floor_code'],
                $record['mean_score'],
            ];

            foreach ($dimensions as $dimension) {
                $row[] = $record['scores'][$dimension['code']] ?? null;
            }

            foreach ($dimensions as $dimension) {
                foreach ($dimension['items'] as $item) {
                    $row[] = $record['marks'][(int) $item['id']] ?? null;
                }
            }

            $csv->row($row);
        }

        return $csv->toString();
    }

    /* ------------------------------------------------------------------ */

    public function json(): string
    {
        $instrument = $this->instrument();
        $dimensions = $this->dimensions((int) $instrument['id']);
        $records    = $this->records((int) $instrument['id']);

        $payload = [
            'instrument' => $instrument['name'],
            'version'    => $instrument['version'],
            'scale'      => $instrument['scale'],
            'rule'       => $instrument['rule_text'],
            'exported'   => date('c'),

            /*
             * Stated twice on purpose: as a flag a script can read, and as the
             * first caveat a person reads. A file that has been emailed on has
             * lost the screen that said which it was.
             */
            'includes_synthetic' => $this->includeSynthetic,
            'caveats'            => $this->caveats(),

            'n'       => count($records),
            'records' => array_map(
                static function (array $record) use ($dimensions): array {
                    $out = [
                        'id'            => (int) $record['id'],
                        'timestamp'     => $record['submitted_at'],
                        'year'          => (int) $record['assessment_year'],
                        'synthetic'     => (bool) $record['is_synthetic'],
                        'archetype'     => $record['archetype'],
                        'organization'  => [
                            'name'              => $record['organisation_name'],
                            'size'              => $record['organisation_size'],
                            'sector'            => $record['sector'],
                            'systems_inventory' => $record['systems_inventory'] === null
                                ? null : (int) $record['systems_inventory'],
                        ],
                        'respondent' => [
                            'name'   => $record['respondent_name'],
                            'email'  => $record['respondent_email'],
                            'phone'  => $record['respondent_phone'],
                            'role'   => $record['role'],
                            'tenure' => $record['tenure'],
                        ],
                        'reported' => [
                            'agenda_meetings' => $record['agenda_meetings'] === null
                                ? null : (int) $record['agenda_meetings'],
                            'education_hours' => $record['education_hours'] === null
                                ? null : (float) $record['education_hours'],
                        ],
                        'consent' => $record['consent_agreed_at'] === null ? null : [
                            'agreed_at' => $record['consent_agreed_at'],
                            'terms'     => $record['consent_terms_url'],
                        ],
                        'confirmed_at' => $record['confirmed_at'],
                        'level'        => $record['level_name'],
                        'level_value'  => $record['level_value'] === null ? null : (int) $record['level_value'],
                        'mean'         => $record['mean_score'] === null ? null : (float) $record['mean_score'],
                        'floor'        => $record['floor_score'] === null ? null : (float) $record['floor_score'],
                        'floor_dimension' => $record['floor_code'],
                        'dimensions'      => [],
                        'responses'       => [],
                    ];

                    foreach ($dimensions as $dimension) {
                        $score = $record['scores'][$dimension['code']] ?? null;
                        $out['dimensions'][$dimension['code']] = $score === null ? null : (float) $score;

                        $marks = [];
                        foreach ($dimension['items'] as $item) {
                            $marks[$item['position']] = $record['marks'][(int) $item['id']] ?? null;
                        }
                        $out['responses'][$dimension['code']] = $marks;
                    }

                    return $out;
                },
                $records
            ),
        ];

        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * What this dataset may not be used to claim.
     *
     * Shared by the JSON export and the printed research report, so the two
     * cannot carry different lists of the same limitations.
     *
     * @return list<string>
     */
    public function caveats(): array
    {
        $caveats = [
            'self-reported by an executive about their own organization, and unverified',
            'identity fields are self-asserted, not checked against any register',
            'self-selected sample',
            'the level is the band of the overall average; the floor is the weakest dimension '
                . 'and is reported, not enforced',
            'correlations are withheld below n = '
                . (int) $this->app->config('scoring.correlation_min_n', 30),
            'not empirically validated',
        ];

        if ($this->includeSynthetic) {
            array_unshift(
                $caveats,
                'THIS EXPORT INCLUDES SYNTHETIC DEMO RECORDS. They are flagged per row by '
                . '"synthetic": true and carry an "archetype"'
            );
        }

        return $caveats;
    }

    public function count(): int
    {
        return (int) Database::scalar(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE `status` = \'complete\'%s',
            $this->app->table('submissions'),
            $this->includeSynthetic ? '' : ' AND `is_synthetic` = 0'
        ));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Every record in scope, with its dimension scores and its thirty marks
     * attached.
     *
     * Three queries in total rather than three per record: the scores and the
     * marks are fetched in one pass each and grafted on. A 500-record export
     * otherwise costs a thousand round trips.
     *
     * @return list<array<string, mixed>>
     */
    private function records(int $instrumentId, ?string $where = null, array $params = []): array
    {
        if ($where === null) {
            $where = "s.`status` = 'complete'";

            if (!$this->includeSynthetic) {
                $where .= ' AND s.`is_synthetic` = 0';
            }
        }

        $records = Database::select(sprintf(
            'SELECT s.*, d.`code` AS floor_code, r.`name` AS level_name
               FROM `%s` s
          LEFT JOIN `%s` d ON d.`id` = s.`floor_dimension_id`
          LEFT JOIN `%s` r ON r.`instrument_id` = s.`instrument_id` AND r.`value` = s.`level_value`
              WHERE %s
           ORDER BY s.`organisation_name`, s.`assessment_year`, s.`id`',
            $this->app->table('submissions'),
            $this->app->table('dimensions'),
            $this->app->table('rungs'),
            $where
        ), $params);

        if ($records === []) {
            return [];
        }

        $byId = [];
        foreach ($records as $index => $record) {
            $byId[(int) $record['id']] = $index;
            $records[$index]['scores'] = [];
            $records[$index]['marks']  = [];
        }

        $ids          = array_keys($byId);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        foreach (Database::select(sprintf(
            'SELECT ss.`submission_id`, d.`code`, ss.`score`
               FROM `%s` ss JOIN `%s` d ON d.`id` = ss.`dimension_id`
              WHERE ss.`submission_id` IN (%s)',
            $this->app->table('submission_scores'),
            $this->app->table('dimensions'),
            $placeholders
        ), $ids) as $row) {
            $records[$byId[(int) $row['submission_id']]]['scores'][$row['code']] = $row['score'];
        }

        foreach (Database::select(sprintf(
            'SELECT `submission_id`, `item_id`, `mark` FROM `%s` WHERE `submission_id` IN (%s)',
            $this->app->table('submission_responses'),
            $placeholders
        ), $ids) as $row) {
            $records[$byId[(int) $row['submission_id']]]['marks'][(int) $row['item_id']] = (int) $row['mark'];
        }

        return $records;
    }

    /** @return list<array<string, mixed>> dimensions with their items, in order */
    private function dimensions(int $instrumentId): array
    {
        $dimensions = Database::select(sprintf(
            'SELECT `id`, `code`, `name`, `position` FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->app->table('dimensions')
        ), [$instrumentId]);

        foreach ($dimensions as $index => $dimension) {
            $dimensions[$index]['items'] = Database::select(sprintf(
                'SELECT `id`, `position`, `label` FROM `%s` WHERE `dimension_id` = ? ORDER BY `position`',
                $this->app->table('items')
            ), [$dimension['id']]);
        }

        return $dimensions;
    }

    /**
     * Chosen submissions as page-shaped records, keyed by submission id.
     * RecordMirror uses it to give the console's demo rows a record each, so
     * the v32 page's own admin views show them too.
     *
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function pageRecords(AppManifest $app, string $where, array $params = []): array
    {
        $exporter    = new self($app, true);
        $instrument  = $exporter->instrument();
        $dimensions  = $exporter->dimensions((int) $instrument['id']);
        $unspecified = (string) $app->config('result.unspecified_label', 'Not specified');
        $out         = [];

        foreach ($exporter->records((int) $instrument['id'], $where, $params) as $record) {
            $out[(int) $record['id']] = self::pageRecord($record, $dimensions, $unspecified);
        }

        return $out;
    }

    /**
     * One record, shaped as the page's `buildRecord()` / `synthRecord()`.
     *
     * A real record's `org` is the whole profile the form produced (consent
     * first, then tenure, agenda and education last); a synthetic one's stops
     * at `systems`, and only a synthetic record carries `archetype`.
     * `responses` is keyed by question INDEX, "0" to "4", as
     * `A.responses[code][qi]` is. `artifacts` holds nothing in either: real
     * records `{}`, synthetic ones an empty object per dimension.
     *
     * @param list<array<string, mixed>> $dimensions
     * @return array<string, mixed>
     */
    private static function pageRecord(array $record, array $dimensions, string $unspecified): array
    {
        $synthetic = (bool) $record['is_synthetic'];
        $float     = static fn ($v): ?float => $v === null ? null : (float) $v;
        $int       = static fn ($v): ?int => $v === null ? null : (int) $v;

        $org = $synthetic ? [] : [
            'consent' => $record['consent_agreed_at'] === null ? null : [
                'at'        => self::iso($record['consent_agreed_at']),
                'scorecard' => true,
                'research'  => true,
                'terms'     => $record['consent_terms_url'],
            ],
        ];

        $org += [
            'org'     => $record['organisation_name'],
            'name'    => $record['respondent_name'],
            'email'   => $record['respondent_email'],
            'phone'   => $record['respondent_phone'],
            'role'    => $record['role'] ?? '',
            'sector'  => $record['sector'] ?? $unspecified,
            'size'    => $record['organisation_size'],
            'year'    => (int) $record['assessment_year'],
            'systems' => $int($record['systems_inventory']),
        ];

        if (!$synthetic) {
            $org += [
                'tenure' => $record['tenure'],
                'agenda' => $int($record['agenda_meetings']),
                'edu'    => $float($record['education_hours']),
            ];
        }

        $dims      = [];
        $responses = [];
        $artifacts = [];

        foreach ($dimensions as $dimension) {
            $code        = $dimension['code'];
            $dims[$code] = $float($record['scores'][$code] ?? null);

            $marks = [];
            foreach ($dimension['items'] as $index => $item) {
                if (isset($record['marks'][(int) $item['id']])) {
                    $marks[(string) $index] = $record['marks'][(int) $item['id']];
                }
            }
            $responses[$code] = (object) $marks;
            $artifacts[$code] = new \stdClass();
        }

        $out = [
            'id'        => (int) $record['id'],
            'timestamp' => self::iso($record['submitted_at']),
            'year'      => (int) $record['assessment_year'],
            'org'       => $org,
            'dims'      => $dims,
            'floor'     => $float($record['floor_score']),
            'floorCode' => $record['floor_code'],
            'mean'      => $float($record['mean_score']),
            'level'     => $record['level_name'],
            'responses' => $responses,
            'artifacts' => $synthetic ? $artifacts : new \stdClass(),
            'synthetic' => $synthetic,
        ];

        if ($synthetic) {
            $out['archetype'] = $record['archetype'];
        }

        return $out + [
            'userName'  => $record['respondent_name'],
            'userEmail' => $record['respondent_email'],
            'userPhone' => $record['respondent_phone'],
        ];
    }

    /** A stored DATETIME (app timezone) as `Date.prototype.toISOString()` prints it. */
    private static function iso(?string $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return (new \DateTimeImmutable($datetime))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @return array<string, mixed> */
    private function instrument(): array
    {
        return Database::selectOne(sprintf(
            'SELECT * FROM `%s` WHERE `status` = \'published\' ORDER BY `published_at` DESC LIMIT 1',
            $this->app->table('instruments')
        )) ?? [];
    }
}
