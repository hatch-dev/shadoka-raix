<?php

declare(strict_types=1);

use Apps\EnterpriseAiScorecard\Services\SubmissionService;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * A scorecard's life: start, save, submit, accept.
 *
 * The contract this protects is the one the reference build did not have: the
 * browser sends MARKS and nothing else. It cannot assert a score, a floor or a
 * level, and it cannot attach an answer to a question from another instrument.
 *
 * Everything created here is removed again in a finally. Nothing truncates a
 * table: this database is shared with a running site.
 */
return static function (Assert $t): void {
    $app     = AppRegistry::get('enterprise-ai-scorecard');
    $service = new SubmissionService($app);

    $instrument   = $service->publishedInstrument();
    $instrumentId = (int) $instrument['id'];

    $dimensions = Database::select(
        'SELECT `id`, `code` FROM `es_dimensions` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrumentId]
    );

    $itemsByDimension = [];
    foreach ($dimensions as $dimension) {
        $itemsByDimension[$dimension['code']] = array_column(Database::select(
            'SELECT `id` FROM `es_items` WHERE `dimension_id` = ? ORDER BY `position`',
            [$dimension['id']]
        ), 'id');
    }

    $started = $service->start([
        'respondent_name'   => 'Submission Test',
        'respondent_email'  => 'submission.test@northgate-systems.com',
        'respondent_phone'  => '+1 415 555 0177',
        'organisation_name' => 'Submission Test Organisation',
        'organisation_size' => '251–1,000',
        'role'              => 'Chief Information Officer',
        'tenure'            => '4–6 years',
        'sector'            => 'Technology',
        'assessment_year'   => (int) date('Y'),
        'systems_inventory' => 17,
        'agenda_meetings'   => 4,
        'education_hours'   => 9.5,
        'consent'           => true,
    ], hash('sha256', 'test'));

    $submissionId = $started['id'];

    try {
        /* ---------- what start() wrote ---------- */

        $t->true('the token is 32 hex characters', (bool) preg_match('/^[a-f0-9]{32}$/', $started['token']));

        $row = Database::selectOne('SELECT * FROM `es_submissions` WHERE `id` = ?', [$submissionId]);

        $t->same('it starts in progress', 'in_progress', $row['status']);
        $t->same('it is not synthetic', 0, (int) $row['is_synthetic']);
        $t->same('the typed organisation name is kept on the submission', 'Submission Test Organisation', $row['organisation_name']);
        $t->true('and resolved onto the shared organisations table', $row['organisation_id'] !== null);
        $t->same('no score exists before submitting', null, $row['mean_score']);
        $t->same('and no level', null, $row['level_value']);

        /*
         * Consent is a stored fact, not a checkbox that enabled a button. The
         * URL travels with the timestamp so a later revision of the terms cannot
         * be read back onto a consent given under the old ones.
         */
        $t->true('consent is timestamped', $row['consent_agreed_at'] !== null);
        $t->same(
            'and records which terms were shown',
            $app->config('result.consent.terms_url'),
            $row['consent_terms_url']
        );

        /* The shared organisation row carries the sector and size for joining. */
        $organisation = Database::selectOne(
            'SELECT * FROM `organisations` WHERE `id` = ?',
            [$row['organisation_id']]
        );
        $t->same('the organisation row took the sector', 'Technology', $organisation['sector']);

        /* ---------- Back to step 0, and edit ---------- */

        /*
         * Pressing Back rewrites the record that exists. It must NOT open a
         * second one: the marks already saved hang off this id, and a new token
         * would strand them and leave two rows for one respondent in the dataset.
         */
        $before = (int) Database::scalar('SELECT COUNT(*) FROM `es_submissions`');

        $service->updateProfile($row, [
            'respondent_name'   => 'Submission Test',
            'respondent_email'  => 'submission.test@northgate-systems.com',
            'respondent_phone'  => '+353 86 555 0177',
            'organisation_name' => 'Submission Test Organisation Ltd',
            'organisation_size' => '251–1,000',
            'role'              => 'Chief Executive Officer',
            'tenure'            => 'Under 1 year',
            'sector'            => 'Technology',
            'assessment_year'   => (int) date('Y'),
            'systems_inventory' => 9,
            'agenda_meetings'   => null,
            'education_hours'   => null,
        ]);

        $edited = Database::selectOne('SELECT * FROM `es_submissions` WHERE `id` = ?', [$submissionId]);

        $t->same(
            'editing the profile adds no second submission',
            $before,
            (int) Database::scalar('SELECT COUNT(*) FROM `es_submissions`')
        );
        $t->same('the token is unchanged', $row['public_token'], $edited['public_token']);
        $t->same('the edit landed', 'Submission Test Organisation Ltd', $edited['organisation_name']);
        $t->same('and so did a changed optional field', '+353 86 555 0177', $edited['respondent_phone']);
        $t->same('and a changed number', 9, (int) $edited['systems_inventory']);
        $t->true('the organisation is re-resolved', $edited['organisation_id'] !== null);

        /*
         * Consent is not re-given by an edit. The columns record an acceptance
         * that already happened, at a URL that was already shown.
         */
        $t->same('consent is not re-stamped', $row['consent_agreed_at'], $edited['consent_agreed_at']);
        $t->same('nor are the terms rewritten', $row['consent_terms_url'], $edited['consent_terms_url']);
        $t->same('it is still not synthetic', 0, (int) $edited['is_synthetic']);
        $t->same('and still in progress', 'in_progress', $edited['status']);

        /* Put it back, so the assertions below read the profile they were written for. */
        $service->updateProfile($edited, [
            'respondent_name'   => 'Submission Test',
            'respondent_email'  => 'submission.test@northgate-systems.com',
            'respondent_phone'  => '+1 415 555 0177',
            'organisation_name' => 'Submission Test Organisation',
            'organisation_size' => '251–1,000',
            'role'              => null,
            'tenure'            => null,
            'sector'            => 'Technology',
            'assessment_year'   => (int) date('Y'),
            'systems_inventory' => null,
            'agenda_meetings'   => null,
            'education_hours'   => null,
        ]);

        $row = Database::selectOne('SELECT * FROM `es_submissions` WHERE `id` = ?', [$submissionId]);

        /* ---------- saving marks ---------- */

        $t->count('nothing is answered yet', 6, $service->missingDimensions($row));

        $first = $itemsByDimension[$dimensions[0]['code']];

        $saved = $service->saveProgress($row, [
            ['item_id' => $first[0], 'mark' => 4],
            ['item_id' => $first[1], 'mark' => 5],
        ]);

        $t->same('two marks saved', 2, $saved['saved']);
        $t->count('and are readable back', 2, $service->responses($submissionId));

        /* A mark outside 1–5 is discarded rather than stored. */
        $t->same(
            'a mark of 6 is refused',
            0,
            $service->saveProgress($row, [['item_id' => $first[2], 'mark' => 6]])['saved']
        );
        $t->same(
            'a mark of 0 is refused',
            0,
            $service->saveProgress($row, [['item_id' => $first[2], 'mark' => 0]])['saved']
        );

        /*
         * Five points, not four. The sibling instrument's range is 1–4, and
         * copying its bound here would silently discard every 5 — which would
         * look like respondents never answering at the top of the scale.
         */
        $t->same(
            'a mark of 5 is accepted',
            1,
            $service->saveProgress($row, [['item_id' => $first[2], 'mark' => 5]])['saved']
        );

        /*
         * A question that is not this instrument's must never attach.
         *
         * Ids are per-table, so `ds_items` id 1 and `es_items` id 1 both exist
         * and are unrelated — posting the sibling app's id 1 is accepted, and
         * correctly so: it names a real question of THIS instrument. That is a
         * numeric coincidence, not a leak, and asserting otherwise would be
         * asserting the wrong property.
         *
         * What has to hold is that the set of ids saveProgress() will accept is
         * exactly this instrument's thirty. So: take an id that exists in the
         * sibling's table and NOT in this one, and check it is refused.
         */
        $foreign = Database::scalar(
            'SELECT `id` FROM `ds_items` WHERE `id` NOT IN (SELECT `id` FROM `es_items`) ORDER BY `id` LIMIT 1'
        );

        if ($foreign !== null) {
            $t->same(
                'an item id that belongs only to the sibling app is ignored',
                0,
                $service->saveProgress($row, [['item_id' => (int) $foreign, 'mark' => 3]])['saved']
            );
        }

        $t->same(
            'an item id that does not exist anywhere is ignored',
            0,
            $service->saveProgress($row, [['item_id' => 999999999, 'mark' => 3]])['saved']
        );

        $t->same(
            'and nothing was attached by either attempt',
            3,
            (int) Database::scalar(
                'SELECT COUNT(*) FROM `es_submission_responses` WHERE `submission_id` = ?',
                [$submissionId]
            )
        );

        /* A cleared mark deletes its row rather than storing a zero. */
        $t->same(
            'clearing a mark removes it',
            1,
            $service->saveProgress($row, [['item_id' => $first[2], 'mark' => null]])['cleared']
        );

        /* ---------- submitting is refused while anything is unanswered ---------- */

        $incomplete = $service->submit($service->find($submissionId));

        /*
         * All six, not five: the first dimension has two of its five questions
         * answered after the clear above, and a partly-answered dimension is
         * still missing. That is the rule — every question, because an average
         * over eleven answers is not comparable with an average over thirty.
         */
        $t->count('every dimension is still missing', 6, $incomplete['missing']);
        $t->same('the first is missing despite having some answers', $dimensions[0]['code'], $incomplete['missing'][0]);
        $t->same(
            'and nothing was marked complete',
            'in_progress',
            $service->find($submissionId)['status']
        );
        $t->same('nor scored', null, $service->find($submissionId)['mean_score']);

        /* ---------- answer everything, and submit ---------- */

        $marks = [4, 4, 3, 3, 2, 2];
        $responses = [];

        foreach ($dimensions as $index => $dimension) {
            foreach ($itemsByDimension[$dimension['code']] as $itemId) {
                $responses[] = ['item_id' => $itemId, 'mark' => $marks[$index]];
            }
        }

        $service->saveProgress($service->find($submissionId), $responses);

        $outcome = $service->submit($service->find($submissionId));

        $t->same('nothing is missing now', [], $outcome['missing']);
        $t->same('and it scores to 3.00', 3.0, $outcome['summary']->mean);

        $scored = $service->find($submissionId);

        $t->same('it is complete', 'complete', $scored['status']);
        $t->true('and stamped', $scored['submitted_at'] !== null);

        /* ---------- the result payload ---------- */

        $result = $service->result($scored);

        $t->same('the result reports the average', 3.0, $result['mean']);
        $t->same('the level is banded from it', 'Developing', $result['level']['name']);
        $t->same('the floor is the weakest dimension', 2.0, $result['floor']);
        $t->same('and names it', $dimensions[4]['code'], $result['floor_code']);
        $t->same('the gap is the average above the floor', 1.0, $result['spread']);
        $t->count('six dimensions are reported', 6, $result['dimensions']);
        $t->same('all six were scored', 6, $result['scored_count']);
        $t->same('and none is unscored', [], $result['unscored']);

        $flagged = array_values(array_filter(
            $result['dimensions'],
            static fn (array $d): bool => $d['is_floor']
        ));
        $t->count('exactly one dimension is tagged as the floor', 1, $flagged);

        /* The gauge reads its arcs off the instrument, not off a constant. */
        $t->same('the band edges travel with the result', [1.8, 2.6, 3.4, 4.2], $result['band_edges']);
        $t->count('and the five levels', 5, $result['rungs']);

        /* The closing read names the weakest dimension and the question to press. */
        $t->true(
            'the reading names the weakest dimension',
            str_contains((string) $result['floor_narrative'], $result['floor_name'])
        );
        $t->true(
            'and carries the instrument\'s own question to press',
            str_contains((string) $result['floor_narrative'], 'question to press')
        );

        /* Its own marks come back with it, so "Review answers" needs no second call. */
        $t->count('all thirty marks travel with the result', 30, $result['responses']);

        /* ---------- accepting the result ---------- */

        $t->same('it is not accepted yet', null, $result['confirmed_at']);

        $confirmed = $service->confirm($scored);

        $t->true('accepting stamps it', $confirmed['confirmed_at'] !== '');
        $t->true('and says it was not already accepted', !$confirmed['already_confirmed']);

        $accepted = $service->find($submissionId);
        $again    = $service->confirm($accepted);

        $t->true('a second press is recognised', $again['already_confirmed']);
        $t->same(
            'and does not move the timestamp',
            $accepted['confirmed_at'],
            $service->find($submissionId)['confirmed_at']
        );

        /*
         * What the next scorecard may start from. The identity fields are
         * deliberately NOT among them: scoring another organisation means a
         * different organisation and usually a different person.
         */
        $carry = $again['carry_forward'];

        $t->same('sector carries forward', 'Technology', $carry['sector']);
        $t->same('size carries forward', '251–1,000', $carry['organisation_size']);
        $t->same('year carries forward', (int) date('Y'), $carry['assessment_year']);
        $t->true('the respondent does not', !array_key_exists('respondent_name', $carry));
        $t->true('nor the organisation name', !array_key_exists('organisation_name', $carry));

        /* ---------- the token is the only credential ---------- */

        $t->true('a valid token resolves', $service->findByToken($started['token']) !== null);
        $t->same('a malformed token does not', null, $service->findByToken('not-a-token'));
        $t->same('nor a plausible but wrong one', null, $service->findByToken(str_repeat('a', 32)));
        $t->same('nor a numeric id', null, $service->findByToken((string) $submissionId));
    } finally {
        $organisationId = Database::scalar(
            'SELECT `organisation_id` FROM `es_submissions` WHERE `id` = ?',
            [$submissionId]
        );

        Database::statement('DELETE FROM `es_submissions` WHERE `id` = ?', [$submissionId]);

        // Only if nothing else points at it — the resolver deduplicates, so this
        // row may belong to a record that was here before this test ran.
        if ($organisationId !== null) {
            Database::statement(
                'DELETE FROM `organisations`
                  WHERE `id` = ?
                    AND `id` NOT IN (SELECT DISTINCT `organisation_id` FROM `es_submissions` WHERE `organisation_id` IS NOT NULL)
                    AND `id` NOT IN (SELECT DISTINCT `organisation_id` FROM `ds_submissions` WHERE `organisation_id` IS NOT NULL)',
                [$organisationId]
            );
        }
    }
};
