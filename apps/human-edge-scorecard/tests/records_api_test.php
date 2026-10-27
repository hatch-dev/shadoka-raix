<?php

declare(strict_types=1);

use Apps\HumanEdgeScorecard\Controllers\Admin\RecordsApiController;
use Apps\HumanEdgeScorecard\Controllers\Public\ScorecardApiController;
use Apps\HumanEdgeScorecard\Services\RecordStore;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\Request;

/**
 * The privacy directive (the privacy directive the RAIX scorecards share),
 * Part B, checked against its own acceptance criteria where a test can reach
 * them (7, 8, 10, 11, and the second half of 5).
 *
 * The database is shared with the running site. Every record this test writes
 * has an id starting "the1-" and is removed at the end; B3 and B4 replace the
 * WHOLE synthetic set, so the synthetic rows present at the start are saved
 * first and put back afterwards, and the console's copies (RecordMirror) are
 * brought back in step.
 */
return static function (Assert $t): void {
    $app   = AppRegistry::get('human-edge-scorecard');
    $store = new RecordStore($app);
    $table = $app->table('records');
    $tag   = 'the1-' . bin2hex(random_bytes(3)) . '-';

    // A record in the shape section 6 of the directive lists.
    $record = static function (string $id, array $over = []) use ($tag): array {
        $r = [
            'id' => $tag . $id, 'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'year' => (int) gmdate('Y'), 'synthetic' => false,
            'userName' => 'Test Person', 'userEmail' => 'probe@raix-tests.org', 'userPhone' => '',
            'org' => [
                'consent' => ['notice' => 'HE-PN-1', 'at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'scorecard' => true, 'research' => true, 'terms' => 'https://shadoka.com/terms/'],
                'name' => 'Test Person', 'email' => 'probe@raix-tests.org', 'phone' => '', 'org' => 'Probe Org',
                'role' => 'Manager', 'tenure' => '1–3 years', 'sector' => 'Technology', 'size' => '51–250', 'year' => (int) gmdate('Y'),
                'aiuse' => null, 'edu' => null,
            ],
            'dims' => ['JDG' => 2.5], 'mean' => 2.5, 'level' => 'Developing',
            // As a page builds it: an object keyed "0".."n", which a PHP list would not encode as.
            'responses' => ['JDG' => (object) ['0' => 3, '1' => 2]], 'artifacts' => ['JDG' => (object) []],
        ];

        return array_replace_recursive($r, $over);
    };
    $json = static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $row  = static fn (string $id): ?array => Database::selectOne("SELECT * FROM `{$table}` WHERE `id` = ?", [$id]);

    // A controller called as the web would call it.
    $call = static function (string $class, string $method, string $verb, array $server = [], ?string $body = null) use ($app) {
        $_SERVER = array_merge(['REQUEST_METHOD' => $verb, 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost'], $server);
        $_GET    = [];
        $_POST   = [];
        ScorecardApiController::$rawBody = $body;
        $response = (new $class(Request::capture(), $app))->{$method}();
        ScorecardApiController::$rawBody = null;

        return $response;
    };

    $savedSynthetic   = Database::select("SELECT * FROM `{$table}` WHERE `synthetic` = 1");
    $seedRun          = Database::scalar('SELECT MAX(`seed_run_id`) FROM `' . $app->table('submissions') . '` WHERE `is_synthetic` = 1');
    $adminId          = (int) (Auth::appAdmin($app->slug)['id'] ?? 0);

    try {
        /* ---------- routes ---------- */

        $routes = (string) file_get_contents($app->path('routes') . '/api.php');
        foreach (["post('/api/scorecards'", "get('/api/records'", "put('/api/admin/synthetic'", "delete('/api/admin/synthetic'"] as $r) {
            $t->true("route registered: {$r}", str_contains($routes, $r));
        }

        /* ---------- B1 ---------- */

        $ok  = $record('ok', ['org' => ['org' => '']]);
        $raw = $json($ok);

        $t->same('B1: a JSON array is refused', 400, $store->save('[' . $raw . ']')['status']);
        $t->same('B1: a scalar is refused', 400, $store->save('"x"')['status']);
        $t->same('B1: a body over 256 KB is refused', 413, $store->save(str_repeat(' ', RecordStore::MAX_BODY_BYTES) . $raw)['status']);
        $big = $call(ScorecardApiController::class, 'save', 'POST', ['CONTENT_LENGTH' => (string) (RecordStore::MAX_BODY_BYTES + 1)], $raw);
        $t->same('B1: a declared Content-Length over 256 KB is refused (413)', 413, $big->status());
        $t->same('B1: synthetic: true is refused', 400, $store->save($json($record('syn', ['synthetic' => true])))['status']);
        $t->same('B1: synthetic missing is refused', 400, $store->save(str_replace('"synthetic":false,', '', $json($record('nosyn'))))['status']);
        $t->same('B1: research consent false is refused', 400, $store->save($json($record('nores', ['org' => ['consent' => ['research' => false]]])))['status']);
        $t->true('B1: ... and is not stored', $row($tag . 'nores') === null);
        $t->same('B1: scorecard consent false is refused', 400, $store->save($json($record('nosc', ['org' => ['consent' => ['scorecard' => false]]])))['status']);
        $t->same('B1: research consent "true" (a string) is refused', 400, $store->save($json($record('strres', ['org' => ['consent' => ['research' => 'true']]])))['status']);

        $res = $call(ScorecardApiController::class, 'save', 'POST', ['CONTENT_TYPE' => 'application/json'], $raw);
        $t->same('B1: an empty organization name is accepted', 200, $res->status());
        $t->same('B1: the response body is {}', '{}', $res->content());
        $t->same('B1: stored exactly as received', $raw, $row($ok['id'])['payload'] ?? null);
        $t->same('B1: stored with org.org empty', '', json_decode((string) $row($ok['id'])['payload'])->org->org);
        $t->same('B1: stored as a real record', 0, (int) $row($ok['id'])['synthetic']);
        $t->same('B1: the email is indexed lowercased', 'probe@raix-tests.org', $row($ok['id'])['email']);

        // Acceptance 5: Review answers → change one answer → save again.
        $firstClock = $row($ok['id'])['completed_at'];
        $again      = $record('ok', ['org' => ['org' => ''], 'responses' => ['JDG' => (object) ['0' => 5, '1' => 2]]]);
        $res        = $call(ScorecardApiController::class, 'save', 'POST', [], $json($again));
        $t->same('B1: the same id again is accepted', [200, '{}'], [$res->status(), $res->content()]);
        $t->same('B1: ... stored once', 1, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `id` = ?", [$ok['id']]));
        $t->same('B1: ... and the newer copy replaced the first', $json($again), $row($ok['id'])['payload']);
        $t->same('B1: ... carrying the changed answer', 5, json_decode((string) $row($ok['id'])['payload'])->responses->JDG->{'0'});

        $later = $record('ok', ['timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400 * 400)]);
        $store->save($json($later));
        $t->same('B1: a re-save never moves the retention clock later', $firstClock, $row($ok['id'])['completed_at']);

        Database::statement("UPDATE `{$table}` SET `restricted` = 1 WHERE `id` = ?", [$ok['id']]);
        $store->save($raw);
        $t->same('B1: a re-save keeps a restriction in force', 1, (int) $row($ok['id'])['restricted']);
        Database::statement("UPDATE `{$table}` SET `restricted` = 0 WHERE `id` = ?", [$ok['id']]);

        $future = $record('future', ['timestamp' => '2099-01-01T00:00:00.000Z']);
        $store->save($json($future));
        $t->true('B1: a future timestamp cannot stretch the clock', strtotime($row($future['id'])['completed_at'] . ' UTC') <= time() + 5);

        $restricted = $record('restricted');
        $store->save($json($restricted));
        Database::statement("UPDATE `{$table}` SET `restricted` = 1 WHERE `id` = ?", [$restricted['id']]);

        /* ---------- B2, B3, B4 without a login ---------- */

        Auth::actAs(null);
        foreach ([['index', 'GET'], ['replaceSynthetic', 'PUT'], ['deleteSynthetic', 'DELETE']] as [$m, $verb]) {
            $before = (int) Database::scalar("SELECT COUNT(*) FROM `{$table}`");
            $res    = $call(RecordsApiController::class, $m, $verb, ['CONTENT_TYPE' => 'application/json'], '{"records":[]}');
            $t->same("{$verb} without a login is 401", 401, $res->status());
            $t->true("{$verb} without a login returns no records", !str_contains($res->content(), 'probe@') && !str_contains($res->content(), $tag));
            $t->same("{$verb} without a login changes nothing", $before, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}`"));
        }

        /* ---------- signed in as this app's admin ---------- */

        $t->true('the app has an administrator to sign in as', $adminId > 0);
        Auth::actAs($adminId);

        $cross = $call(RecordsApiController::class, 'index', 'GET', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $t->same('a cross-site request is refused even when signed in', 403, $cross->status());
        $same = $call(RecordsApiController::class, 'index', 'GET', ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $t->same('a same-origin request is let through', 200, $same->status());

        $syn = [
            $record('s1', ['synthetic' => true, 'org' => ['email' => 'x@example.invalid']]),
            $record('s2', ['synthetic' => true, 'org' => ['org' => '']]),
        ];

        $bad = $call(RecordsApiController::class, 'replaceSynthetic', 'PUT', [], $json(['records' => [$syn[0], $record('real-in-b3')]]));
        $t->same('B3: a record not marked synthetic is refused', 400, $bad->status());
        $t->same('B3: a bare array body is refused', 400, $call(RecordsApiController::class, 'replaceSynthetic', 'PUT', [], $json($syn))->status());
        $clash = $call(RecordsApiController::class, 'replaceSynthetic', 'PUT', [], $json(['records' => [$record('ok', ['synthetic' => true])]]));
        $t->same('B3: a synthetic record reusing a real id is refused', 400, $clash->status());

        $realBefore = Database::select("SELECT `id`, `payload`, `restricted`, `completed_at` FROM `{$table}` WHERE `synthetic` = 0 ORDER BY `id`");

        $res = $call(RecordsApiController::class, 'replaceSynthetic', 'PUT', [], $json(['records' => $syn]));
        $t->same('B3: signed in, the synthetic set is replaced', 200, $res->status());
        $t->same('B3: responds {}', '{}', $res->content());
        $t->same('B3: exactly the new synthetic records remain', 2, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `synthetic` = 1"));
        $t->true('B3: object keys survive ({"0":3} stays an object)', str_contains((string) $row($tag . 's1')['payload'], '"responses":{"JDG":{"0":3,"1":2}},"artifacts":{"JDG":{}}'));

        $clashB1 = $store->save($json($record('s1')));
        $t->same('B1: a real save cannot overwrite a demonstration record', 400, $clashB1['status']);
        $t->same('B1: ... which stays synthetic', 1, (int) $row($tag . 's1')['synthetic']);

        $list = $call(RecordsApiController::class, 'index', 'GET');
        $ids  = array_column(json_decode($list->content(), true), 'id');
        $t->same('B2: signed in, 200', 200, $list->status());
        $t->same('B2: the body is a JSON array', true, array_is_list(json_decode($list->content(), true)));
        $t->true('B2: includes synthetic records', in_array($tag . 's1', $ids, true));
        $t->true('B2: includes unrestricted real records', in_array($ok['id'], $ids, true));
        $t->true('B2: never includes a restricted record', !in_array($restricted['id'], $ids, true));
        $t->true('B2: ... which is kept, not deleted', $row($restricted['id']) !== null);

        $res = $call(RecordsApiController::class, 'deleteSynthetic', 'DELETE');
        $t->same('B4: signed in, 200 {}', [200, '{}'], [$res->status(), $res->content()]);
        $t->same('B4: no synthetic records remain', 0, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `synthetic` = 1"));
        $t->same('B3 and B4 changed no real record', $realBefore, Database::select("SELECT `id`, `payload`, `restricted`, `completed_at` FROM `{$table}` WHERE `synthetic` = 0 ORDER BY `id`"));

    } finally {
        Auth::actAs(null);
        Database::statement("DELETE FROM `{$table}` WHERE `id` LIKE ?", [$tag . '%']);

        // Put the demonstration set back as it was.
        Database::statement("DELETE FROM `{$table}` WHERE `synthetic` = 1");
        foreach ($savedSynthetic as $r) {
            Database::insert($table, $r);
        }

        // ... and the console's copies with it (raw SQL above bypassed RecordMirror).
        $mirror = new \Apps\HumanEdgeScorecard\Services\RecordMirror($app);
        $mirror->syncSynthetic($seedRun === null ? null : (int) $seedRun);
        $mirror->prune();
    }
};
