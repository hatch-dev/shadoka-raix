<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecard\Services\SubmissionService;
use Platform\Assessment\OrganisationResolver;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * A scorecard's whole life, through the service layer.
 *
 * Creates its own submissions and organisation and removes them again. It never
 * truncates a table — this database is shared with a running site.
 */
return static function (Assert $t): void {
    $app     = AppRegistry::get('raix-director-scorecard');
    $service = new SubmissionService($app);

    $boardName = 'Probe ' . bin2hex(random_bytes(4)) . ' Capital Group';
    $tokens    = [];
    $orgIds    = [];

    try {
        /* ---------- match_key normalisation ---------- */

        $t->same(
            'match key lowercases, drops punctuation and collapses spaces',
            'meridian capital group',
            OrganisationResolver::matchKey('  Meridian,  Capital   Group.  ')
        );
        $t->same(
            'a company suffix is NOT stripped (two real entities must stay two rows)',
            'apex systems ltd',
            OrganisationResolver::matchKey('Apex Systems Ltd')
        );
        $t->same('an empty name yields an empty key', '', OrganisationResolver::matchKey('   '));

        /* ---------- starting ---------- */

        $started = $service->start([
            'board_name'      => $boardName,
            'director_name'   => 'Test Director',
            'seat_role'       => 'Risk Committee Chair',
            'tenure'          => '4–6 years',
            'sector'          => 'Financial Services',
            'company_size'    => '1,001–5,000',
            'assessment_year' => (int) date('Y'),
            'board_seats'     => 9,
            'agenda_meetings' => 3,
            'education_hours' => 6.5,
            'remit'           => ['RIS'],
        ], hash('sha256', 'probe'));

        $tokens[] = $started['token'];

        $t->true('start returns a 32-char hex token', (bool) preg_match('/^[a-f0-9]{32}$/', $started['token']));

        $submission = $service->findByToken($started['token']);
        $t->true('the submission can be reopened by its token', $submission !== null);
        $t->same('it starts in progress', 'in_progress', $submission['status']);
        $t->same('it pinned an instrument version', true, $submission['instrument_id'] !== null);

        $orgIds[] = (int) $submission['organisation_id'];
        $t->true('a platform-level organisation was created', $submission['organisation_id'] !== null);

        /* ---------- the same board typed differently reuses ONE row ---------- */

        $again = $service->start([
            'board_name'      => '  ' . strtoupper($boardName) . ' . ',
            'assessment_year' => (int) date('Y'),
        ]);
        $tokens[] = $again['token'];

        $second = $service->findByToken($again['token']);
        $t->same(
            'the same board typed differently maps to the same organisation',
            (int) $submission['organisation_id'],
            (int) $second['organisation_id']
        );

        /* ---------- a bad token never resolves ---------- */

        $t->same('a malformed token is refused', null, $service->findByToken('not-a-token'));
        $t->same('an unknown token is refused', null, $service->findByToken(str_repeat('a', 32)));

        /* ---------- saving marks ---------- */

        $items = Database::select(
            'SELECT i.`id`, c.`code`, i.`position`
               FROM `ds_items` i
               JOIN `ds_competencies` c ON c.`id` = i.`competency_id`
              WHERE c.`instrument_id` = ?
           ORDER BY c.`position`, i.`position`',
            [$submission['instrument_id']]
        );
        $t->count('35 items to answer', 35, $items);

        $marks = ['GOV' => 4, 'DAT' => 4, 'EXP' => 3, 'FAI' => 3, 'RIS' => 4, 'HUM' => 3, 'AUT' => 1];

        // Save the first competency only, to prove partial saving works.
        $firstFive = array_slice($items, 0, 5);
        $saved = $service->saveProgress($submission, array_map(
            static fn (array $i): array => [
                'item_id'       => (int) $i['id'],
                'mark'          => 4,
                'evidence_note' => 'Board minutes Mar 12, item 4',
            ],
            $firstFive
        ));

        $t->same('five marks saved', 5, $saved['saved']);
        $t->count('six competencies still incomplete', 6, $service->missingCompetencies($submission));

        /* ---------- an item from outside the instrument is ignored ---------- */

        $rejected = $service->saveProgress($submission, [
            ['item_id' => 999999, 'mark' => 4],
        ]);
        $t->same('an unknown item id is silently dropped, not stored', 0, $rejected['saved']);

        /* ---------- an out-of-range mark is ignored ---------- */

        $outOfRange = $service->saveProgress($submission, [
            ['item_id' => (int) $items[0]['id'], 'mark' => 9],
        ]);
        $t->same('a mark outside 1-4 is refused', 0, $outOfRange['saved']);
        $t->same(
            'and the existing mark is untouched',
            4,
            $service->responses((int) $submission['id'])[(int) $items[0]['id']]['mark']
        );

        /* ---------- submitting while incomplete ---------- */

        $tooEarly = $service->submit($submission);
        $t->true('an incomplete scorecard will not submit', $tooEarly['missing'] !== []);
        $t->true('and it names what is missing', in_array('AUT', $tooEarly['missing'], true));

        $stillOpen = $service->findByToken($started['token']);
        $t->same('it is still in progress', 'in_progress', $stillOpen['status']);

        /* ---------- answer everything, then submit ---------- */

        $service->saveProgress($submission, array_map(
            static fn (array $i): array => ['item_id' => (int) $i['id'], 'mark' => $marks[$i['code']]],
            $items
        ));

        $t->count('nothing incomplete now', 0, $service->missingCompetencies($submission));

        $outcome = $service->submit($submission);
        $t->same('submitted cleanly', [], $outcome['missing']);
        $t->same('server-derived floor', 1.0, $outcome['summary']->headline);
        $t->same('server-derived mean', 3.14, $outcome['summary']->mean);

        $complete = $service->findByToken($started['token']);
        $t->same('status is now complete', 'complete', $complete['status']);
        $t->true('submitted_at was stamped', $complete['submitted_at'] !== null);

        /* ---------- the result payload ---------- */

        $result = $service->result($complete);

        $t->same('result: level is Unaware, set by the floor', 'Unaware', $result['level']['name'] ?? null);
        $t->same('result: floor', 1.0, $result['floor']);
        $t->same('result: mean', 3.14, $result['mean']);
        $t->same('result: the spread between them', 2.14, $result['spread']);
        $t->same('result: board name resolved from the platform table', $boardName, $result['board']);
        $t->count('result: seven competencies', 7, $result['competencies']);
        $t->same('result: all seven scored', 7, $result['scored_count']);

        /* ---------- accepting the result ---------- */

        // Submitting is not accepting. "Save this scorecard" is what records
        // that the director looked at the result and stood behind it, and a
        // scorecard is perfectly valid without it.
        $t->same('a submitted scorecard is not yet accepted', null, $result['confirmed_at']);

        $confirmed = $service->confirm($complete);

        $t->true('confirming stamps a time', is_string($confirmed['confirmed_at']));
        $t->same('and reports it as the first acceptance', false, $confirmed['already_confirmed']);
        $t->same(
            'the stamp reaches the database',
            $confirmed['confirmed_at'],
            $service->findByToken($started['token'])['confirmed_at']
        );
        $t->same(
            'and the result payload reports it',
            $confirmed['confirmed_at'],
            $service->result($service->findByToken($started['token']))['confirmed_at']
        );

        // Board context travels to the next scorecard; the seat does not,
        // because scoring another seat means scoring a different one.
        $t->same(
            'only board context is carried forward',
            ['sector', 'company_size', 'assessment_year'],
            array_keys($confirmed['carry_forward'])
        );
        $t->same('carried sector', 'Financial Services', $confirmed['carry_forward']['sector']);
        $t->same('carried company size', '1,001–5,000', $confirmed['carry_forward']['company_size']);
        $t->same('carried year', (int) date('Y'), $confirmed['carry_forward']['assessment_year']);

        // A second press is not a second acceptance: the column records when
        // the director first stood behind this result.
        $again = $service->confirm($service->findByToken($started['token']));
        $t->true('a second acceptance is reported as already done', $again['already_confirmed']);
        $t->same('and does not move the timestamp', $confirmed['confirmed_at'], $again['confirmed_at']);

        $floor = array_values(array_filter($result['competencies'], static fn (array $c): bool => $c['is_floor']));
        $t->count('result: exactly one competency is tagged as the floor', 1, $floor);
        $t->same('result: the floor is AUT', 'AUT', $floor[0]['code'] ?? null);

        $t->true('result: the floor narrative is present', is_string($result['floor_narrative']));
        $t->true(
            'result: the narrative has its {floor} placeholder filled in',
            is_string($result['floor_narrative']) && !str_contains($result['floor_narrative'], '{floor}')
        );
        $t->true(
            'result: the narrative quotes the actual floor score',
            is_string($result['floor_narrative']) && str_contains($result['floor_narrative'], '1.00')
        );

        /* ---------- cadence ---------- */

        $t->true('cadence: annual re-score date set', is_string($result['cadence']['full']['due']));
        $t->true(
            'cadence: AUT is flagged for a sooner re-score',
            in_array('AUT', $result['cadence']['sooner']['competencies'], true)
        );
        $t->true(
            'cadence: GOV (4.00) is NOT flagged',
            !in_array('GOV', $result['cadence']['sooner']['competencies'], true)
        );
        $t->same('cadence: incident re-score is immediate', 'immediately', $result['cadence']['on_incident']['due']);

        /* ---------- commitments ---------- */

        $t->count('three commitments offered', 3, $result['commitments']);
        $t->true('none checked yet', !$result['commitments'][0]['checked']);

        $service->setCommitments((int) $complete['id'], ['register' => true, 'not_a_real_key' => true]);
        $after = $service->commitments((int) $complete['id']);

        $checked = array_values(array_filter($after, static fn (array $c): bool => $c['checked']));
        $t->count('one commitment checked', 1, $checked);
        $t->same('and it is the right one', 'register', $checked[0]['key']);
        $t->same(
            'an unknown commitment key was not stored',
            0,
            (int) Database::scalar(
                'SELECT COUNT(*) FROM `ds_submission_commitments` WHERE `submission_id` = ? AND `commitment_key` = ?',
                [$complete['id'], 'not_a_real_key']
            )
        );

        /* ---------- editing after submission rescores ---------- */

        $autItems = array_values(array_filter($items, static fn (array $i): bool => $i['code'] === 'AUT'));
        $service->saveProgress($complete, array_map(
            static fn (array $i): array => ['item_id' => (int) $i['id'], 'mark' => 4],
            $autItems
        ));
        $service->submit($service->findByToken($started['token']));

        $rescored = $service->result($service->findByToken($started['token']));
        $t->same('raising the floor competency raises the level', 'Practiced', $rescored['level']['name'] ?? null);
        $t->same('and the floor moves to the next weakest', 3.0, $rescored['floor']);
        $t->true(
            'the floor is now one of the 3.00 competencies',
            in_array(
                array_values(array_filter($rescored['competencies'], static fn (array $c): bool => $c['is_floor']))[0]['code'] ?? '',
                ['EXP', 'FAI', 'HUM'],
                true
            )
        );
    } finally {
        foreach ($tokens as $token) {
            Database::statement('DELETE FROM `ds_submissions` WHERE `public_token` = ?', [$token]);
        }
        foreach (array_unique($orgIds) as $orgId) {
            // Only if nothing else references it — never remove a board another
            // submission still points at.
            $refs = (int) Database::scalar('SELECT COUNT(*) FROM `ds_submissions` WHERE `organisation_id` = ?', [$orgId]);
            if ($refs === 0) {
                Database::statement('DELETE FROM `organisations` WHERE `id` = ?', [$orgId]);
            }
        }
    }

    $t->same(
        'probe submissions cleaned up',
        0,
        (int) Database::scalar('SELECT COUNT(*) FROM `ds_submissions` WHERE `director_name` = ?', ['Test Director'])
    );
};
