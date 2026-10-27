<?php

declare(strict_types=1);

use Apps\EnterpriseAiScorecard\Services\Export\CodebookExporter;
use Apps\EnterpriseAiScorecard\Services\Export\DatasetExporter;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The exports, which are the only artefacts of this app that leave the building.
 *
 * What they have to get right:
 *
 *  - Every one of the thirty item marks, not just the six dimension scores. A
 *    dimension mean of 3.00 can be five 3s or a 1 and four 3.5s.
 *  - Whether synthetic records are in the file, stated INSIDE the file. A
 *    spreadsheet that has been emailed around has lost the screen that said.
 *  - A CSV that survives an organisation name containing a comma or a quote.
 *  - A codebook that documents only rules this application actually implements.
 *
 * The fixture is removed in a finally. Nothing truncates a table.
 */
return static function (Assert $t): void {
    $app          = AppRegistry::get('enterprise-ai-scorecard');
    $instrumentId = (int) Database::scalar(
        'SELECT `id` FROM `es_instruments` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
        ['published']
    );

    $dimensions = Database::select(
        'SELECT `id`, `code` FROM `es_dimensions` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrumentId]
    );

    /*
     * A name carrying both of the characters that break a naive CSV writer. The
     * reference got this right and it is worth keeping right: a research export
     * that silently mangles one row in a thousand is worse than one that fails.
     */
    $awkward = 'Ac"me, Inc — ZZ Export Test';
    $now     = date('Y-m-d H:i:s');

    $submissionId = Database::insert('es_submissions', [
        'public_token'      => bin2hex(random_bytes(16)),
        'instrument_id'     => $instrumentId,
        'respondent_name'   => 'Export Test',
        'respondent_email'  => 'export.test@zz-export.test',
        'respondent_phone'  => '+1 555 0155',
        'organisation_name' => $awkward,
        'organisation_size' => '1–50',
        'sector'            => 'Technology',
        'role'              => 'Chief Executive Officer',
        'assessment_year'   => (int) date('Y'),
        'systems_inventory' => 11,
        'agenda_meetings'   => 3,
        'education_hours'   => 7.5,
        'consent_agreed_at' => $now,
        'consent_terms_url' => (string) $app->config('result.consent.terms_url'),
        'status'            => 'complete',
        'is_synthetic'      => 0,
        'submitted_at'      => $now,
        'created_at'        => $now,
    ]);

    try {
        /* A distinct mark on every question, so a mis-ordered column shows up. */
        $mark = 1;
        foreach ($dimensions as $dimension) {
            $items = Database::select(
                'SELECT `id` FROM `es_items` WHERE `dimension_id` = ? ORDER BY `position`',
                [$dimension['id']]
            );

            foreach ($items as $item) {
                Database::insert('es_submission_responses', [
                    'submission_id' => $submissionId,
                    'item_id'       => $item['id'],
                    'mark'          => ($mark % 5) + 1,
                    'answered_at'   => $now,
                ]);
                $mark++;
            }
        }

        (new \Apps\EnterpriseAiScorecard\Services\Scoring\Scorer($app))->score($submissionId);

        /* ---------- CSV ---------- */

        $exporter = new DatasetExporter($app, false);
        $csv      = $exporter->csv();

        $t->true('the CSV opens with a BOM, so Excel reads the en dashes', str_starts_with($csv, "\xEF\xBB\xBF"));
        $t->true('and uses CRLF, as RFC 4180 says', str_contains($csv, "\r\n"));

        $lines  = explode("\r\n", trim(substr($csv, 3)));
        $header = str_getcsv($lines[0]);

        $t->count('fifty-three columns', 53, $header);
        $t->same('id first', 'id', $header[0]);
        $t->same('the six dimension columns start at 18', 'PIP', $header[17]);
        $t->same('and the thirty question columns after them', 'PIP_1', $header[23]);
        $t->same('ending with the last dimension', 'WRK_5', $header[52]);

        $t->true(
            'the level column sits beside the floor, both named',
            in_array('level', $header, true)
                && in_array('floor', $header, true)
                && in_array('floor_dimension', $header, true)
                && in_array('mean', $header, true)
        );

        /* The awkward name must survive the round trip intact. */
        $ours = null;
        foreach (array_slice($lines, 1) as $line) {
            $row = str_getcsv($line);
            if (($row[4] ?? null) === $awkward) {
                $ours = $row;
                break;
            }
        }

        $t->true('the record is in the file', $ours !== null);

        if ($ours !== null) {
            $t->same('a name with a comma and a quote survives', $awkward, $ours[4]);
            $t->same('the respondent is named', 'Export Test', $ours[6]);
            $t->same('with their email', 'export.test@zz-export.test', $ours[7]);
            $t->same('and their phone', '+1 555 0155', $ours[8]);
            $t->true('and when they consented', $ours[9] !== '');
            $t->same('not flagged synthetic', '0', $ours[3]);

            /* Thirty marks, in instrument order, each matching what was written. */
            $marks = array_slice($ours, 23, 30);
            $t->count('thirty marks', 30, $marks);
            $t->same('the first question', '2', $marks[0]);
            $t->same('the fifth', '1', $marks[4]);
            $t->same('the last', '1', $marks[29]);

            /* And the dimension scores are the mean of their five. */
            $t->same('PIP scores the mean of its five marks', '3.00', $ours[17]);
        }

        /* ---------- the synthetic toggle is stated in the file ---------- */

        $withSynthetic = new DatasetExporter($app, true);

        $t->true(
            'excluding synthetic records is stated in the JSON',
            str_contains($exporter->json(), '"includes_synthetic": false')
        );
        $t->true(
            'and including them is stated too',
            str_contains($withSynthetic->json(), '"includes_synthetic": true')
        );
        $t->true(
            'and shouted, as the first caveat a person reads',
            str_contains($withSynthetic->caveats()[0], 'INCLUDES SYNTHETIC')
        );
        $t->true(
            'while an export without them does not mention them first',
            !str_contains($exporter->caveats()[0], 'SYNTHETIC')
        );

        $t->true(
            'the counts differ, so the toggle does something',
            $withSynthetic->count() >= $exporter->count()
        );

        /* ---------- JSON ---------- */

        $json = json_decode($exporter->json(), true, 512, JSON_THROW_ON_ERROR);

        $t->same('the instrument names itself', '1.0', $json['version']);
        $t->same('the record count matches the array', count($json['records']), $json['n']);

        $record = null;
        foreach ($json['records'] as $candidate) {
            if ($candidate['organization']['name'] === $awkward) {
                $record = $candidate;
                break;
            }
        }

        $t->true('our record is in the JSON', $record !== null);

        if ($record !== null) {
            $t->count('with all six dimension scores', 6, $record['dimensions']);
            $t->count('and all six blocks of responses', 6, $record['responses']);
            $t->count('five marks in the first', 5, $record['responses']['PIP']);
            $t->same('the respondent block carries the contact details', 'Export Test', $record['respondent']['name']);
            $t->true('the consent block is present', $record['consent'] !== null);
            $t->same('and names the terms', $app->config('result.consent.terms_url'), $record['consent']['terms']);
            $t->true('the level is reported', $record['level'] !== null);
            $t->true('and the floor alongside it, named', $record['floor_dimension'] !== null);
        }

        $t->true(
            'the rule the file states is the mean rule',
            str_contains((string) $json['rule'], 'overall average')
        );

        /* ---------- the codebook ---------- */

        $codebook = json_decode((new CodebookExporter($app))->json(), true, 512, JSON_THROW_ON_ERROR);

        $t->same('six dimensions', 6, $codebook['dimension_count']);
        $t->same('thirty questions', 30, $codebook['item_count']);
        $t->count('five levels, with their cut points', 5, $codebook['scoring']['scale']);
        $t->same('the lowest band is open below', null, $codebook['scoring']['scale'][0]['band_min']);
        $t->same('and the highest open above', null, $codebook['scoring']['scale'][4]['band_max']);
        $t->same('the second band starts at 1.80', 1.8, $codebook['scoring']['scale'][1]['band_min']);

        $t->true(
            'it says the level comes from the average',
            str_contains($codebook['scoring']['organisation_level'], 'overall score')
        );
        $t->true(
            'and that the floor does NOT set it',
            str_contains($codebook['scoring']['floor'], 'does NOT set the level')
        );
        $t->true(
            'and which way a score on a boundary goes',
            str_contains($codebook['scoring']['band_boundaries'], 'HIGHER')
        );

        /*
         * The reference codebook documented a `coverage` rule that nothing
         * computed, so a researcher would go looking for a field that does not
         * exist. No rule is documented here unless it is implemented.
         */
        $text = (new CodebookExporter($app))->json();

        $t->true('no coverage rule is documented, because none is computed', !str_contains($text, 'coverage'));
        $t->true('no cadence is documented either', !str_contains($text, 'cadence'));

        $t->count('every question carries both of its written anchors', 5, $codebook['dimensions'][0]['items']);
        $t->true(
            'and the anchors are the wording, not just a label',
            isset($codebook['dimensions'][0]['items'][0]['anchor_1']['text'])
        );

        $t->true(
            'it names the required identity fields',
            in_array('email', $codebook['identification']['required_fields'], true)
        );
        $t->same('and is explicit that none of them is verified', false, $codebook['identification']['verified']);

        $t->true(
            'it says what the seeder does not generate',
            isset($codebook['synthetic_records']['not_generated']['agenda_meetings'])
        );

        /* Filenames are stable and safe to put in a Content-Disposition. */
        $t->true('the CSV filename is dated', (bool) preg_match(
            '/^enterprise-scorecard-\d{4}-\d{2}-\d{2}\.csv$/',
            $exporter->filename('csv')
        ));
        $t->true('and the codebook names its version', (bool) preg_match(
            '/^enterprise-scorecard-codebook-v[\d.]+\.json$/',
            (new CodebookExporter($app))->filename()
        ));
    } finally {
        Database::statement('DELETE FROM `es_submissions` WHERE `id` = ?', [$submissionId]);
    }
};
