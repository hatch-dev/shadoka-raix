<?php

declare(strict_types=1);

use Apps\AiSovereigntyScorecard\Services\NoticeArchive;
use Apps\AiSovereigntyScorecard\Services\ScorecardPage;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * Directive v6, Part A: the served page is Shadoka's file, changed on line
 * 1023 (API_BASE) and on the owner-approved sign-in patch lines only; C5 keeps
 * a dated copy of it. Acceptance criteria 1 and 15 (the notice's version).
 *
 * Reads and renders only: nothing in the database is touched.
 */
return static function (Assert $t): void {
    $app  = AppRegistry::get('ai-sovereignty-scorecard');
    $page = new ScorecardPage($app);

    $supplied = (string) file_get_contents($page->path());
    $t->same('the supplied file is the v6 file (md5)', ScorecardPage::MD5, md5($supplied));
    $t->same('... and its size', ScorecardPage::BYTES, strlen($supplied));
    $t->same('it has 2,492 lines', 2492, substr_count($supplied, "\n"));
    $t->same('it makes exactly four fetch( calls, all fetch(API_BASE+', [4, 4], [substr_count($supplied, 'fetch('), substr_count($supplied, 'fetch(API_BASE+')]);

    $served = $page->html('/RAIX/dev/' . $app->slug . '/api');
    $a = explode("\n", $supplied);
    $b = explode("\n", $served);
    $changed = array_keys(array_diff_assoc($a, $b));
    $allowed = array_merge([1022], array_map(static fn (int $n): int => $n - 1, array_keys(ScorecardPage::PATCHES)));
    sort($allowed);
    $t->same('the served page differs only on line 1023 and the listed sign-in patch lines', $allowed, $changed);
    $t->same('line 1023 names this app\'s /api (line ending kept as supplied)', "const API_BASE='/RAIX/dev/{$app->slug}/api';" . (str_ends_with($a[1022], "\r") ? "\r" : ''), $b[1022]);
    $t->same('the served page has the same number of lines', count($a), count($b));

    $t->same('served: every other fetch( is a patch\'s own, to this app (sign-in fetch(RAIX_ADMIN+, Requests/Settings fetch(ADMIN +)',
        substr_count($served, 'fetch(') - 4, substr_count($served, 'fetch(RAIX_ADMIN+') + substr_count($served, 'fetch(ADMIN +'));
    $t->true('served: the Requests/Settings script only calls this app\'s own API', str_contains($served, "var ADMIN = API_BASE + '/admin';"));
    $t->true('served: the admin menu has Requests and Settings',
        str_contains($b[333], 'onclick="raixRequests()">Requests</button>') && str_contains($b[333], 'onclick="raixSettings()">Settings</button>'));
    $t->true('served: the Requests and Settings views exist', str_contains($b[364], 'id="view-requests"') && str_contains($b[364], 'id="view-settings"'));
    $t->true('served: the script is inlined on the </body> line', str_contains($b[2490], 'window.raixSettings') && str_ends_with(rtrim($b[2490], "\r"), '</script></body>'));
    $t->true('served: no "Admin responsibilities" notice anywhere (owner removed it, 30 Sep 2026)', !str_contains($served, 'Admin responsibilities'));
    $t->true('served: Settings is the console\'s screen ("Admin password" card)', str_contains($b[2490], 'Admin password') && str_contains($b[2490], 'pw-meter'));
    $t->true('served: the sign-in posts to this platform\'s /admin for this app',
        str_contains($served, "const RAIX_ADMIN='" . url('admin') . "',RAIX_APP='{$app->slug}';"));
    $t->true('served: the Admin dialog no longer calls itself demo-grade', !str_contains($b[1546], 'demo-grade') && !str_contains($b[1894], 'demo-grade'));
    $t->true('served: the notice text is untouched (noticeBody lines identical)', $a[1133] === $b[1133] && $a[1140] === $b[1140] && $a[1141] === $b[1141]);
    $t->true('served: the consent checkbox wording is untouched', $a[1147] === $b[1147]);
    $t->true('served: the results page has both save buttons, as on the Enterprise Scorecard',
        str_contains($b[1437], '>Save · score another organization</button>') && str_contains($b[1437], 'onclick="raixSaveThisScorecard()">Save this scorecard</button>'));
    // Automatic saving (5 Oct 2026): the supplied showResults() sends on arrival.
    $t->true('served: the supplied showResults() is untouched, so the results are sent as they appear',
        $a[1375] === $b[1375] && $a[1379] === $b[1379] && str_contains($b[1379], 'resultSavePromise=resultSavePromise.then(()=>persistResult(rec));'));
    $t->true('served: no trace of the 1 Oct save-on-buttons-only patch', !str_contains($served, 'raixUnusedAutoSaveShowResults'));
    $t->true('served: a failed save on arrival names the button that retries it',
        str_contains($b[1384], "toast('Your scorecard could not be saved. Click “Save this scorecard” to try again.');"));
    $t->true('served: "Save this scorecard" sends the same record again, after any save in flight',
        str_contains($b[1448], 'async function raixSaveThisScorecard(){') && str_contains($b[1448], 'resultSavePromise=resultSavePromise.then(()=>persistResult(rec));'));
    $t->true('served: "Save · score another organization" (v6\'s own function) is made to send again too',
        str_ends_with(rtrim($b[1448], "\r"), 'async function scoreAnotherOrganization(){ if(lastRecord) resultSavePromise=resultSavePromise.then(()=>false);'));
    $t->same('served: the home page list holds the four dimensions from the first paint', 4, substr_count($b[355], '<div class="comp"><div class="c">'));
    $t->true('served: ... in DIMS order', (bool) preg_match('#>DAT<.*>INF<.*>DEC<.*>ADT<#', $b[355]));
    $t->same('served: every embedded font is font-display:block, none swap', [7, 0], [substr_count(implode("\n", array_slice($b, 7, 7)), 'font-display:block'), substr_count($served, 'font-display:swap')]);
    $t->true('served: the hero waits for its fonts in <head> (at most 1.5 s), before any paint',
        str_contains($b[14], '.raix-fonts .hero{visibility:hidden}') && str_contains($b[14], 'setTimeout(done,1500)') && substr_count($b[14], '</script>') === 1);
    $added = substr($b[319], 1);
    $t->true('served: the phone styles are added after the page\'s own 900px block, and ONLY inside phone media queries (desktop untouched)',
        $b[319] !== $a[319] && (bool) preg_match('#^@media \(max-width:900px\)\{(?:[^{}]+\{[^{}]*\})+\}@media \(max-width:700px\)\{(?:[^{}]+\{[^{}]*\})+\}$#', $added));
    $t->true('served: the phone menu button follows the logo, hidden by default (wider screens never show it)',
        str_contains($b[324], '<button class="raix-menu" type="button" hidden aria-label="Open menu" aria-expanded="false" aria-controls="navLinks" onclick="raixMenu()">'));
    $t->true('served: the menu script is inlined with the others', str_contains($b[2490], 'window.raixMenu = function'));
    $t->true('the notice version is SOV-PN-1', str_contains($served, "const NOTICE_VERSION='SOV-PN-1';"));
    $t->same('C5: the version is read from the page itself', 'SOV-PN-1', NoticeArchive::noticeVersion($served));

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
        $t->true('C5: the deployed page\'s copy is labelled SOV-PN-1', str_contains((string) $copies[md5($live)]['file'], '_SOV-PN-1_'));
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
