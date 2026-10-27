<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Services\Export;

use Platform\Assessment\Export\CsvWriter;
use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The dataset, as CSV or JSON.
 *
 * Both formats carry every one of the sixteen item-level marks, not just the four
 * dimension scores: a dimension mean of 3.00 can be four 3s or a 1 and three 3.67s,
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
 * it is why `sov.export` is a capability of its own rather than part of `sov.view`.
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
        return sprintf('ai-sovereignty-scorecard-%s.%s', date('Y-m-d'), $extension);
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
                // ISO 8601 in UTC, as the reference's toISOString() wrote it.
                self::iso($record['submitted_at']),
                $record['assessment_year'],
                (int) $record['is_synthetic'],
                $record['organisation_name'],
                $record['organisation_size'],
                $record['respondent_name'],
                $record['respondent_email'],
                $record['respondent_phone'],
                self::iso($record['consent_agreed_at']),
                $record['role'],
                $record['sector'] ?? $this->app->config('result.unspecified_label', 'Not specified'),
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
        $unspecified = (string) $this->app->config('result.unspecified_label', 'Not specified');

        /*
         * The reference's `exportJSON()`: its header, then its own record array —
         * the objects that build kept in browser storage — key for key and in its
         * order. A notebook written against that file reads this one.
         *
         * `instrument` is the reference's label, not the instrument row's name
         * (which carries the page brand), and `version`, `scale` and `rule` are the
         * row's, which the seeder took from the reference verbatim.
         */
        $payload = [
            'instrument'         => 'AI Sovereignty Scorecard',
            'version'            => $instrument['version'],
            'scale'              => $instrument['scale'],
            'rule'               => $instrument['rule_text'],
            'exported'           => self::iso(date('Y-m-d H:i:s')),
            'includes_synthetic' => $this->includeSynthetic,
            'n'                  => count($records),
            'records'            => array_map(
                static fn (array $record): array => self::referenceRecord($record, $dimensions, $unspecified),
                $records
            ),
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );

        // `JSON.stringify(…, null, 2)`: two-space indentation. Every newline in
        // the output is structural — string values escape theirs.
        return (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)),
            $json
        );
    }

    /**
     * One record, shaped as the reference's `buildRecord()` / `synthRecord()`.
     *
     * The two shapes differ and both are kept: a real record's `org` is the
     * whole profile the form produced (consent first, then tenure, agenda and
     * education last), a synthetic one's stops at `systems`, and only a synthetic
     * record carries `archetype`. `responses` is keyed by question INDEX, "0" to
     * "3", as `A.responses[code][qi]` was. `artifacts` held nothing in either
     * build — real records `{}`, synthetic ones an empty object per dimension.
     *
     * @param list<array<string, mixed>> $dimensions
     * @return array<string, mixed>
     */
    private static function referenceRecord(array $record, array $dimensions, string $unspecified): array
    {
        $synthetic = (bool) $record['is_synthetic'];
        $float     = static fn ($v): ?float => $v === null ? null : (float) $v;
        $int       = static fn ($v): ?int => $v === null ? null : (int) $v;

        $org = $synthetic ? [] : [
            'consent' => $record['consent_agreed_at'] === null ? null : [
                'agreed' => true,
                'at'     => self::iso($record['consent_agreed_at']),
                'terms'  => $record['consent_terms_url'],
            ],
        ];

        $org += [
            'org'     => $record['organisation_name'],
            'name'    => $record['respondent_name'],
            'email'   => $record['respondent_email'],
            'phone'   => $record['respondent_phone'],
            // The form's value for "Select…" was the empty string.
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

        $at = new \DateTimeImmutable($datetime);

        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * What this dataset may not be used to claim.
     *
     * Printed on the research report page. The JSON export no longer carries
     * them: the reference's file has no such key, and it is matched exactly.
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
     * Every record in scope, with its dimension scores and its sixteen marks
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
     * Chosen submissions as page-shaped records (the JSON export's own
     * objects), keyed by submission id. RecordMirror uses it to give the
     * console's demo rows a record each, so the page shows them too.
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
            $out[(int) $record['id']] = self::referenceRecord($record, $dimensions, $unspecified);
        }

        return $out;
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
