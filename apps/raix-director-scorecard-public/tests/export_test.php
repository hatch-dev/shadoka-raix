<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecardPublic\Services\Export\CodebookExporter;
use Apps\RaixDirectorScorecardPublic\Services\Export\DatasetExporter;
use Apps\RaixDirectorScorecardPublic\Services\SubmissionService;
use Platform\Assessment\Export\CsvWriter;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * Exports are parsed back, not merely generated.
 *
 * A research export is a file that leaves the building and is cited later, so
 * the things worth asserting are that every mark survives the round trip, that
 * awkward characters in a board name do not shift a column, and that the
 * codebook describes the instrument actually in use.
 */
return static function (Assert $t): void {
    $app     = AppRegistry::get('raix-director-scorecard-public');
    $service = new SubmissionService($app);

    /* ---------- CSV quoting, before any database is involved ---------- */

    $t->same('null becomes an empty field', '""', CsvWriter::cell(null));
    $t->same('a quote is doubled', '"Ac""me"', CsvWriter::cell('Ac"me'));
    $t->same('a comma stays inside the field', '"Ac""me, Inc"', CsvWriter::cell('Ac"me, Inc'));
    $t->same('a newline is quoted, not escaped away', "\"line1\nline2\"", CsvWriter::cell("line1\nline2"));
    $t->same('true becomes 1', '"1"', CsvWriter::cell(true));
    $t->same('false becomes 0', '"0"', CsvWriter::cell(false));
    $t->same('an en dash survives untouched', '"251–1,000"', CsvWriter::cell('251–1,000'));

    /* ---------- a record to export ---------- */

    $board  = 'Ex"port, Ltd ' . bin2hex(random_bytes(3));
    $tokens = [];

    try {
        $started  = $service->start([
            'board_name'      => $board,
            'director_name'   => 'Export Probe',
            'director_email'  => 'probe@raix-tests.org',
            'director_phone'  => '+1 202 555 0147',
            'seat_role'       => 'Audit Committee Chair',
            'sector'          => 'Technology',
            'company_size'    => '251–1,000',
            'assessment_year' => (int) date('Y'),
            'board_seats'     => 9,
            'agenda_meetings' => 2,
            'education_hours' => 6.5,
            'remit'           => ['GOV'],
        ]);
        $tokens[] = $started['token'];

        $submission = $service->findByToken($started['token']);

        $items = Database::select(
            'SELECT i.`id`, c.`code`, i.`position`
               FROM `dsp_items` i JOIN `dsp_competencies` c ON c.`id` = i.`competency_id`
              WHERE c.`instrument_id` = ? ORDER BY c.`position`, i.`position`',
            [$submission['instrument_id']]
        );

        $marks = ['GOV' => 4, 'DAT' => 4, 'EXP' => 3, 'FAI' => 3, 'RIS' => 4, 'HUM' => 3, 'AUT' => 1];

        $service->saveProgress($submission, array_map(
            static fn (array $i): array => [
                'item_id'       => (int) $i['id'],
                'mark'          => $marks[$i['code']],
                'evidence_note' => $i['code'] === 'GOV' && (int) $i['position'] === 1
                    ? 'Board minutes Mar 12, item 4'
                    : null,
            ],
            $items
        ));
        $service->submit($service->findByToken($started['token']));

        /* ---------- CSV ---------- */

        $exporter = new DatasetExporter($app, false);
        $csv      = $exporter->csv();

        $t->true('CSV starts with a UTF-8 BOM (Excel reads en dashes correctly)', str_starts_with($csv, "\xEF\xBB\xBF"));
        $t->true('CSV uses CRLF line endings', str_contains($csv, "\r\n"));

        $rows = str_getcsv_all(substr($csv, 3));
        $t->true('CSV parses back', count($rows) >= 2);

        $headers = $rows[0];

        /*
         * The reference's own header row, in its order — `exportCSV()` in the
         * single-file build. Asserted as a whole rather than column by column:
         * the point of this export is that a file written by that build and a
         * file written by this one load into the same analysis.
         */
        $t->same(
            'the CSV carries the reference\'s columns, in its order',
            [
                'id', 'timestamp', 'year', 'synthetic',
                'board', 'director', 'email', 'phone', 'consent_at',
                'seat', 'sector', 'company_size',
                'ai_systems_seen', 'remit',
                'level', 'floor', 'floor_competency', 'mean',
                'GOV', 'DAT', 'EXP', 'FAI', 'RIS', 'HUM', 'AUT',
                'GOV_1', 'GOV_2', 'GOV_3', 'GOV_4', 'GOV_5',
                'DAT_1', 'DAT_2', 'DAT_3', 'DAT_4', 'DAT_5',
                'EXP_1', 'EXP_2', 'EXP_3', 'EXP_4', 'EXP_5',
                'FAI_1', 'FAI_2', 'FAI_3', 'FAI_4', 'FAI_5',
                'RIS_1', 'RIS_2', 'RIS_3', 'RIS_4', 'RIS_5',
                'HUM_1', 'HUM_2', 'HUM_3', 'HUM_4', 'HUM_5',
                'AUT_1', 'AUT_2', 'AUT_3', 'AUT_4', 'AUT_5',
            ],
            $headers
        );
        $t->same('which is 60 columns', 60, count($headers));

        $row = null;
        foreach (array_slice($rows, 1) as $candidate) {
            $record = array_combine($headers, $candidate);
            if (($record['director'] ?? '') === 'Export Probe') {
                $row = $record;
                break;
            }
        }

        $t->true('our record is in the export', $row !== null);

        if ($row !== null) {
            $t->same('a board name with a quote and comma survives intact', $board, $row['board']);
            $t->same('an en dash survives the round trip', '251–1,000', $row['company_size']);
            // `level` is the rung's NAME there, not its value.
            $t->same('level', 'Unaware', $row['level']);
            $t->same('floor competency', 'AUT', $row['floor_competency']);
            $t->same('synthetic flag', '0', $row['synthetic']);
            $t->same('remit', 'GOV', $row['remit']);
            $t->true('the timestamp is ISO-8601', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $row['timestamp']));

            /*
             * Scores print as the reference's JavaScript numbers did: a floor
             * of exactly 1 is "1", not "1.00". Trailing zeros here and 2dp in
             * the next column is the one thing a file like this must not do,
             * so every score column goes through the same formatter.
             */
            $t->same('floor', '1', $row['floor']);
            $t->same('mean', '3.14', $row['mean']);

            // Every one of the 35 marks, in the right column.
            $mismatched = 0;
            foreach ($items as $item) {
                $key = $item['code'] . '_' . $item['position'];
                if ((string) $marks[$item['code']] !== ($row[$key] ?? '')) {
                    $mismatched++;
                }
            }
            $t->same('all 35 item marks land in the right columns', 0, $mismatched);

            $t->same('competency score column', '4', $row['GOV']);
            $t->same('floor competency score column', '1', $row['AUT']);
        }

        /* ---------- JSON ---------- */

        $json = json_decode((new DatasetExporter($app, false))->json(), true, 512, JSON_THROW_ON_ERROR);

        $t->same('JSON names the instrument from the database', 'RAI-X Director Scorecard', $json['instrument']);
        $t->same('JSON states the version', '1.0', $json['instrument_version']);
        $t->same('JSON states whether synthetic records are included', false, $json['includes_synthetic']);
        $t->true('JSON carries caveats', count($json['caveats']) >= 4);

        $record = null;
        foreach ($json['records'] as $candidate) {
            if (($candidate['director']['name'] ?? '') === 'Export Probe') {
                $record = $candidate;
            }
        }

        $t->true('our record is in the JSON export', $record !== null);

        if ($record !== null) {
            $t->same('JSON board name', $board, $record['board']['name']);
            $t->same('JSON floor', 1.0, $record['result']['floor_score']);
            $t->same('JSON level name', 'Unaware', $record['result']['level_name']);
            $t->count('JSON carries all 35 item marks', 35, $record['item_marks']);
            $t->same('JSON keeps the evidence note', 'Board minutes Mar 12, item 4', $record['evidence_notes']['GOV_1'] ?? null);
            $t->same('JSON remit', ['GOV'], $record['context']['remit']);
        }

        /* ---------- the synthetic flag changes what is exported AND what is said ---------- */

        $withSynthetic = json_decode((new DatasetExporter($app, true))->json(), true, 512, JSON_THROW_ON_ERROR);
        $t->same('the with-synthetic export says so', true, $withSynthetic['includes_synthetic']);
        $t->true(
            'and warns about it in the caveats, first',
            str_contains((string) ($withSynthetic['caveats'][0] ?? ''), 'SYNTHETIC')
        );
        /*
         * The reference's filename, which says nothing about synthetic records
         * — so the two exports collide on disk and the file itself is the only
         * thing that tells them apart. Asserted so that changing it back is a
         * decision rather than a drift.
         */
        $t->same(
            'the filename is the reference\'s',
            'raix-scorecard-' . date('Y-m-d') . '.csv',
            (new DatasetExporter($app, false))->filename('csv')
        );
        $t->same(
            'and does not vary with the synthetic setting',
            (new DatasetExporter($app, false))->filename('csv'),
            (new DatasetExporter($app, true))->filename('csv')
        );

        /* ---------- codebook: the legacy defects must not return ---------- */

        $codebook = json_decode((new CodebookExporter($app))->json(), true, 512, JSON_THROW_ON_ERROR);

        $t->same(
            'codebook names THIS instrument, not the organisation-level one',
            'RAI-X Director Scorecard',
            $codebook['instrument']
        );
        $t->same('codebook version matches the instrument it describes', '1.0', $codebook['version']);
        $t->true(
            'codebook does NOT document board_coverage (nothing computes it)',
            !str_contains(json_encode($codebook), 'board_coverage'),
            'the legacy codebook documented a rule that was implemented nowhere'
        );

        $t->same('codebook counts the competencies', 7, $codebook['structure']['competencies']);
        $t->same('codebook counts the items', 35, $codebook['structure']['items']);
        $t->same('codebook counts the scale points', 4, $codebook['structure']['scale_points']);
        $t->count('codebook lists every competency', 7, $codebook['competencies']);
        $t->same(
            'codebook states the floor rule',
            'director level = band of the weakest scored competency',
            $codebook['scoring']['director_level']
        );
        $t->true('codebook explains the tie rule', str_contains($codebook['scoring']['ties'], 'earlier'));
        $t->true('codebook explains unscored competencies', str_contains($codebook['scoring']['unscored'], 'cannot set the level'));
        $t->same('codebook band for Practiced', ['min' => 2.75, 'max' => 3.75], $codebook['scale'][2]['band']);
        $t->true('codebook carries caveats', count($codebook['caveats']) >= 4);

        $statements = 0;
        foreach ($codebook['competencies'] as $competency) {
            $statements += count($competency['items']);
        }
        $t->same('codebook carries all 35 statements', 35, $statements);

        /* ---------- the research report's inputs ---------- */

        // The report is a rendered page rather than a file, so what is asserted
        // here is that every figure it prints is available and consistent with
        // the exports beside it — a report disagreeing with the CSV it ships
        // with is worse than no report.
        $analytics = new \Apps\RaixDirectorScorecardPublic\Services\Analytics($app, false);
        $overview  = $analytics->overview();

        $t->true('the report has an overview to print', $overview['n'] > 0);
        $t->same(
            'report record count matches the JSON export',
            $json['n'],
            $overview['n'],
            'the report and the dataset export must not disagree about n'
        );

        $levels = $analytics->levelDistribution();
        $t->count('the report can print every rung', 4, $levels);
        $t->true(
            'rung descriptions come from the instrument, not from the view',
            ($levels[0]['description'] ?? '') !== ''
        );
        $t->same(
            'level counts sum to the number of scorecards',
            $overview['n'],
            array_sum(array_column($levels, 'n'))
        );

        $reportStatements = 0;
        foreach ($analytics->competencies() as $competency) {
            $detail = $analytics->items($competency['code']);
            if ($detail !== null) {
                $reportStatements += count($detail['items']);
            }
        }
        $t->same('the report prints all 35 statements', 35, $reportStatements);
    } finally {
        foreach ($tokens as $token) {
            Database::statement('DELETE FROM `dsp_submissions` WHERE `public_token` = ?', [$token]);
        }
        Database::statement(
            'DELETE FROM `organisations` WHERE `id` NOT IN
             (SELECT DISTINCT `organisation_id` FROM `dsp_submissions` WHERE `organisation_id` IS NOT NULL)
             AND `name` LIKE ?',
            ['Ex%port%']
        );
    }
};

/**
 * Parse a whole CSV string into rows.
 *
 * str_getcsv() handles a single line; a quoted field containing a newline spans
 * lines, so the string is walked through a memory stream instead of exploded.
 *
 * @return list<list<string>>
 */
function str_getcsv_all(string $csv): array
{
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null]) {
            continue;   // blank trailing line
        }
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}
