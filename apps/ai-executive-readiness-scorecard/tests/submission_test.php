<?php

declare(strict_types=1);

use Apps\AiExecutiveReadinessScorecard\Services\InstrumentData;
use Apps\AiExecutiveReadinessScorecard\Services\Records;
use Apps\AiExecutiveReadinessScorecard\Services\SubmissionService;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * A questionnaire's life through the service: start, save ratings by scope,
 * the completeness rule, submit, the stored summary, the result payload, the
 * record, and "Start over".
 *
 * The dev database is shared with a browser, so everything this creates is
 * found by its own test address and removed in `finally` — never a table wipe.
 */
return static function (Assert $t): void {
    $app     = AppRegistry::get('ai-executive-readiness-scorecard');
    $service = new SubmissionService($app);
    $email   = 'submission-test@raix-tests.org';
    $table   = $app->table('submissions');

    InstrumentData::flush();
    Records::flush();

    $profile = [
        'respondent_name'   => 'Test Respondent',
        'respondent_email'  => $email,
        'respondent_phone'  => '+1 415 555 2671',
        'organisation_name' => 'Submission Test Organization EXR',
        'organisation_size' => '51–250',
        'assessment_year'   => (int) date('Y'),
        'consent'           => true,
    ];

    try {
        $started    = $service->start($profile);
        $submission = $service->find($started['id']);

        $t->same('starts in progress', 'in_progress', $submission['status']);
        $t->true('consent recorded', $submission['consent_agreed_at'] !== null && $submission['consent_terms_url'] === 'https://shadoka.com/terms/');

        $data  = new InstrumentData($app);
        $items = [];
        foreach ($data->dimensions((int) $submission['instrument_id']) as $d => $dimension) {
            foreach ($dimension['items'] as $i => $item) {
                $items[] = ['id' => (int) $item['id'], 'self' => min(5, $d + $i + 1), 'leadership' => 3];
            }
        }

        // Self only: every dimension is still missing its leadership ratings.
        $saved = $service->saveProgress($submission, array_map(
            static fn (array $i): array => ['item_id' => $i['id'], 'scope' => 'self', 'mark' => $i['self']],
            $items
        ));
        $t->same('twelve self ratings saved', 12, $saved['saved']);
        $t->same('every dimension still incomplete', ['JDG', 'FLU', 'STR', 'CHG'], $service->missingDimensions($submission));
        $t->same('submit refuses a half questionnaire', ['JDG', 'FLU', 'STR', 'CHG'], $service->submit($submission)['missing']);

        // Junk is ignored, not stored.
        $junk = $service->saveProgress($submission, [
            ['item_id' => $items[0]['id'], 'scope' => 'board', 'mark' => 3],
            ['item_id' => $items[0]['id'], 'scope' => 'leadership', 'mark' => 6],
            ['item_id' => 999999, 'scope' => 'leadership', 'mark' => 3],
            ['item_id' => $items[0]['id'], 'scope' => 'leadership', 'mark' => '2.5'],
        ]);
        $t->same('an unknown scope, a 6, a foreign item and a fraction are all ignored', 0, $junk['saved']);

        $service->saveProgress($submission, array_map(
            static fn (array $i): array => ['item_id' => $i['id'], 'scope' => 'leadership', 'mark' => $i['leadership']],
            $items
        ));
        $t->same('both scopes answered: complete', [], $service->missingDimensions($submission));

        // A cleared rating deletes its row, so the questionnaire is incomplete again.
        $service->saveProgress($submission, [['item_id' => $items[5]['id'], 'scope' => 'leadership', 'mark' => null]]);
        $t->same('clearing one rating reopens its dimension', ['FLU'], $service->missingDimensions($submission));
        $service->saveProgress($submission, [['item_id' => $items[5]['id'], 'scope' => 'leadership', 'mark' => 3]]);

        $t->same('submit succeeds', [], $service->submit($submission)['missing']);

        $row = $service->find($started['id']);
        // self: JDG 1,2,3 · FLU 2,3,4 · STR 3,4,5 · CHG 4,5,5 → 41/12
        $t->same('stored self mean', '3.4167', $row['self_mean']);
        $t->same('stored self level (Established)', 4, (int) $row['self_level']);
        $t->same('stored leadership level (Developing)', 3, (int) $row['leadership_level']);
        $t->same('stored difference', '0.4167', $row['difference_mean']);
        $t->same('eight dimension score rows (4 × 2 scopes)', 8, (int) Database::scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `submission_id` = ?', $app->table('submission_scores')),
            [$row['id']]
        ));

        $result = $service->result($row);
        $t->same('result: self level', 'Established', $result['scores']['self']['level']['name']);
        $t->same('result: leaders floor is the first tied dimension', 'JDG', $result['scores']['leadership']['floorCode']);
        $t->same('result: 24 answers for Review', 24, count($result['answers']));
        $t->same('result: blank sector reads Not specified', 'Not specified', $result['sector']);
        $t->same('result: disclaimer from the instrument', true, str_starts_with((string) $result['text']['disclaimer'], 'This scorecard is a self-assessment.'));

        Records::flush();
        $record = (new Records($app))->find((int) $row['id']);
        $t->same('record id shape', 'exr-' . $row['id'], $record['id']);
        $t->same('record responses by scope, code and index', 5, $record['responses']['self']['CHG'][2]);
        $t->same('record level is a name', 'Developing', $record['scores']['leadership']['level']);
        $t->true('record carries consent', ($record['org']['consent']['agreed'] ?? false) === true);
        $t->same('record NULL sector stays NULL in the row', null, $row['sector']);

        $exported = Records::export($record);
        $t->true('export drops internal keys', !array_key_exists('_id', $exported));
        $t->same('export writes ratings as an object', '{"0":4,"1":5,"2":5}', json_encode($exported['responses']['self']['CHG']));

        $t->true('a finished questionnaire cannot be discarded', !$service->discard($row));

        // "Start over": an unfinished draft is deleted, with its ratings.
        $draft = $service->start($profile);
        $service->saveProgress($service->find($draft['id']), [['item_id' => $items[0]['id'], 'scope' => 'self', 'mark' => 2]]);
        $t->true('a draft is discarded', $service->discard($service->find($draft['id'])));
        $t->same('…and is gone', null, $service->find($draft['id']));
        $t->same('…with its ratings', 0, (int) Database::scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `submission_id` = ?', $app->table('submission_responses')),
            [$draft['id']]
        ));
    } finally {
        $ids = array_column(Database::select(sprintf('SELECT `id` FROM `%s` WHERE `respondent_email` = ?', $table), [$email]), 'id');
        Database::statement(sprintf('DELETE FROM `%s` WHERE `respondent_email` = ?', $table), [$email]);
        Database::statement(
            "DELETE FROM `organisations` WHERE `name` = ? AND NOT EXISTS (SELECT 1 FROM `{$table}` s WHERE s.`organisation_id` = `organisations`.`id`)",
            ['Submission Test Organization EXR']
        );
        Records::flush();
        $t->same('test rows removed', 0, (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s` WHERE `respondent_email` = ?', $table), [$email]));
        unset($ids);
    }
};
