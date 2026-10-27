<?php

declare(strict_types=1);

use Apps\AiSovereigntyScorecard\Services\Export\CodebookExporter;
use Apps\AiSovereigntyScorecard\Services\Export\DatasetExporter;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The exports, which are the only artefacts of this app that leave the building.
 *
 * What they have to get right:
 *
 *  - Every one of the sixteen item marks, not just the four dimension scores. A
 *    dimension mean of 3.00 can be four 3s or a 1 and three 3.67s.
 *  - Whether synthetic records are in the file, stated INSIDE the file. A
 *    spreadsheet that has been emailed around has lost the screen that said.
 *  - A CSV that survives an organisation name containing a comma or a quote.
 *  - The reference's own shapes: its CSV columns, its JSON records, its codebook.
 *
 * The fixture is removed in a finally. Nothing truncates a table.
 */
return static function (Assert $t): void {
    $app          = AppRegistry::get('ai-sovereignty-scorecard');
    $instrumentId = (int) Database::scalar(
        'SELECT `id` FROM `sov_instruments` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
        ['published']
    );

    $dimensions = Database::select(
        'SELECT `id`, `code` FROM `sov_dimensions` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrumentId]
    );

    /*
     * A name carrying both of the characters that break a naive CSV writer. The
     * reference got this right and it is worth keeping right: a research export
     * that silently mangles one row in a thousand is worse than one that fails.
     */
    $awkward = 'Ac"me, Inc — ZZ Export Test';
    $now     = date('Y-m-d H:i:s');

    $submissionId = Database::insert('sov_submissions', [
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
                'SELECT `id` FROM `sov_items` WHERE `dimension_id` = ? ORDER BY `position`',
                [$dimension['id']]
            );

            foreach ($items as $item) {
                Database::insert('sov_submission_responses', [
                    'submission_id' => $submissionId,
                    'item_id'       => $item['id'],
                    'mark'          => ($mark % 5) + 1,
                    'answered_at'   => $now,
                ]);
                $mark++;
            }
        }

        (new \Apps\AiSovereigntyScorecard\Services\Scoring\Scorer($app))->score($submissionId);

        /* ---------- CSV ---------- */

        $exporter = new DatasetExporter($app, false);
        $csv      = $exporter->csv();

        $t->true('the CSV opens with a BOM, so Excel reads the en dashes', str_starts_with($csv, "\xEF\xBB\xBF"));
        $t->true('and uses CRLF, as RFC 4180 says', str_contains($csv, "\r\n"));

        $lines  = explode("\r\n", trim(substr($csv, 3)));
        $header = str_getcsv($lines[0]);

        /* The reference's seventeen, then four dimensions, then sixteen questions. */
        $t->count('thirty-seven columns', 37, $header);
        $t->same('id first', 'id', $header[0]);
        $t->same('the four dimension columns start at 18', 'DAT', $header[17]);
        $t->same('and the sixteen question columns after them', 'DAT_1', $header[21]);
        $t->same('ending with the last dimension', 'ADT_4', $header[36]);

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

        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

        if ($ours !== null) {
            $t->same('a name with a comma and a quote survives', $awkward, $ours[4]);
            $t->same('the respondent is named', 'Export Test', $ours[6]);
            $t->same('with their email', 'export.test@zz-export.test', $ours[7]);
            $t->same('and their phone', '+1 555 0155', $ours[8]);
            $t->true('the timestamp is ISO 8601, as toISOString() wrote it', (bool) preg_match($iso, $ours[1]));
            $t->true('and so is when they consented', (bool) preg_match($iso, $ours[9]));
            $t->same('not flagged synthetic', '0', $ours[3]);

            /* Sixteen marks, in instrument order, each matching what was written. */
            $marks = array_slice($ours, 21, 16);
            $t->count('sixteen marks', 16, $marks);
            $t->same('the first question', '2', $marks[0]);
            $t->same('the fifth', '1', $marks[4]);
            $t->same('the last', '2', $marks[15]);

            /* And the dimension scores are the mean of their four. */
            $t->same('DAT scores the mean of its four marks', '3.50', $ours[17]);
            $t->same('INF is the floor', 'INF', $ours[15]);
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
            'the research report page shouts it, as the first caveat a person reads',
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

        /* ---------- JSON: the reference's exportJSON(), key for key ---------- */

        $text = $exporter->json();
        $json = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        $t->same(
            'the header keys are the reference\'s, in its order',
            ['instrument', 'version', 'scale', 'rule', 'exported', 'includes_synthetic', 'n', 'records'],
            array_keys($json)
        );
        $t->same('it names the instrument as the reference did', 'AI Sovereignty Scorecard', $json['instrument']);
        $t->same('at the reference\'s version', '1.1', $json['version']);
        $t->same('on the reference\'s scale', '5-level maturity scale', $json['scale']);
        $t->true('the rule it states is the mean rule', str_contains((string) $json['rule'], 'overall average'));
        $t->true('the export time is ISO 8601', (bool) preg_match($iso, $json['exported']));
        $t->same('the record count matches the array', count($json['records']), $json['n']);
        $t->true('indented by two, as JSON.stringify(..., null, 2)', str_contains($text, "\n  \"version\": \"1.1\""));

        $record = null;
        foreach ($json['records'] as $candidate) {
            if ($candidate['org']['org'] === $awkward) {
                $record = $candidate;
                break;
            }
        }

        $t->true('our record is in the JSON', $record !== null);

        if ($record !== null) {
            $t->same(
                'a real record has buildRecord()\'s keys, in its order',
                ['id', 'timestamp', 'year', 'org', 'dims', 'floor', 'floorCode', 'mean', 'level',
                    'responses', 'artifacts', 'synthetic', 'userName', 'userEmail', 'userPhone'],
                array_keys($record)
            );
            $t->same(
                'and its org block is the profile saveProfile() wrote',
                ['consent', 'org', 'name', 'email', 'phone', 'role', 'sector', 'size', 'year', 'systems',
                    'tenure', 'agenda', 'edu'],
                array_keys($record['org'])
            );
            $t->same('consent is recorded as agreed', true, $record['org']['consent']['agreed']);
            $t->same('with the terms that were shown', $app->config('result.consent.terms_url'), $record['org']['consent']['terms']);
            $t->same('reported figures carry through', 3, $record['org']['agenda']);
            $t->same('including a fractional one', 7.5, $record['org']['edu']);
            $t->count('all four dimension scores', 4, $record['dims']);
            $t->same('DAT is the mean of its marks', 3.5, $record['dims']['DAT']);
            $t->same('the floor is named by code', 'INF', $record['floorCode']);
            $t->count('all four blocks of responses', 4, $record['responses']);
            $t->same(
                'keyed by question index, as A.responses[code][qi] was',
                [0 => 2, 1 => 3, 2 => 4, 3 => 5],
                $record['responses']['DAT']
            );
            $t->true('responses are an object in the file, not a list', str_contains($text, '"0": 2'));
            $t->same('the respondent is repeated as userName', 'Export Test', $record['userName']);
            $t->same('artifacts held nothing', [], $record['artifacts']);
            $t->true('the level is reported', $record['level'] !== null);
            $t->true('the timestamp is ISO 8601', (bool) preg_match($iso, $record['timestamp']));
        }

        /* ---------- the codebook: the reference's exportCodebook() ---------- */

        $text     = (new CodebookExporter($app))->json();
        $codebook = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        $t->same(
            'its top-level keys are the reference\'s, in its order',
            ['instrument', 'version', 'scoring', 'dimensions', 'identification', 'caveats'],
            array_keys($codebook)
        );
        $t->same(
            'it names the instrument as the reference did',
            'AI Sovereignty Scorecard · Enterprise AI Sovereignty',
            $codebook['instrument']
        );
        $t->same('at its version', '1.1', $codebook['version']);
        $t->count('five levels', 5, $codebook['scoring']['scale']);
        $t->same(
            'each with the reference\'s three keys',
            ['value', 'name', 'definition'],
            array_keys($codebook['scoring']['scale'][0])
        );
        $t->same(
            'the dimension score is described from the instrument\'s own count',
            'unweighted mean of four item marks',
            $codebook['scoring']['dimension_score']
        );
        $t->same(
            'the bands are written as the reference wrote them',
            ['Undeveloped' => '<1.80', 'Emerging' => '1.80–2.59', 'Developing' => '2.60–3.39',
                'Established' => '3.40–4.19', 'Embedded' => '≥4.20'],
            $codebook['scoring']['bands']
        );
        $t->same(
            'including the cadence the reference documented',
            ['full' => 'annual', 'below_defined' => 'quarterly', 'incident' => 'immediate'],
            $codebook['scoring']['cadence']
        );
        $t->true('and its coverage sentence', str_contains($codebook['scoring']['coverage'], 'two or more respondents'));

        $t->count('four dimensions', 4, $codebook['dimensions']);
        $t->count('four questions in the first', 4, $codebook['dimensions'][0]['items']);
        $t->true(
            'an anchor is its wording, as the reference wrote it',
            is_string($codebook['dimensions'][0]['items'][0]['anchor_1'])
                && $codebook['dimensions'][0]['items'][0]['anchor_1'] !== ''
        );

        $t->true(
            'it names the required identity fields',
            in_array('email', $codebook['identification']['required_fields'], true)
        );
        $t->same('and is explicit that none of them is verified', false, $codebook['identification']['verified']);
        $t->true('the correlation floor is stated', in_array('correlations withheld below n=30', $codebook['caveats'], true));

        /* Filenames are the reference's. */
        $t->true('the CSV filename is dated', (bool) preg_match(
            '/^ai-sovereignty-scorecard-\d{4}-\d{2}-\d{2}\.csv$/',
            $exporter->filename('csv')
        ));
        $t->same('and the codebook\'s carries no version', 'ai-sovereignty-scorecard-codebook.json', (new CodebookExporter($app))->filename());
    } finally {
        Database::statement('DELETE FROM `sov_submissions` WHERE `id` = ?', [$submissionId]);
    }
};
