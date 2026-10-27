<?php

declare(strict_types=1);

use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\RecordsApiController;
use Apps\AiExecutiveReadinessScorecard\Services\RecordStore;
use Apps\AiExecutiveReadinessScorecard\Services\Retention;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\Request;
use Platform\Core\Settings;

/**
 * Directive v4, C4 (requests from individuals) and C3 (24-month deletion),
 * over exr_records. Acceptance criteria 9 (second half) and 12.
 *
 * Shared database: every record this test writes has an id starting "tr32-"
 * and is removed at the end, and the retention job's last-run stamp is put
 * back as it was. The purge itself is run against a clock set 30 years ahead
 * only inside a transaction that is rolled back, so no one else's record is
 * ever deleted by this test.
 */
return static function (Assert $t): void {
    $app   = AppRegistry::get('ai-executive-readiness-scorecard');
    $store = new RecordStore($app);
    $table = $app->table('records');
    $tag   = 'tr32-' . bin2hex(random_bytes(3)) . '-';
    $mail  = 'probe-' . bin2hex(random_bytes(3)) . '@raix-tests.org';

    $record = static function (string $id, array $over = []) use ($tag, $mail): array {
        $r = [
            'id' => $tag . $id, 'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'year' => (int) gmdate('Y'), 'synthetic' => false,
            'userName' => 'Test Executive', 'userEmail' => $mail, 'userPhone' => '',
            'org' => [
                'consent' => ['notice' => 'EXR-PN-2', 'at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'scorecard' => true, 'research' => true, 'terms' => 'https://shadoka.com/terms/'],
                'name' => 'Test Executive', 'email' => $mail, 'phone' => '', 'org' => 'Probe Org',
            ],
            'instrument' => 'ai-executive-readiness', 'version' => '1.0',
            'responses' => ['self' => ['STR' => (object) ['0' => 3, '1' => 2]], 'leadership' => ['STR' => (object) ['0' => 2, '1' => 2]]],
        ];

        return array_replace_recursive($r, $over);
    };
    $json = static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $row  = static fn (string $id): ?array => Database::selectOne("SELECT * FROM `{$table}` WHERE `id` = ?", [$id]);
    $ago  = static fn (string $rel): string => gmdate('Y-m-d\TH:i:s.000\Z', strtotime($rel));

    $lastRun = Settings::get(Retention::lastRunKey($app));
    $adminId = (int) (Auth::appAdmin($app->slug)['id'] ?? 0);

    try {
        $ok  = $record('ok');
        $raw = $json($ok);
        $t->same('setup: a record saved', 200, $store->save($raw)['status']);
        $store->save($json($record('two', ['org' => ['org' => '']])));

        /* ---------- C4: find ---------- */

        $found = array_column($store->findByEmail(strtoupper($mail)), 'id');
        sort($found);
        $t->same('C4: found by email, ignoring case', [$tag . 'ok', $tag . 'two'], $found);
        $t->same('C4: an empty search finds nothing', [], $store->findByEmail('  '));
        $t->same('C4: another address finds nothing of ours', [], array_filter(array_column($store->findByEmail('someone-else@raix-tests.org'), 'id'), static fn ($i) => str_starts_with($i, $tag)));

        $orgOnly = $record('orgonly', ['userEmail' => '', 'org' => ['email' => strtoupper($mail)]]);
        $store->save($json($orgOnly));
        $t->true('C4: found by org.email when userEmail is empty', in_array($orgOnly['id'], array_column($store->findByEmail($mail), 'id'), true));

        /* ---------- C4: correct ---------- */

        $t->same('C4: correct contact details', null, $store->correctIdentity($ok['id'], [
            'name' => 'Corrected Name', 'email' => 'New-' . $mail, 'phone' => '+1 202 555 0100', 'organization' => 'New Org',
        ]));
        $fixed = json_decode((string) $row($ok['id'])['payload']);
        $t->same('C4: both copies of each field change together',
            ['Corrected Name', 'Corrected Name', 'New-' . $mail, 'New-' . $mail, '+1 202 555 0100', '+1 202 555 0100', 'New Org'],
            [$fixed->userName, $fixed->org->name, $fixed->userEmail, $fixed->org->email, $fixed->userPhone, $fixed->org->phone, $fixed->org->org]);
        $t->same('C4: the search column follows the corrected email', strtolower('new-' . $mail), $row($ok['id'])['email']);
        $t->same('C4: the rest of the record is untouched', json_encode(json_decode($raw)->responses), json_encode($fixed->responses));
        $t->true('C4: an emptied email is refused', $store->correctIdentity($ok['id'], ['email' => '']) !== null);
        $t->true('C4: an emptied name is refused', $store->correctIdentity($ok['id'], ['name' => ' ']) !== null);
        $t->same('C4: the organization may be emptied (it is optional)', null, $store->correctIdentity($ok['id'], ['organization' => '']));
        $t->same('C4: ... and is stored empty', '', json_decode((string) $row($ok['id'])['payload'])->org->org);

        $noRes = json_decode((string) $row($ok['id'])['payload']);
        $noRes->org->consent->research = false;
        $t->true('C4: a correction cannot remove research consent', $store->replacePayload($ok['id'], json_encode($noRes)) !== null);
        $moved = json_decode((string) $row($ok['id'])['payload']);
        $moved->id = 'other';
        $t->true('C4: a correction cannot change the id', $store->replacePayload($ok['id'], json_encode($moved)) !== null);
        $t->true('C4: a correction must be one JSON object', $store->replacePayload($ok['id'], '[1,2]') !== null);
        $clock = $row($ok['id'])['completed_at'];
        $later = json_decode((string) $row($ok['id'])['payload']);
        $later->timestamp = gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400 * 400);
        $t->same('C4: a whole-record correction is accepted', null, $store->replacePayload($ok['id'], json_encode($later)));
        $t->same('C4: ... and never moves the deletion date later', $clock, $row($ok['id'])['completed_at']);

        /* ---------- C4: export ---------- */

        $t->same('C4: export — the stored record is readable as one object', $ok['id'], RecordStore::decodeObject((string) $store->findReal($ok['id'])['payload'])->id ?? null);
        $t->same('C4: findReal never returns a demonstration record', null, $store->findReal('no-such-' . $tag));

        /* ---------- C4: restrict ---------- */

        $t->true('C4: restrict', $store->setRestricted($ok['id'], true) && (int) $row($ok['id'])['restricted'] === 1);
        $t->true('C4: a restricted record is still found by email', in_array($ok['id'], array_column($store->findByEmail('new-' . $mail), 'id'), true));

        Auth::actAs($adminId);
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost'];
        $_GET = $_POST = [];
        $ids = array_column(json_decode((new RecordsApiController(Request::capture(), $app))->index()->content(), true), 'id');
        $t->true('C4: once restricted it is gone from GET /records', !in_array($ok['id'], $ids, true));
        $t->true('C4: ... but not deleted', $row($ok['id']) !== null);
        Auth::actAs(null);

        $t->true('C4: lift the restriction', $store->setRestricted($ok['id'], false) && (int) $row($ok['id'])['restricted'] === 0);
        $t->same('C4: restricting an unknown id reports false', false, $store->setRestricted('no-such-' . $tag, true));

        /* ---------- C4: delete / withdraw ---------- */

        $t->true('C4: withdrawal of consent deletes the whole record', $store->deleteReal($ok['id']) && $row($ok['id']) === null);
        $t->same('C4: deleting it again reports false', false, $store->deleteReal($ok['id']));

        /* ---------- C3: 24 months ---------- */

        $old  = $record('old', ['timestamp' => $ago('-25 months')]);
        $edge = $record('edge', ['timestamp' => $ago('-24 months -1 day')]);
        $keep = $record('keep', ['timestamp' => $ago('-23 months')]);
        foreach ([$old, $edge, $keep] as $r) {
            $store->save($json($r));
        }

        $t->same('C3: the deletion date is 24 months after completion',
            gmdate('Y-m-d', strtotime($row($keep['id'])['completed_at'] . ' UTC +24 months')),
            substr(RecordStore::deleteBy((string) $row($keep['id'])['completed_at']), 0, 10));

        $result = Retention::run($app);
        $t->true('C3: a record 25 months old is deleted', $row($old['id']) === null);
        $t->true('C3: a record just past 24 months is deleted', $row($edge['id']) === null);
        $t->true('C3: a record 23 months old is kept', $row($keep['id']) !== null);
        $t->true('C3: the run reports what it deleted', $result['records'] >= 2);
        $t->true('C3: the run stamps its last-run time', Settings::get(Retention::lastRunKey($app)) !== null);
        $logFiles = array_map(static fn (array $l): string => str_replace('\\', '/', $l['file']), $result['logs']);
        $t->true('C6: the same daily run trims the logs (the RAIX app log is covered)',
            (bool) array_filter($logFiles, static fn (string $f): bool => str_ends_with($f, 'storage/logs/app.log')));
        $t->true('C6: every log it touched was handled (trimmed, unchanged or deleted, never unreadable)',
            array_filter($result['logs'], static fn (array $l): bool => !in_array($l['action'], ['trimmed', 'unchanged', 'deleted'], true)) === []);

        $store->save($json($record('late', ['timestamp' => $ago('-26 months')])));
        Retention::runIfDue($app);
        $t->true('C3: runIfDue does nothing again within the day', $row($tag . 'late') !== null);
        Settings::put(Retention::lastRunKey($app), gmdate('Y-m-d H:i:s', time() - 90000));
        Retention::runIfDue($app);
        $t->true('C3: runIfDue runs once a day has passed', $row($tag . 'late') === null);

        // Demonstration records age out too — inside a rolled-back transaction,
        // with a clock 30 years ahead, so nothing outside this test is lost.
        $syn = Database::connection();
        $syn->beginTransaction();
        try {
            $store->purgeExpired(new DateTimeImmutable('+30 years', new DateTimeZone('UTC')));
            $t->same('C3: every record, synthetic included, is deleted once past the limit', 0, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}`"));
        } finally {
            $syn->rollBack();
        }
        $t->true('C3: ... (rolled back: the kept record is still there)', $row($keep['id']) !== null);

        /* ---------- no bulk delete of real records ---------- */

        $routes = (string) file_get_contents($app->path('routes') . '/api.php') . (string) file_get_contents($app->path('routes') . '/admin.php');
        $t->same('no route deletes real records in bulk (only /requests/{id}/delete and /admin/synthetic)',
            0, preg_match("#(delete\\('/api/records|/records/delete|/requests/delete)#", $routes));
    } finally {
        Auth::actAs(null);
        Database::statement("DELETE FROM `{$table}` WHERE `id` LIKE ?", [$tag . '%']);
        (new \Apps\AiExecutiveReadinessScorecard\Services\RecordMirror($app))->prune();

        if ($lastRun === null) {
            Settings::forget(Retention::lastRunKey($app));
        } else {
            Settings::put(Retention::lastRunKey($app), $lastRun);
        }
    }
};
