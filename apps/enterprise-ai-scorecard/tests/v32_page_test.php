<?php

declare(strict_types=1);

use Apps\EnterpriseAiScorecard\Services\NoticeArchive;
use Apps\EnterpriseAiScorecard\Services\ScorecardPage;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * Directive v32, Part A: the served page is Shadoka's file, changed on line
 * 972 (API_BASE) and on the owner-approved sign-in patch lines only; C5 keeps
 * a dated copy of it. Acceptance criteria 1 and 15 (the notice's version).
 *
 * Reads and renders only: nothing in the database is touched.
 */
return static function (Assert $t): void {
    $app  = AppRegistry::get('enterprise-ai-scorecard');
    $page = new ScorecardPage($app);

    $supplied = (string) file_get_contents($page->path());
    $t->same('the supplied file is the v33 file (md5)', ScorecardPage::MD5, md5($supplied));
    $t->same('v33: the md5 is the one the automatic-saving directive gives', '06af2d76', substr(md5($supplied), 0, 8));
    $t->same('... and its size', ScorecardPage::BYTES, strlen($supplied));
    $t->same('it has 2,277 lines', 2277, substr_count($supplied, "\n"));
    $t->same('it makes exactly four fetch( calls, all fetch(API_BASE+', [4, 4], [substr_count($supplied, 'fetch('), substr_count($supplied, 'fetch(API_BASE+')]);

    $served = $page->html('/RAIX/dev/' . $app->slug . '/api');
    $a = explode("\n", $supplied);
    $b = explode("\n", $served);
    $t->true('v33: one id per scorecard (already in v32)', str_contains($a[1313], 'return {id:A.recordId,'));
    $t->true('v33: saved when the results appear, with the failure message (B1 of the directive)',
        str_contains($a[1321], "go('results'); renderResults(lastRecord); saveResearch(lastRecord).catch(()=>toast('Your scorecard could not be saved."));
    $changed = array_keys(array_diff_assoc($a, $b));
    $allowed = array_merge([971], array_map(static fn (int $n): int => $n - 1, array_keys(ScorecardPage::PATCHES)));
    sort($allowed);
    $t->same('the served page differs only on line 972 and the listed sign-in patch lines', $allowed, $changed);
    $t->same('line 972 names this app\'s /api (line ending kept as supplied)', "const API_BASE='/RAIX/dev/{$app->slug}/api';" . (str_ends_with($a[971], "\r") ? "\r" : ''), $b[971]);
    $t->same('the served page has the same number of lines', count($a), count($b));

    $t->same('served: every other fetch( is a patch\'s own, to this app (sign-in fetch(RAIX_ADMIN+, Requests/Settings fetch(ADMIN +)',
        substr_count($served, 'fetch(') - 4, substr_count($served, 'fetch(RAIX_ADMIN+') + substr_count($served, 'fetch(ADMIN +'));
    $t->true('served: the Requests/Settings script only calls this app\'s own API', str_contains($served, "var ADMIN = API_BASE + '/admin';"));
    $t->true('served: the admin menu has Requests and Settings',
        str_contains($b[333], 'onclick="raixRequests()">Requests</button>') && str_contains($b[333], 'onclick="raixSettings()">Settings</button>'));
    $t->true('served: the Requests and Settings views exist', str_contains($b[364], 'id="view-requests"') && str_contains($b[364], 'id="view-settings"'));
    $t->true('served: the script is inlined on the </body> line', str_contains($b[2275], 'window.raixSettings') && str_ends_with(rtrim($b[2275], "\r"), '</script></body>'));
    $t->true('served: no "Admin responsibilities" notice anywhere (owner removed it, 30 Sep 2026)', !str_contains($served, 'Admin responsibilities'));
    $t->true('served: Settings is the console\'s screen ("Admin password" card)', str_contains($b[2275], 'Admin password') && str_contains($b[2275], 'pw-meter'));
    $t->true('served: the sign-in posts to this platform\'s /admin for this app',
        str_contains($served, "const RAIX_ADMIN='" . url('admin') . "',RAIX_APP='{$app->slug}';"));
    $t->true('served: the Admin dialog no longer calls itself demo-grade', !str_contains($b[1486], 'demo-grade') && !str_contains($b[1834], 'demo-grade'));
    $t->true('served: the notice text is untouched (noticeBody lines identical)', $a[1082] === $b[1082] && $a[1089] === $b[1089] && $a[1090] === $b[1090]);
    $t->true('served: the consent checkbox wording is untouched', $a[1096] === $b[1096]);
    $t->true('the notice version is ENT-PN-1', str_contains($served, "const NOTICE_VERSION='ENT-PN-1';"));
    $t->same('C5: the version is read from the page itself', 'ENT-PN-1', NoticeArchive::noticeVersion($served));

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

    $t->true('the CSP allows requests to this host only', str_contains(ScorecardPage::CSP, "connect-src 'self'") && str_contains(ScorecardPage::CSP, "default-src 'none'"));

    /* C5: the page as deployed (the default API base) has a labelled copy, once it has been served. */
    $live   = $page->html();
    $copies = array_column(NoticeArchive::index(), null, 'md5');
    if (isset($copies[md5($live)])) {
        $t->true('C5: the deployed page\'s copy is labelled ENT-PN-1', str_contains((string) $copies[md5($live)]['file'], '_ENT-PN-1_'));
        $t->true('C5: ... and is byte-identical to what is served', md5_file(NoticeArchive::dir() . '/' . $copies[md5($live)]['file']) === md5($live));
        $t->same('C5: serving it again writes nothing new', null, (new NoticeArchive($app))->record($live, $page->apiBase()));
    } else {
        $t->true('C5: (page not served yet on this install; open it once and re-run)', true);
    }
    $t->true('C5: the replaced edition is kept too',
        (bool) array_filter(NoticeArchive::index(), static fn ($c) => str_contains((string) $c['file'], 'previous-edition_overview')));
    $t->true('C5: the supplied file is kept as received',
        (bool) array_filter(NoticeArchive::index(), static fn ($c) => ($c['md5'] ?? '') === ScorecardPage::MD5));
};
