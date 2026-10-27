<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services\Export;

use Platform\Assessment\Export\CsvWriter;
use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The dataset, as CSV or JSON.
 *
 * Both formats carry every item-level mark, not just competency scores: a
 * competency mean of 3.00 can be five 3s or a 1 and four 3.5s, and a research
 * export that cannot tell those apart is not a research export.
 *
 * Synthetic records are excluded unless explicitly asked for, and whichever way
 * that went is stated inside the file — so a spreadsheet that has been emailed
 * around still says what it contains.
 */
final class DatasetExporter
{
    public function __construct(
        private readonly AppManifest $app,
        private readonly bool $includeSynthetic = false,
    ) {
    }

    /**
     * Filename stem, shared by both formats — the reference's, exactly.
     *
     * It does not say whether synthetic records are in the file, which means
     * two exports of the same dataset land on disk as `… .csv` and
     * `… (1).csv`. The file itself still says: the `synthetic` column per row
     * in the CSV, `includes_synthetic` and a shouting first caveat in the JSON.
     */
    public function filename(string $extension): string
    {
        return sprintf('raix-scorecard-%s.%s', date('Y-m-d'), $extension);
    }

    /* ------------------------------------------------------------------ */

    public function csv(): string
    {
        $instrument   = $this->instrument();
        $competencies = $this->competencies((int) $instrument['id']);

        $csv = new CsvWriter();

        /*
         * The public reference's eighteen columns, in its order and under its
         * names — the fifteen of the sibling build plus email, phone and
         * consent_at after the director — then a column per competency and a
         * column per item: 60 in all.
         *
         * What it did NOT carry is not carried here either — the public token,
         * tenure, board seats, agenda meetings, education hours and the
         * instrument version. All of them are still in the JSON export, which
         * is the one the console calls the full structured dataset.
         */
        $headers = [
            'id', 'timestamp', 'year', 'synthetic',
            'board', 'director', 'email', 'phone', 'consent_at',
            'seat', 'sector', 'company_size',
            'ai_systems_seen', 'remit',
            'level', 'floor', 'floor_competency', 'mean',
        ];

        foreach ($competencies as $competency) {
            $headers[] = $competency['code'];
        }

        // One column per item: CODE_1 … CODE_5.
        foreach ($competencies as $competency) {
            foreach ($competency['items'] as $item) {
                $headers[] = $competency['code'] . '_' . $item['position'];
            }
        }

        $csv->headers($headers);

        foreach ($this->records((int) $instrument['id']) as $record) {
            $row = [
                $record['id'],
                $this->timestamp($record['submitted_at']),
                $record['assessment_year'],
                (int) $record['is_synthetic'],
                $record['board'],
                $record['director_name'],
                $record['director_email'],
                $record['director_phone'],
                $record['consent_agreed_at'] === null ? '' : $this->timestamp($record['consent_agreed_at']),
                $record['seat_role'],
                $record['sector'],
                $record['size_band'],
                $record['systems_seen'],
                $this->remit($record['remit']),
                // The rung's NAME, as the reference's `level` column held —
                // the number is derivable from it and is in the JSON.
                $record['level_name'],
                $this->number($record['floor_score']),
                $record['floor_code'],
                $this->number($record['mean_score']),
            ];

            foreach ($competencies as $competency) {
                $row[] = $this->number($record['scores'][$competency['code']] ?? null);
            }

            foreach ($competencies as $competency) {
                foreach ($competency['items'] as $item) {
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
        $records    = $this->records((int) $instrument['id']);

        return json_encode([
            'instrument'         => $instrument['name'],
            'instrument_version' => $instrument['version'],
            'scoring_rule'       => $instrument['rule_text'],
            'exported_at'        => date('c'),
            'includes_synthetic' => $this->includeSynthetic,
            'n'                  => count($records),
            'caveats'            => $this->caveats(),
            'records'            => array_map(
                static fn (array $r): array => [
                    'id'              => (int) $r['id'],
                    'token'           => $r['public_token'],
                    'submitted_at'    => $r['submitted_at'],
                    'assessment_year' => (int) $r['assessment_year'],
                    'synthetic'       => (bool) $r['is_synthetic'],
                    'board'           => [
                        'name'   => $r['board'],
                        'sector' => $r['sector'],
                        'size'   => $r['size_band'],
                    ],
                    'director' => [
                        'name'   => $r['director_name'],
                        'email'  => $r['director_email'],
                        'phone'  => $r['director_phone'],
                        'seat'   => $r['seat_role'],
                        'tenure' => $r['tenure'],
                    ],
                    'consent' => [
                        'agreed_at' => $r['consent_agreed_at'],
                        'terms'     => $r['consent_terms_url'],
                    ],
                    'context' => [
                        'ai_systems_seen' => $r['systems_seen'] === null ? null : (int) $r['systems_seen'],
                        'board_seats'     => $r['board_seats'] === null ? null : (int) $r['board_seats'],
                        'agenda_meetings' => $r['agenda_meetings'] === null ? null : (int) $r['agenda_meetings'],
                        'education_hours' => $r['education_hours'] === null ? null : (float) $r['education_hours'],
                        'remit'           => $r['remit'] === null ? [] : json_decode((string) $r['remit'], true),
                    ],
                    'result' => [
                        'level_value'       => $r['level_value'] === null ? null : (int) $r['level_value'],
                        'level_name'        => $r['level_name'],
                        'floor_score'       => $r['floor_score'] === null ? null : (float) $r['floor_score'],
                        'floor_competency'  => $r['floor_code'],
                        'mean_score'        => $r['mean_score'] === null ? null : (float) $r['mean_score'],
                    ],
                    'competency_scores' => array_map('floatval', $r['scores']),
                    'item_marks'        => $r['item_marks'],
                    'evidence_notes'    => $r['evidence'],
                ],
                $records
            ),
        ],
            // PRESERVE_ZERO_FRACTION matters here: without it json_encode turns
            // a score of 1.0 into `1` and 3.14 into `3.14`, so the same field
            // decodes as int for one record and float for another. Anything
            // typing the column downstream then has to guess.
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public function caveats(): array
    {
        $caveats = [
            'self-reported and unverified',
            'self-selected sample',
            'a director\'s level is the band of their weakest competency, not their average',
            'correlations are withheld below n = ' . (int) $this->app->config('scoring.correlation_min_n', 30),
            'not empirically validated',
        ];

        if ($this->includeSynthetic) {
            array_unshift(
                $caveats,
                'THIS EXPORT INCLUDES SYNTHETIC DEMO RECORDS, flagged by the "synthetic" field'
            );
        }

        return $caveats;
    }

    /**
     * Chosen submissions as page-shaped records, keyed by submission id.
     * RecordMirror uses it to give the console's demo rows a record each, so
     * the v14 page's own admin views show them too.
     *
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function pageRecords(AppManifest $app, string $where, array $params = []): array
    {
        $exporter     = new self($app, true);
        $instrument   = $exporter->instrument();
        $competencies = $exporter->competencies((int) $instrument['id']);
        $records      = $exporter->records((int) $instrument['id'], $where, $params);

        if ($records === []) {
            return [];
        }

        $commitments = [];
        $ids         = array_map('intval', array_column($records, 'id'));
        foreach (Database::select(sprintf(
            'SELECT `submission_id`, `commitment_key`, `is_checked` FROM `%s` WHERE `submission_id` IN (%s)',
            $app->table('submission_commitments'),
            implode(',', array_fill(0, count($ids), '?'))
        ), $ids) as $row) {
            $commitments[(int) $row['submission_id']][$row['commitment_key']] = (int) $row['is_checked'] === 1;
        }

        $out = [];
        foreach ($records as $record) {
            $out[(int) $record['id']] = self::pageRecord($record, $competencies, $commitments[(int) $record['id']] ?? []);
        }

        return $out;
    }

    /**
     * One record, shaped as the page's `buildRecord()` / `synthRecord()`.
     *
     * A real record's `org` is the whole profile the form produced (consent
     * first); a synthetic one's has no consent and carries `archetype`.
     * `responses` and `artifacts` are keyed by competency code, then question
     * INDEX "0" to "4", as `A.responses[code][qi]` and `A.artifacts[code][qi]`
     * are; an artifact is the evidence note typed against a mark.
     *
     * @param list<array<string, mixed>> $competencies
     * @param array<string, bool>        $commitments
     * @return array<string, mixed>
     */
    private static function pageRecord(array $record, array $competencies, array $commitments): array
    {
        $synthetic = (bool) $record['is_synthetic'];
        $float     = static fn ($v): ?float => $v === null ? null : (float) $v;
        $int       = static fn ($v): ?int => $v === null ? null : (int) $v;
        $remit     = $record['remit'] ? (json_decode((string) $record['remit'], true) ?: []) : [];

        $org = $synthetic ? [] : [
            'consent' => $record['consent_agreed_at'] === null ? null : [
                'at'        => self::iso($record['consent_agreed_at']),
                'scorecard' => true,
                'research'  => true,
                'terms'     => $record['consent_terms_url'],
            ],
        ];

        $org += [
            'org'     => $record['board_name'] ?? '',
            'name'    => $record['director_name'],
            'email'   => $record['director_email'],
            'phone'   => $record['director_phone'],
            'role'    => $record['seat_role'] ?? '',
            'sector'  => $record['sector'] ?? 'Not specified',
            'size'    => $record['company_size'],
            'year'    => (int) $record['assessment_year'],
            'systems' => $int($record['systems_seen']),
            'own'     => array_values((array) $remit),
            'tenure'  => $record['tenure'],
            'seats'   => $int($record['board_seats']),
            'agenda'  => $int($record['agenda_meetings']),
            'edu'     => $float($record['education_hours']),
        ];

        $dims      = [];
        $responses = [];
        $artifacts = [];

        foreach ($competencies as $competency) {
            $code        = $competency['code'];
            $dims[$code] = $float($record['scores'][$code] ?? null);

            $marks = [];
            $notes = [];
            foreach ($competency['items'] as $index => $item) {
                if (isset($record['marks'][(int) $item['id']])) {
                    $marks[(string) $index] = $record['marks'][(int) $item['id']];
                }
                $note = $record['evidence'][$code . '_' . $item['position']] ?? null;
                if ($note !== null) {
                    $notes[(string) $index] = $note;
                }
            }
            $responses[$code] = (object) $marks;
            $artifacts[$code] = (object) $notes;
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
            'artifacts' => $artifacts,
            'synthetic' => $synthetic,
        ];

        if ($synthetic) {
            $out['archetype'] = $record['archetype'];
        }

        return $out + [
            'consequences' => [
                'register' => $commitments['register'] ?? false,
                'agenda'   => $commitments['agenda'] ?? false,
                'gate'     => $commitments['gate'] ?? false,
            ],
            'userName'  => $record['director_name'],
            'userEmail' => $record['director_email'],
            'userPhone' => $record['director_phone'],
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

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function instrument(): array
    {
        return Database::selectOne(sprintf(
            'SELECT * FROM `%s` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
            $this->app->table('instruments')
        ), ['published']) ?? [];
    }

    /**
     * Competencies with their items, in instrument order.
     *
     * @return list<array<string, mixed>>
     */
    private function competencies(int $instrumentId): array
    {
        $competencies = Database::select(sprintf(
            'SELECT `id`, `code`, `name`, `position` FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->app->table('competencies')
        ), [$instrumentId]);

        foreach ($competencies as $index => $competency) {
            $competencies[$index]['items'] = Database::select(sprintf(
                'SELECT `id`, `position` FROM `%s` WHERE `competency_id` = ? ORDER BY `position`',
                $this->app->table('items')
            ), [$competency['id']]);
        }

        return $competencies;
    }

    /**
     * Every exportable record, with its scores and marks attached.
     *
     * Three queries rather than one per record: a dataset export that runs 35
     * queries per row stops working at exactly the point it becomes useful.
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
            'SELECT s.*, COALESCE(s.`board_name`, o.`name`) AS board,
                    COALESCE(s.`sector`, o.`sector`) AS `sector`,
                    COALESCE(s.`company_size`, o.`size_band`) AS `size_band`,
                    fc.`code` AS floor_code, r.`name` AS level_name
               FROM `%s` s
          LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id`
          LEFT JOIN `%s` fc ON fc.`id` = s.`floor_competency_id`
          LEFT JOIN `%s` r ON r.`instrument_id` = s.`instrument_id` AND r.`value` = s.`level_value`
              WHERE %s
           ORDER BY s.`submitted_at`, s.`id`',
            $this->app->table('submissions'),
            $this->app->table('competencies'),
            $this->app->table('rungs'),
            $where
        ), $params);

        if ($records === []) {
            return [];
        }

        $ids          = array_column($records, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $scoresBySubmission = [];
        foreach (Database::select(sprintf(
            'SELECT ss.`submission_id`, c.`code`, ss.`score`
               FROM `%s` ss JOIN `%s` c ON c.`id` = ss.`competency_id`
              WHERE ss.`submission_id` IN (%s)',
            $this->app->table('submission_scores'),
            $this->app->table('competencies'),
            $placeholders
        ), $ids) as $row) {
            $scoresBySubmission[(int) $row['submission_id']][$row['code']] = (float) $row['score'];
        }

        $marksBySubmission = [];
        foreach (Database::select(sprintf(
            'SELECT r.`submission_id`, r.`item_id`, r.`mark`, r.`evidence_note`,
                    c.`code`, i.`position`
               FROM `%s` r
               JOIN `%s` i ON i.`id` = r.`item_id`
               JOIN `%s` c ON c.`id` = i.`competency_id`
              WHERE r.`submission_id` IN (%s)',
            $this->app->table('submission_responses'),
            $this->app->table('items'),
            $this->app->table('competencies'),
            $placeholders
        ), $ids) as $row) {
            $id  = (int) $row['submission_id'];
            $key = $row['code'] . '_' . $row['position'];

            $marksBySubmission[$id]['by_item'][(int) $row['item_id']] = (int) $row['mark'];
            $marksBySubmission[$id]['by_code'][$key] = (int) $row['mark'];

            if ($row['evidence_note'] !== null && $row['evidence_note'] !== '') {
                $marksBySubmission[$id]['evidence'][$key] = $row['evidence_note'];
            }
        }

        foreach ($records as $index => $record) {
            $id = (int) $record['id'];

            $records[$index]['scores']     = $scoresBySubmission[$id] ?? [];
            $records[$index]['marks']      = $marksBySubmission[$id]['by_item'] ?? [];
            $records[$index]['item_marks'] = $marksBySubmission[$id]['by_code'] ?? [];
            $records[$index]['evidence']   = $marksBySubmission[$id]['evidence'] ?? [];
        }

        return $records;
    }

    /**
     * A score the way the reference wrote it: 2.4, not 2.40, and 3, not 3.00.
     *
     * Its scores were JavaScript numbers and printed as such. Here they come
     * out of DECIMAL(4,2) as "2.40", so the trailing zeros are dropped rather
     * than carried — the two files are otherwise column-identical and differ
     * in every score cell.
     */
    private function number(mixed $value): string
    {
        return $value === null || $value === '' ? '' : (string) (float) $value;
    }

    /** ISO-8601, which is what the reference's `timestamp` field held. */
    private function timestamp(?string $value): string
    {
        return $value === null || $value === '' ? '' : date('c', (int) strtotime($value));
    }

    private function remit(?string $remit): string
    {
        if ($remit === null || $remit === '') {
            return '';
        }

        $codes = json_decode($remit, true);

        return is_array($codes) ? implode('|', $codes) : '';
    }
}
