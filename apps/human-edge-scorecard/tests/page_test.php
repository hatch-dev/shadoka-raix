<?php

declare(strict_types=1);

use Apps\HumanEdgeScorecard\Services\NoticeArchive;
use Apps\HumanEdgeScorecard\Services\ScorecardPage;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * The privacy directive, Part A: the served page is Shadoka's file, changed on
 * line 1117 (API_BASE) and on the listed patch lines only; C5 keeps a dated
 * copy of it.
 *
 * Reads and renders only: nothing in the database is touched.
 */
return static function (Assert $t): void {
    $app  = AppRegistry::get('human-edge-scorecard');
    $page = new ScorecardPage($app);

    $supplied = (string) file_get_contents($page->path());
    $t->same('the supplied file is the Human Edge file (md5)', ScorecardPage::MD5, md5($supplied));
    $t->same('... and its size', ScorecardPage::BYTES, strlen($supplied));
    $t->same('it has 2,728 lines', 2728, substr_count($supplied, "\n"));
    $t->same('it makes exactly four fetch( calls, all fetch(API_BASE+', [4, 4], [substr_count($supplied, 'fetch('), substr_count($supplied, 'fetch(API_BASE+')]);

    $served = $page->html('/RAIX/dev/' . $app->slug . '/api');
    $a = explode("\n", $supplied);
    $b = explode("\n", $served);
    $changed = array_keys(array_diff_assoc($a, $b));
    $allowed = array_merge([1116], array_map(static fn (int $n): int => $n - 1, array_keys(ScorecardPage::PATCHES)));
    sort($allowed);
    $t->same('the served page differs only on line 1117 and the listed patch lines', $allowed, $changed);
    $t->same('line 1117 names this app\'s /api', "const API_BASE='/RAIX/dev/{$app->slug}/api';", $b[1116]);
    $t->same('the served page has the same number of lines', count($a), count($b));

    $t->same('served: every other fetch( is a patch\'s own, to this app (sign-in fetch(RAIX_ADMIN+, Requests/Settings fetch(ADMIN +)',
        substr_count($served, 'fetch(') - 4, substr_count($served, 'fetch(RAIX_ADMIN+') + substr_count($served, 'fetch(ADMIN +'));
    $t->true('served: the Requests/Settings script only calls this app\'s own API', str_contains($served, "var ADMIN = API_BASE + '/admin';"));
    $t->true('served: the admin menu has Requests and Settings',
        str_contains($b[357], 'onclick="raixRequests()">Requests</button>') && str_contains($b[357], 'onclick="raixSettings()">Settings</button>'));
    $t->true('served: the Requests and Settings views exist', str_contains($b[388], 'id="view-requests"') && str_contains($b[388], 'id="view-settings"'));
    $t->true('served: the script is inlined on the </body> line', str_contains($b[2726], 'window.raixSettings') && str_ends_with($b[2726], '</script></body>'));
    $t->true('served: Settings is the console\'s screen ("Admin password" card)', str_contains($b[2726], 'Admin password') && str_contains($b[2726], 'pw-meter'));
    $t->true('served: the sign-in posts to this platform\'s /admin for this app',
        str_contains($served, "const RAIX_ADMIN='" . url('admin') . "',RAIX_APP='{$app->slug}';"));
    $t->true('served: Enter signs in on the server', str_contains($b[1659], 'function checkPin(){ return raixServerSignIn(); }'));
    $t->true('served: Exit signs out on the server too', str_contains($b[1189], 'function exitAdmin(){ raixServerSignOut();'));
    $t->true('served: the Admin dialog no longer calls itself demo-grade', !str_contains($b[1653], 'demo-grade') && !str_contains($b[2086], 'demo-grade'));
    $t->true('served: the notice text and consent wording are untouched (noticeBody .. consentAccept)',
        array_slice($a, 1236, 25) === array_slice($b, 1236, 25));
    $t->true('served: the results page has both save buttons, as on the other scorecards',
        str_contains($b[1545], '>Save · score another organization</button>') && str_contains($b[1545], 'onclick="raixSaveThisScorecard()">Save this scorecard</button>'));
    $t->true('served: the supplied showResults() is untouched, so the results are sent as they appear',
        $a[1479] === $b[1479] && $a[1483] === $b[1483] && str_contains($b[1483], 'resultSavePromise=resultSavePromise.then(()=>persistResult(rec));'));
    $t->true('served: a failed save on arrival names the button that retries it',
        str_contains($b[1488], "toast('Your scorecard could not be saved to the server. Click “Save this scorecard” to try again.');"));
    $t->true('served: "Save this scorecard" sends the same record again, after any save in flight',
        str_contains($b[1556], 'async function raixSaveThisScorecard(){') && str_contains($b[1556], 'resultSavePromise=resultSavePromise.then(()=>persistResult(rec));'));
    $t->true('served: "Save · score another organization" (the page\'s own function) is made to send again too',
        str_ends_with($b[1556], 'async function scoreAnotherOrganization(){ if(lastRecord) resultSavePromise=resultSavePromise.then(()=>false);'));
    $t->same('served: the home page list holds the six skills from the first paint', 6, substr_count($b[379], '<div class="comp"><div class="c">'));
    $t->true('served: ... in DIMS order, each with its sub-title', (bool) preg_match('#>JDG<.*>ADP<.*>EMP<.*>CON<.*>IMG<.*>AUT<#', $b[379])
        && substr_count($b[379], '<span class="sub-n"> · ') === 6 && str_contains($b[379], 'Judgment<span class="sub-n"> · Critical Thinking</span>'));
    $t->same('served: every embedded font is font-display:block, none swap', [7, 0], [substr_count(implode("\n", array_slice($b, 7, 7)), 'font-display:block'), substr_count($served, 'font-display:swap')]);
    $t->true('served: the hero waits for its fonts in <head> (at most 1.5 s), before any paint',
        str_contains($b[14], '.raix-fonts .hero{visibility:hidden}') && str_contains($b[14], 'setTimeout(done,1500)') && substr_count($b[14], '</script>') === 1);
    $added = substr($b[319], 1);
    $t->true('served: the phone styles are added after the page\'s own 900px block, and ONLY inside phone media queries (desktop untouched)',
        $b[319] !== $a[319] && (bool) preg_match('#^@media \(max-width:900px\)\{(?:[^{}]+\{[^{}]*\})+\}@media \(max-width:700px\)\{(?:[^{}]+\{[^{}]*\})+\}$#', $added));
    $t->true('served: the phone menu button follows the logo, hidden by default (wider screens never show it)',
        str_contains($b[348], '<button class="raix-menu" type="button" hidden aria-label="Open menu" aria-expanded="false" aria-controls="navLinks" onclick="raixMenu()">'));
    $t->true('served: the menu script is inlined with the others', str_contains($b[2726], 'window.raixMenu = function'));
    $t->true('the notice version is HE-PN-1', str_contains($served, "const NOTICE_VERSION='HE-PN-1';"));
    $t->same('C5: the version is read from the page itself', 'HE-PN-1', NoticeArchive::noticeVersion($served));

    /* The share card: sent on line 6 only, never part of what C5 archives. */
    $sent = explode("\n", $page->withShareCard($served));
    $t->same('share card: the page as sent differs from html() on line 6 only', [5], array_keys(array_diff_assoc($b, $sent)));
    $t->same('share card: ... and has the same number of lines', count($b), count($sent));
    $t->true('share card: line 6 keeps the supplied <title> and adds the share tags',
        str_starts_with($sent[5], $b[5] . '<meta ') && str_contains($sent[5], '<meta property="og:title" content="The Human Edge Scorecard">'));
    $t->true('share card: html() itself carries no share tags (the archived copy is unchanged)', !str_contains($served, 'og:title'));

    $threw = false;
    try { $page->html("/api';alert(1);//"); } catch (RuntimeException) { $threw = true; }
    $t->true('an API base that could break out of the string is refused', $threw);

    $t->true('the CSP allows requests to this host only', str_contains(ScorecardPage::CSP, "connect-src 'self'") && str_contains(ScorecardPage::CSP, "default-src 'none'"));

    /* C5: the page as deployed (the default API base) has a labelled copy, once it has been served. */
    $live   = $page->html();
    $copies = array_column(NoticeArchive::index(), null, 'md5');
    if (isset($copies[md5($live)])) {
        $t->true('C5: the deployed page\'s copy is labelled HE-PN-1', str_contains((string) $copies[md5($live)]['file'], '_HE-PN-1_'));
        $t->true('C5: ... and is byte-identical to what is served', md5_file(NoticeArchive::dir() . '/' . $copies[md5($live)]['file']) === md5($live));
        $t->same('C5: serving it again writes nothing new', null, (new NoticeArchive($app))->record($live, $page->apiBase()));
    } else {
        $t->true('C5: (page not served yet on this install; open it once and re-run)', true);
    }
};
