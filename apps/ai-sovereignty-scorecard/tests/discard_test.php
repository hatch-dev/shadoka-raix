<?php

declare(strict_types=1);

use Apps\AiSovereigntyScorecard\Services\SubmissionService;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * "Start over" deletes the draft it abandons — and nothing else.
 *
 *  - An unfinished scorecard goes, with its marks, and the deletion is audited
 *    without the respondent's details.
 *  - A FINISHED scorecard is never deleted this way: it is research data.
 *  - The organisation row goes only when no scorecard in any app still uses it.
 *
 * Everything written here is removed in a finally. The audit rows stay: they
 * are the record, and nothing here deletes from `audit_log`.
 */
return static function (Assert $t): void {
    $app     = AppRegistry::get('ai-sovereignty-scorecard');
    $service = new SubmissionService($app);
    $ipHash  = hash('sha256', 'discard-test');

    $profile = static fn (string $organisation, string $email): array => [
        'respondent_name'   => 'Discard Test',
        'respondent_email'  => $email,
        'respondent_phone'  => '+1 415 555 0133',
        'organisation_name' => $organisation,
        'organisation_size' => '51–250',
        'assessment_year'   => (int) date('Y'),
        'consent'           => true,
    ];

    $itemIds = array_map('intval', array_column(Database::select(
        'SELECT i.`id` FROM `sov_items` i JOIN `sov_dimensions` d ON d.`id` = i.`dimension_id`
          WHERE d.`instrument_id` = ? ORDER BY d.`position`, i.`position`',
        [(int) $service->publishedInstrument()['id']]
    ), 'id'));

    $ids = [];

    try {
        /* ---------- an unfinished draft goes, with its marks ---------- */

        $draft = $service->start($profile('ZZ Discard Alone', 'discard.alone@northgate-systems.com'), $ipHash);
        $ids[] = $draft['id'];

        $row = $service->find($draft['id']);
        $organisationId = (int) $row['organisation_id'];
        $service->saveProgress($row, [['item_id' => $itemIds[0], 'mark' => 3], ['item_id' => $itemIds[1], 'mark' => 4]]);

        $t->true('the draft is discarded', $service->discard($row, $ipHash));
        $t->same('its row is gone', null, $service->find($draft['id']));
        $t->same(
            'and its marks with it',
            0,
            (int) Database::scalar('SELECT COUNT(*) FROM `sov_submission_responses` WHERE `submission_id` = ?', [$draft['id']])
        );
        $t->same(
            'its organisation, used by nothing else, is gone too',
            null,
            Database::selectOne('SELECT `id` FROM `organisations` WHERE `id` = ?', [$organisationId])
        );

        $audit = Database::selectOne(
            'SELECT * FROM `audit_log` WHERE `action` = ? AND `subject_id` = ? ORDER BY `id` DESC LIMIT 1',
            ['sov.submission.discarded', (string) $draft['id']]
        );
        $t->true('the deletion is audited', $audit !== null);
        $t->same('as the respondent, not an administrator', 'respondent', $audit['actor_label'] ?? null);
        $t->same('against this app', 'ai-sovereignty-scorecard', $audit['app'] ?? null);
        $t->same('with why and how much was dropped', ['reason' => 'start_over', 'marks' => 2], json_decode((string) ($audit['meta'] ?? ''), true));
        $t->true(
            'and without the respondent\'s details',
            !str_contains((string) ($audit['meta'] ?? ''), '@') && !str_contains((string) ($audit['meta'] ?? ''), 'Discard')
        );

        $t->true('a second discard of the same row does nothing', !$service->discard($row, $ipHash));

        /* ---------- a shared organisation survives ---------- */

        $first  = $service->start($profile('ZZ Discard Shared', 'discard.one@northgate-systems.com'), $ipHash);
        $second = $service->start($profile('ZZ Discard Shared', 'discard.two@northgate-systems.com'), $ipHash);
        $ids[]  = $first['id'];
        $ids[]  = $second['id'];

        $sharedId = (int) $service->find($first['id'])['organisation_id'];
        $t->same('two drafts resolve to one organisation', $sharedId, (int) $service->find($second['id'])['organisation_id']);

        $service->discard($service->find($first['id']), $ipHash);
        $t->true(
            'discarding one leaves the organisation the other still uses',
            Database::selectOne('SELECT `id` FROM `organisations` WHERE `id` = ?', [$sharedId]) !== null
        );

        $service->discard($service->find($second['id']), $ipHash);
        $t->same(
            'discarding the last one removes it',
            null,
            Database::selectOne('SELECT `id` FROM `organisations` WHERE `id` = ?', [$sharedId])
        );

        /* ---------- a finished scorecard is never discarded ---------- */

        $done  = $service->start($profile('ZZ Discard Finished', 'discard.done@northgate-systems.com'), $ipHash);
        $ids[] = $done['id'];

        $service->saveProgress(
            $service->find($done['id']),
            array_map(static fn (int $id): array => ['item_id' => $id, 'mark' => 3], $itemIds)
        );
        $service->submit($service->find($done['id']));

        $finished = $service->find($done['id']);
        $t->same('it was finished', 'complete', $finished['status']);
        $t->true('discarding a finished scorecard is refused', !$service->discard($finished, $ipHash));
        $t->same('and it is still there, complete', 'complete', $service->find($done['id'])['status'] ?? null);
        $t->same(
            'with all sixteen marks',
            16,
            (int) Database::scalar('SELECT COUNT(*) FROM `sov_submission_responses` WHERE `submission_id` = ?', [$done['id']])
        );
    } finally {
        foreach ($ids as $id) {
            Database::statement('DELETE FROM `sov_submissions` WHERE `id` = ?', [$id]);
        }

        $inUse = implode(' ', array_map(
            static fn (\Platform\Core\AppManifest $a): string => sprintf(
                'AND `id` NOT IN (SELECT DISTINCT `organisation_id` FROM `%s` WHERE `organisation_id` IS NOT NULL)',
                $a->table('submissions')
            ),
            AppRegistry::all()
        ));

        Database::statement('DELETE FROM `organisations` WHERE `name` LIKE ? ' . $inUse, ['ZZ Discard %']);
    }
};
