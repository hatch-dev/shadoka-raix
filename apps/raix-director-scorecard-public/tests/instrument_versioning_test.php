<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecardPublic\Services\InstrumentVersions;
use Apps\RaixDirectorScorecardPublic\Services\SubmissionService;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * Instrument versioning.
 *
 * This is the feature that can quietly falsify a dataset, so the assertions
 * that matter most are the negative ones: that a published version cannot be
 * edited, and that a submission taken under v1.0 still reads v1.0's wording
 * after v1.1 has been published over it.
 *
 * Everything created here is removed afterwards.
 */
return static function (Assert $t): void {
    $app      = AppRegistry::get('raix-director-scorecard-public');
    $service  = new InstrumentVersions($app);
    $tokens   = [];
    $draftIds = [];

    $published = $service->published();
    $t->true('a published instrument exists to start from', $published !== null);
    if ($published === null) {
        return;
    }

    $originalId = (int) $published['id'];

    try {
        /* ---------- a published version refuses to be edited ---------- */

        $competency = Database::selectOne(
            'SELECT * FROM `dsp_competencies` WHERE `instrument_id` = ? ORDER BY `position` LIMIT 1',
            [$originalId]
        );

        $refused = null;
        try {
            $service->updateCompetency($originalId, (int) $competency['id'], ['name' => 'Tampered'], []);
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $t->true('editing a published version is refused', $refused !== null);
        $t->true(
            'and the refusal explains why',
            $refused !== null && str_contains($refused, 'pinned')
        );
        $t->same(
            'the published wording is untouched',
            $competency['name'],
            Database::scalar('SELECT `name` FROM `dsp_competencies` WHERE `id` = ?', [$competency['id']])
        );

        /* ---------- a submission against the published version ---------- */

        $submissions = new SubmissionService($app);
        $started     = $submissions->start([
            'board_name'      => 'Versioning Probe Ltd',
            'director_name'   => 'Version Probe',
            'director_email'  => 'probe@raix-tests.org',
            'director_phone'  => '+1 202 555 0147',
            'company_size'    => '51–250',
            'assessment_year' => (int) date('Y'),
        ]);
        $tokens[] = $started['token'];

        $submission = $submissions->findByToken($started['token']);
        $items      = Database::select(
            'SELECT i.`id`, c.`code` FROM `dsp_items` i
               JOIN `dsp_competencies` c ON c.`id` = i.`competency_id`
              WHERE c.`instrument_id` = ? ORDER BY c.`position`, i.`position`',
            [$originalId]
        );
        $marks = ['GOV' => 4, 'DAT' => 4, 'EXP' => 3, 'FAI' => 3, 'RIS' => 4, 'HUM' => 3, 'AUT' => 1];

        $submissions->saveProgress($submission, array_map(
            static fn (array $i): array => ['item_id' => (int) $i['id'], 'mark' => $marks[$i['code']]],
            $items
        ));
        $submissions->submit($submissions->findByToken($started['token']));

        $before = $submissions->result($submissions->findByToken($started['token']));
        $t->same('the submission scored against v1.0', 1.0, $before['floor']);

        /* ---------- drafting ---------- */

        $t->same('version suggestion increments the minor', '1.1', $service->suggestNextVersion('1.0'));
        $t->same('and handles a bare integer', '2.1', $service->suggestNextVersion('2'));

        $draftId    = $service->createDraft($originalId, '1.1-test');
        $draftIds[] = $draftId;

        $draft = $service->find($draftId);
        $t->same('the draft is a draft', 'draft', $draft['status']);
        $t->same('it has its own version', '1.1-test', $draft['version']);
        $t->same('it is not published', null, $draft['published_at']);

        // The clone must be complete, or publishing it would lose content.
        $t->same(
            'the draft cloned every competency',
            (int) Database::scalar('SELECT COUNT(*) FROM `dsp_competencies` WHERE `instrument_id` = ?', [$originalId]),
            (int) Database::scalar('SELECT COUNT(*) FROM `dsp_competencies` WHERE `instrument_id` = ?', [$draftId])
        );
        $t->same(
            'and every statement',
            35,
            (int) Database::scalar(
                'SELECT COUNT(*) FROM `dsp_items` i JOIN `dsp_competencies` c ON c.`id` = i.`competency_id`
                  WHERE c.`instrument_id` = ?',
                [$draftId]
            )
        );
        $t->same(
            'and every rung with its band cut points',
            4,
            (int) Database::scalar('SELECT COUNT(*) FROM `dsp_rungs` WHERE `instrument_id` = ?', [$draftId])
        );
        $t->same(
            'and the reference lists',
            33,
            (int) Database::scalar('SELECT COUNT(*) FROM `dsp_reference_options` WHERE `instrument_id` = ?', [$draftId])
        );

        /* ---------- only one draft at a time ---------- */

        $secondDraft = null;
        try {
            $service->createDraft($originalId, '1.2-test');
        } catch (RuntimeException $e) {
            $secondDraft = $e->getMessage();
        }
        $t->true('a second concurrent draft is refused', $secondDraft !== null);

        $duplicate = null;
        try {
            $service->discard($draftId);
            $draftIds   = [];
            $draftId    = $service->createDraft($originalId, (string) $published['version']);
            $draftIds[] = $draftId;
        } catch (RuntimeException $e) {
            $duplicate = $e->getMessage();
        }
        $t->true('reusing an existing version number is refused', $duplicate !== null);

        $draftId    = $service->createDraft($originalId, '1.1-test');
        $draftIds[] = $draftId;

        /* ---------- editing the draft ---------- */

        $draftCompetency = Database::selectOne(
            'SELECT * FROM `dsp_competencies` WHERE `instrument_id` = ? AND `code` = ?',
            [$draftId, 'GOV']
        );
        $draftItems = Database::select(
            'SELECT * FROM `dsp_items` WHERE `competency_id` = ? ORDER BY `position`',
            [$draftCompetency['id']]
        );

        $changes = $service->updateCompetency(
            $draftId,
            (int) $draftCompetency['id'],
            ['name' => 'Governance Oversight (revised)', 'board_question' => 'Who is accountable, and when did we last decide?'],
            [(int) $draftItems[0]['id'] => 'A reworded first statement for the revised version.']
        );

        $t->true('the edit reported what changed', count($changes) >= 3);
        $t->true('including the competency name', in_array('Name', $changes, true));
        $t->true('and the reworded statement', in_array('GOV 1', $changes, true));

        $t->same(
            'the draft holds the new wording',
            'Governance Oversight (revised)',
            Database::scalar('SELECT `name` FROM `dsp_competencies` WHERE `id` = ?', [$draftCompetency['id']])
        );

        /* ---------- THE POINT: the published version is unchanged ---------- */

        $t->same(
            'the PUBLISHED version still has its original wording',
            'Governance Oversight',
            Database::scalar(
                'SELECT `name` FROM `dsp_competencies` WHERE `instrument_id` = ? AND `code` = ?',
                [$originalId, 'GOV']
            )
        );

        $t->true(
            'and the original statement is untouched',
            str_starts_with(
                (string) Database::scalar('SELECT `statement` FROM `dsp_items` WHERE `id` = ?', [$items[0]['id']]),
                'Whenever an AI matter comes before the board'
            )
        );

        /* ---------- an empty statement is refused ---------- */

        $emptied = null;
        try {
            $service->updateCompetency($draftId, (int) $draftCompetency['id'], [], [(int) $draftItems[1]['id'] => '   ']);
        } catch (RuntimeException $e) {
            $emptied = $e->getMessage();
        }
        $t->true('an empty statement is refused', $emptied !== null);

        /* ---------- a competency from another version is refused ---------- */

        $crossVersion = null;
        try {
            $service->updateCompetency($draftId, (int) $competency['id'], ['name' => 'Cross-version'], []);
        } catch (RuntimeException $e) {
            $crossVersion = $e->getMessage();
        }
        $t->true('editing another version\'s competency through this draft is refused', $crossVersion !== null);

        /* ---------- publishing ---------- */

        $t->same('the draft validates', [], $service->validate($draftId));

        $result = $service->publish($draftId);
        $t->same('the draft became published', '1.1-test', $result['published']);
        $t->same('and the previous version was archived', (string) $published['version'], $result['archived']);

        $t->same(
            'the old version is archived, not deleted',
            'archived',
            Database::scalar('SELECT `status` FROM `dsp_instruments` WHERE `id` = ?', [$originalId])
        );
        $t->same('the new version is the published one', $draftId, (int) $service->published()['id']);

        /* ---------- THE OTHER POINT: the old submission is unaffected ---------- */

        $after = $submissions->result($submissions->findByToken($started['token']));

        $t->same('the existing submission still scores the same', $before['floor'], $after['floor']);
        $t->same('and reports the same level', $before['level']['name'], $after['level']['name']);
        $t->same(
            'and is still pinned to the version it was taken under',
            $originalId,
            (int) $submissions->findByToken($started['token'])['instrument_id']
        );
        $t->same(
            'so it still reads the ORIGINAL wording, not the revision',
            'Governance Oversight',
            $after['competencies'][0]['name']
        );

        /* ---------- a new submission uses the new version ---------- */

        $newStart = $submissions->start([
            'board_name'      => 'Versioning Probe Ltd',
            'director_name'   => 'Version Probe',
            'director_email'  => 'probe@raix-tests.org',
            'director_phone'  => '+1 202 555 0147',
            'company_size'    => '51–250',
            'assessment_year' => (int) date('Y'),
        ]);
        $tokens[] = $newStart['token'];

        $t->same(
            'a new scorecard is pinned to the newly published version',
            $draftId,
            (int) $submissions->findByToken($newStart['token'])['instrument_id']
        );

        /* ---------- a published version cannot be discarded ---------- */

        $discardPublished = null;
        try {
            $service->discard($draftId);
        } catch (RuntimeException $e) {
            $discardPublished = $e->getMessage();
        }
        $t->true('a published version cannot be discarded', $discardPublished !== null);
    } finally {
        foreach ($tokens as $token) {
            Database::statement('DELETE FROM `dsp_submissions` WHERE `public_token` = ?', [$token]);
        }

        // Restore the original as the published version, and remove test ones.
        Database::statement("UPDATE `dsp_instruments` SET `status` = 'published' WHERE `id` = ?", [$originalId]);

        foreach (array_unique($draftIds) as $id) {
            if ($id !== $originalId) {
                Database::statement('DELETE FROM `dsp_instruments` WHERE `id` = ?', [$id]);
            }
        }
        Database::statement("DELETE FROM `dsp_instruments` WHERE `version` LIKE '%-test'");

        Database::statement(
            'DELETE FROM `organisations` WHERE `name` = ? AND `id` NOT IN
             (SELECT DISTINCT `organisation_id` FROM `dsp_submissions` WHERE `organisation_id` IS NOT NULL)',
            ['Versioning Probe Ltd']
        );
    }

    $t->same(
        'only the original instrument remains published',
        $originalId,
        (int) Database::scalar("SELECT `id` FROM `dsp_instruments` WHERE `status` = 'published'")
    );
};
