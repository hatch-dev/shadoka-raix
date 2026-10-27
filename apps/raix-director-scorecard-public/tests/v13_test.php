<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecardPublic\Controllers\Admin\RecordsApiController;
use Apps\RaixDirectorScorecardPublic\Controllers\Public\ScorecardApiController;
use Apps\RaixDirectorScorecardPublic\Services\NoticeArchive;
use Apps\RaixDirectorScorecardPublic\Services\RecordStore;
use Apps\RaixDirectorScorecardPublic\Services\ScorecardPage;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\Request;

/**
 * Directive v13 (Directive_Privacy_DEV_Public_Director_Scorecard_v13_28Sep2026),
 * checked against its own acceptance criteria where a test can reach them.
 *
 * The database is shared with the running site. Every record this test writes
 * has an id starting "t13-" and is removed at the end; B4 and B5 replace the
 * WHOLE synthetic set, so the synthetic rows present at the start are saved
 * first and put back afterwards.
 */
return static function (Assert $t): void {
    $app   = AppRegistry::get('raix-director-scorecard-public');
    $store = new RecordStore($app);
    $table = $app->table('records');
    $tag   = 't13-' . bin2hex(random_bytes(3)) . '-';

    $record = static function (string $id, array $over = []) use ($tag): array {
        $r = [
            'id' => $tag . $id, 'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'year' => (int) gmdate('Y'),
            'org' => [
                'consent' => ['notice' => 'SC-PN-4', 'at' => gmdate('c'), 'scorecard' => true, 'research' => true, 'terms' => 'https://shadoka.com/terms/'],
                'org' => 'Probe Board', 'name' => 'Test Director', 'email' => 'probe@raix-tests.org', 'phone' => '',
                'role' => 'Board Chair', 'sector' => 'Technology', 'size' => '51–250', 'year' => (int) gmdate('Y'),
                'systems' => null, 'own' => [], 'tenure' => null, 'seats' => null, 'agenda' => null, 'edu' => null,
            ],
            'dims' => ['GOV' => 2.4], 'floor' => 2.4, 'floorCode' => 'GOV', 'mean' => 2.8, 'level' => 'Aware',
            // As the page builds it: an object keyed "0".."4", which a PHP list would not encode as.
            'responses' => ['GOV' => (object) ['0' => 3, '1' => 2]], 'artifacts' => new stdClass(), 'synthetic' => false,
            'consequences' => ['register' => false, 'agenda' => false, 'gate' => false],
            'userName' => 'Test Director', 'userEmail' => 'probe@raix-tests.org', 'userPhone' => null,
        ];

        return array_replace_recursive($r, $over);
    };
    $json = static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $row  = static fn (string $id): ?array => Database::selectOne("SELECT * FROM `{$table}` WHERE `id` = ?", [$id]);

    // A controller called as the web would call it.
    $call = static function (string $class, string $method, string $verb, array $server = [], ?string $body = null, array $query = []) use ($app) {
        $_SERVER = array_merge(['REQUEST_METHOD' => $verb, 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost'], $server);
        $_GET    = $query;
        $_POST   = [];
        ScorecardApiController::$rawBody = $body;
        $response = (new $class(Request::capture(), $app))->{$method}();
        ScorecardApiController::$rawBody = null;

        return $response;
    };

    $savedSynthetic = Database::select("SELECT * FROM `{$table}` WHERE `synthetic` = 1");
    $adminId        = (int) (Auth::appAdmin($app->slug)['id'] ?? 0);

    try {
        /* ---------- Part A: the page ---------- */

        $page     = new ScorecardPage($app);
        $supplied = (string) file_get_contents($page->path());
        $served   = $page->html('/RAIX/' . $app->slug . '/api');

        $t->same('the supplied file is the v14 file (md5)', ScorecardPage::MD5, md5($supplied));
        $a = explode("\n", $supplied);
        $b = explode("\n", $served);
        $t->same('v14: the md5 is the one the automatic-saving directive gives', '12678d77', substr(md5($supplied), 0, 8));
        $t->true('v14: one id per scorecard (A1)', str_contains($a[1278] ?? '', "if(!A.recordId) A.recordId=uid('raixsc'); return {id:A.recordId,"));
        $t->true('v14: saved when the results appear, with the failure message (A2)',
            str_contains($a[1287] ?? '', "go('results'); renderResults(lastRecord); saveResearch(lastRecord).catch(()=>toast('Your scorecard could not be saved."));
        $changed = array_keys(array_diff_assoc($a, $b));
        $allowed = array_merge([921], array_map(static fn (int $n): int => $n - 1, array_keys(ScorecardPage::PATCHES)));
        sort($allowed);
        $t->same('the served page differs only on line 922 and the listed patch lines', $allowed, $changed);
        $t->true('patch: the profile form subgrid has a row for the field error', str_contains($b[132], 'grid-row:span 4;') && !str_contains($served, 'grid-row:span 3;'));
        $t->same('line 922 names this app\'s /api', "const API_BASE='/RAIX/{$app->slug}/api';\r", $b[921]);
        $t->same('the supplied page makes exactly five fetch( calls, all fetch(API_BASE+', [5, 5], [substr_count($supplied, 'fetch('), substr_count($supplied, 'fetch(API_BASE+')]);
        $t->same('served: every other fetch( is a staff patch (sign-in fetch(RAIX_ADMIN+, Requests fetch(RQ +)',
            substr_count($served, 'fetch(') - 5, substr_count($served, 'fetch(RAIX_ADMIN+') + substr_count($served, 'fetch(RQ +'));
        $t->true("served: the Requests script only calls this app's own API", str_contains($served, "var RQ = API_BASE + '/admin/requests';"));
        $t->true('served: the Requests view exists and its script is inlined on the </body> line',
            str_contains($b[379], 'id="view-requests"') && str_contains($b[2177], 'window.raixRequests') && str_ends_with(rtrim($b[2177], "\r"), '</script></body>'));
        $t->true('served: the sign-in posts to this platform\'s /admin for this app',
            str_contains($served, "const RAIX_ADMIN='" . url('admin') . "',RAIX_APP='{$app->slug}';"));
        $t->true('served: the Admin dialog no longer calls itself demo-grade', !str_contains($served, 'It is demo-grade.'));
        $t->true('served: the admin menu has a Requests entry that opens the in-page view', str_contains($b[327], '<button data-v="requests" class="admin-only" onclick="raixRequests()">Requests</button>'));
        $t->true('the notice version is SC-PN-4', str_contains($served, "const NOTICE_VERSION='SC-PN-4';"));

        /* The share card (5 Oct 2026): sent on line 6 only, never part of what C5 archives. */
        $sent = explode("\n", $page->withShareCard($served));
        $t->same('share card: the page as sent differs from html() on line 6 only', [5], array_keys(array_diff_assoc($b, $sent)));
        $t->same('share card: ... and has the same number of lines', count($b), count($sent));
        $t->true('share card: line 6 keeps the supplied <title> and adds the banner and a large card',
            str_starts_with($sent[5], rtrim($b[5], "\r") . '<meta ') && str_ends_with($sent[5], substr($b[5], -1) === "\r" ? ">\r" : '>')
            && str_contains($sent[5], '<meta property="og:image" content="') && str_contains($sent[5], '/social/banner.')
            && str_contains($sent[5], '<meta name="twitter:card" content="summary_large_image">'));
        $preview = $page->previewPage(implode("
", $sent));
        $t->true('share card: a link-preview crawler gets the page up to the card line, then </head> (under 4 KB)',
            str_starts_with($preview, implode("
", array_slice($sent, 0, 6)) . "
</head>") && strlen($preview) < 4096);
        $t->true('share card: html() itself carries no share tags (the archived copy is unchanged)', !str_contains($served, 'og:image'));

        $threw = false;
        try { $page->html("/api';alert(1);//"); } catch (RuntimeException) { $threw = true; }
        $t->true('an API base that could break out of the string is refused', $threw);

        /* ---------- C5: every deployed page kept, labelled ---------- */

        $live = $page->html();
        $t->same('C5: the notice version is read from the page itself', 'SC-PN-4', NoticeArchive::noticeVersion($live));
        $copies = array_column(NoticeArchive::index(), null, 'md5');
        $t->true('C5: the page being served has a dated copy in the index', isset($copies[md5($live)]));
        $t->true('C5: ... labelled with its notice version',
            isset($copies[md5($live)]) && str_contains((string) $copies[md5($live)]['file'], '_SC-PN-4_'));
        $t->true('C5: ... and the copy is byte-identical to what is served',
            isset($copies[md5($live)]) && md5_file(NoticeArchive::dir() . '/' . $copies[md5($live)]['file']) === md5($live));
        $t->same('C5: serving it again writes nothing new', null, (new NoticeArchive($app))->record($live, $page->apiBase()));
        $t->true('C5: the replaced edition is kept too',
            (bool) array_filter(NoticeArchive::index(), static fn ($c) => str_contains((string) $c['file'], 'previous-edition_notice')));

        /* ---------- B1 ---------- */

        $ok = $record('ok', ['org' => ['org' => '']]);
        $raw = $json($ok);

        $t->same('B1: a JSON array is refused', 400, $store->save('[' . $raw . ']')['status']);
        $t->same('B1: a body over 256 KB is refused', 413, $store->save(str_repeat(' ', RecordStore::MAX_BODY_BYTES) . $raw)['status']);
        $t->same('B1: synthetic: true is refused', 400, $store->save($json($record('syn', ['synthetic' => true])))['status']);
        $t->same('B1: research consent false is refused', 400, $store->save($json($record('nores', ['org' => ['consent' => ['research' => false]]])))['status']);
        $t->true('B1: ... and is not stored', $row($tag . 'nores') === null);
        $t->same('B1: scorecard consent false is refused', 400, $store->save($json($record('nosc', ['org' => ['consent' => ['scorecard' => false]]])))['status']);

        $res = $call(ScorecardApiController::class, 'save', 'POST', ['CONTENT_TYPE' => 'application/json'], $raw);
        $t->same('B1: an empty board is accepted', 200, $res->status());
        $t->same('B1: the response body is {}', '{}', $res->content());
        $t->same('B1: stored exactly as received', $raw, $row($ok['id'])['payload'] ?? null);
        $t->same('B1: stored with org.org empty', '', json_decode((string) $row($ok['id'])['payload'])->org->org);
        $t->same('B1: the email is indexed lowercased', 'probe@raix-tests.org', $row($ok['id'])['email']);

        // Automatic-saving directive: the page re-sends the same id after
        // "Review answers" or a save button, and the revised copy replaces it.
        $again = $record('ok', ['org' => ['org' => ''], 'consequences' => ['gate' => true], 'floor' => 3.1]);
        $t->same('B1: the same id again is accepted', 200, $store->save($json($again))['status']);
        $t->same('B1: ... stored once', 1, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `id` = ?", [$ok['id']]));
        $t->same('B1: ... and the revised copy replaces the first', $json($again), $row($ok['id'])['payload']);
        $t->same('B1: ... with its derived floor', 3.1, (float) $row($ok['id'])['floor']);
        $t->same('B1: ... still a real record', 0, (int) $row($ok['id'])['synthetic']);

        $res = $call(ScorecardApiController::class, 'save', 'POST', ['CONTENT_TYPE' => 'application/json'], $json($again));
        $t->same('B1: a save-button resend of the same copy is accepted', 200, $res->status());
        $t->same('B1: ... and still one record', 1, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `id` = ?", [$ok['id']]));

        /* ---------- B2 ---------- */

        $expected = static function (string $exclude, float $floor) use ($table): array {
            $f = array_map('floatval', array_column(Database::select(
                "SELECT `floor` FROM `{$table}` WHERE `synthetic` = 0 AND `restricted` = 0 AND `floor` IS NOT NULL AND `id` <> ? ORDER BY `floor`",
                [$exclude]
            ), 'floor'));
            $n = count($f);

            return $n < 5
                ? ['n' => $n, 'median' => null, 'below' => null]
                : ['n' => $n, 'median' => $f[intdiv($n, 2)], 'below' => count(array_filter($f, static fn ($v) => $v < $floor))];
        };

        foreach ([1.2, 1.9, 2.6, 3.1, 3.8, 2.2] as $i => $f) {
            $store->save($json($record('b' . $i, ['floor' => $f])));
        }

        $res  = $call(ScorecardApiController::class, 'benchmark', 'GET', [], null, ['floor' => '2.5', 'exclude' => $tag . 'b0']);
        $body = json_decode($res->content(), true);
        $t->same('B2: only n, median and below', ['n', 'median', 'below'], array_keys($body));
        $t->same('B2: matches the rule (median at floor(n/2), below strictly less)', $expected($tag . 'b0', 2.5), $body);
        $t->true('B2: the exclude id is left out', $body['n'] === $store->benchmark('2.5', 'none')['n'] - 1);

        Database::statement("UPDATE `{$table}` SET `restricted` = 1 WHERE `id` = ?", [$tag . 'b1']);
        $t->same('B2: a restricted record is left out', $expected('none', 2.5)['n'], $store->benchmark('2.5', 'none')['n']);

        /* ---------- B3, B4, B5 ---------- */

        Auth::actAs(null);
        foreach ([['index', 'GET'], ['replaceSynthetic', 'PUT'], ['deleteSynthetic', 'DELETE']] as [$m, $verb]) {
            $res = $call(RecordsApiController::class, $m, $verb, ['CONTENT_TYPE' => 'application/json'], '{"records":[]}');
            $t->same("{$verb} without a login is 401", 401, $res->status());
            $t->true("{$verb} without a login returns no records", !str_contains($res->content(), 'probe@'));
        }

        $t->true('the app has an administrator to sign in as', $adminId > 0);
        Auth::actAs($adminId);

        $cross = $call(RecordsApiController::class, 'index', 'GET', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $t->same('a cross-site request is refused even when signed in', 403, $cross->status());

        $syn = [
            $record('s1', ['synthetic' => true, 'org' => ['email' => 'x@example.invalid']]),
            $record('s2', ['synthetic' => true, 'floor' => 1.0]),
        ];
        $bad = $call(RecordsApiController::class, 'replaceSynthetic', 'PUT', [], $json(['records' => [$syn[0], $record('real-in-b4')]]));
        $t->same('B4: a record not marked synthetic is refused', 400, $bad->status());

        $clash = $call(RecordsApiController::class, 'replaceSynthetic', 'PUT', [], $json(['records' => [$record('ok', ['synthetic' => true])]]));
        $t->same('B4: a synthetic record reusing a real id is refused', 400, $clash->status());

        $realBefore = Database::select("SELECT `id`, `payload`, `restricted` FROM `{$table}` WHERE `synthetic` = 0 ORDER BY `id`");

        $res = $call(RecordsApiController::class, 'replaceSynthetic', 'PUT', [], $json(['records' => $syn]));
        $t->same('B4: signed in, the synthetic set is replaced', 200, $res->status());
        $t->same('B4: responds {}', '{}', $res->content());
        $t->same('B4: exactly the new synthetic records remain', 2, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `synthetic` = 1"));
        $t->same('B4: object keys survive ({"0":3} stays an object)', true, str_contains((string) $row($tag . 's1')['payload'], '"responses":{"GOV":{"0":3,"1":2}}'));

        $list = $call(RecordsApiController::class, 'index', 'GET');
        $ids  = array_column(json_decode($list->content(), true), 'id');
        $t->same('B3: signed in, 200', 200, $list->status());
        $t->true('B3: includes synthetic records', in_array($tag . 's1', $ids, true));
        $t->true('B3: includes unrestricted real records', in_array($ok['id'], $ids, true));
        $t->true('B3: never includes a restricted record', !in_array($tag . 'b1', $ids, true));

        $res = $call(RecordsApiController::class, 'deleteSynthetic', 'DELETE');
        $t->same('B5: signed in, 200 {}', [200, '{}'], [$res->status(), $res->content()]);
        $t->same('B5: no synthetic records remain', 0, (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `synthetic` = 1"));
        $t->same('B4 and B5 changed no real record', $realBefore, Database::select("SELECT `id`, `payload`, `restricted` FROM `{$table}` WHERE `synthetic` = 0 ORDER BY `id`"));

        /* ---------- C4 ---------- */

        $found = array_column($store->findByEmail('PROBE@raix-tests.org'), 'id');
        $t->true('C4: found by email, ignoring case', in_array($ok['id'], $found, true));
        $t->true('C4: a restricted record is still found', in_array($tag . 'b1', $found, true));

        $t->same('C4: correct contact details', null, $store->correctIdentity($ok['id'], ['name' => 'Corrected Name', 'email' => 'New@raix-tests.org', 'phone' => '+1 202 555 0100', 'board' => 'New Board']));
        $fixed = json_decode((string) $row($ok['id'])['payload']);
        $t->same('C4: both copies of each field change together',
            ['Corrected Name', 'Corrected Name', 'New@raix-tests.org', 'New@raix-tests.org', '+1 202 555 0100', '+1 202 555 0100', 'New Board'],
            [$fixed->userName, $fixed->org->name, $fixed->userEmail, $fixed->org->email, $fixed->userPhone, $fixed->org->phone, $fixed->org->org]);
        $t->same('C4: the search column follows the corrected email', 'new@raix-tests.org', $row($ok['id'])['email']);
        $t->same('C4: the rest of the record is untouched', json_encode(json_decode($raw)->responses), json_encode($fixed->responses));

        $noRes = $fixed; $noRes->org->consent->research = false;
        $t->true('C4: a correction cannot remove research consent', $store->replacePayload($ok['id'], json_encode($noRes)) !== null);
        $moved = json_decode((string) $row($ok['id'])['payload']); $moved->id = 'other';
        $t->true('C4: a correction cannot change the id', $store->replacePayload($ok['id'], json_encode($moved)) !== null);

        /* ---------- C4 through the in-page Requests endpoints ---------- */

        // A request as the page sends it: a decoded JSON body (Request's
        // constructor is private and capture() reads php://input, which is
        // empty on the CLI, so it is built in Request's own scope).
        $api = static function (string $method, string $verb, array $args = [], array $body = [], bool $json = true) use ($app) {
            $server  = ['REQUEST_METHOD' => $verb, 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost'] + ($json ? ['CONTENT_TYPE' => 'application/json'] : []);
            $request = \Closure::bind(static fn () => new Request($verb, '/', [], $body, $server), null, Request::class)();

            return (new \Apps\RaixDirectorScorecardPublic\Controllers\Admin\RequestsApiController($request, $app))->{$method}(...$args);
        };

        Auth::actAs(null);
        $t->same('Requests API: signed out, search is 401', 401, $api('search', 'POST', [], ['email' => 'probe@raix-tests.org'])->status());
        Auth::actAs($adminId);
        $t->same('Requests API: a write without a JSON body is refused (415)', 415, $api('search', 'POST', [], ['email' => 'x'], false)->status());
        $t->same('Requests API: the Content-Type check passes for JSON', true, $api('search', 'POST', [], [])->status() === 200);
        $t->same('Requests API: an unknown id is 404', 404, $api('show', 'GET', ['nope-' . $tag])->status());
        $shown = json_decode($api('show', 'GET', [$ok['id']])->content(), true);
        $t->same('Requests API: show returns the record and its meta', [$ok['id'], $ok['id']], [$shown['meta']['id'] ?? null, $shown['record']['id'] ?? null]);
        $exp = $api('export', 'GET', [$ok['id']]);
        $t->true('Requests API: export returns the record as JSON', $exp->status() === 200 && (json_decode($exp->content())->id ?? null) === $ok['id']);
        // The C4 correction above moved this record to new@raix-tests.org.
        $found = json_decode($api('search', 'POST', [], ['email' => 'NEW@raix-tests.org'])->content(), true)['records'] ?? [];
        $t->true('Requests API: search finds the record, ignoring case', in_array($ok['id'], array_column($found, 'id'), true));
        $t->true('Requests API: a search result carries no email address', !str_contains(json_encode($found), '@'));
        $res = $api('restrict', 'POST', [$ok['id']], ['restricted' => true]);
        $t->true('Requests API: restrict', $res->status() === 200 && json_decode($res->content(), true)['meta']['restricted'] === true && (int) $row($ok['id'])['restricted'] === 1);
        $res = $api('restrict', 'POST', [$ok['id']], ['restricted' => false]);
        $t->true('Requests API: lift the restriction', $res->status() === 200 && (int) $row($ok['id'])['restricted'] === 0);
        $res = $api('correctIdentity', 'POST', [$ok['id']], ['name' => 'Via Page', 'email' => '', 'phone' => '', 'board' => '']);
        $t->same('Requests API: a correction that empties the email is refused', 422, $res->status());
        $t->same('Requests API: delete without DELETE typed is refused', 422, $api('destroy', 'POST', [$ok['id']])->status());
        $t->true('Requests API: ... and the record is still there', $row($ok['id']) !== null);
        $gone = $record('rq-del');
        $store->save($json($gone));
        $res = $api('destroy', 'POST', [$gone['id']], ['confirm' => 'DELETE', 'reason' => 'withdrawal']);
        $t->true('Requests API: delete with DELETE typed removes the record', $res->status() === 200 && $row($gone['id']) === null);

        $store->setRestricted($ok['id'], true);
        $ids = array_column(json_decode($call(RecordsApiController::class, 'index', 'GET')->content(), true), 'id');
        $t->true('C4: once restricted it is gone from /records', !in_array($ok['id'], $ids, true));
        $t->true('C4: ... but not deleted', $row($ok['id']) !== null);
        $t->true('C4: delete (withdrawal) removes it', $store->deleteReal($ok['id']) && $row($ok['id']) === null);

        /* ---------- C3 ---------- */

        $old  = $record('old', ['timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime('-25 months'))]);
        $keep = $record('keep', ['timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime('-23 months'))]);
        $future = $record('future', ['timestamp' => '2099-01-01T00:00:00.000Z']);
        foreach ([$old, $keep, $future] as $r) {
            $store->save($json($r));
        }
        $t->true('C3: a future timestamp cannot stretch the clock', strtotime($row($future['id'])['completed_at'] . ' UTC') <= time() + 5);
        $store->purgeExpired();
        $t->true('C3: a record 25 months old is deleted', $row($old['id']) === null);
        $t->true('C3: a record 23 months old is kept', $row($keep['id']) !== null);
    } finally {
        Auth::actAs(null);
        Database::statement("DELETE FROM `{$table}` WHERE `id` LIKE ?", [$tag . '%']);

        // Put the demonstration set back as it was.
        Database::statement("DELETE FROM `{$table}` WHERE `synthetic` = 1");
        foreach ($savedSynthetic as $r) {
            Database::insert($table, $r);
        }

        // ... and the console's copies with it (RecordMirror).
        $mirror = new \Apps\RaixDirectorScorecardPublic\Services\RecordMirror($app);
        $mirror->syncSynthetic();
        $mirror->prune();
    }
};
