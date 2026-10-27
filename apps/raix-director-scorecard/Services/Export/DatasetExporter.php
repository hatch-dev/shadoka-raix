<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Services\Export;

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
         * The reference's fifteen columns, in its order and under its names,
         * then a column per competency and a column per item: 57 in all.
         *
         * What it did NOT carry is not carried here either — the public token,
         * tenure, board seats, agenda meetings, education hours and the
         * instrument version. All of them are still in the JSON export, which
         * is the one the console calls the full structured dataset.
         */
        $headers = [
            'id', 'timestamp', 'year', 'synthetic',
            'board', 'director', 'seat', 'sector', 'company_size',
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
                        'seat'   => $r['seat_role'],
                        'tenure' => $r['tenure'],
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
    private function records(int $instrumentId): array
    {
        $where = "s.`status` = 'complete'";
        if (!$this->includeSynthetic) {
            $where .= ' AND s.`is_synthetic` = 0';
        }

        $records = Database::select(sprintf(
            'SELECT s.*, o.`name` AS board,
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
        ));

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
