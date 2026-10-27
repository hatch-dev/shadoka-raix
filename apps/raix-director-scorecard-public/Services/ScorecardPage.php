<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services;

use Platform\Core\AppManifest;
use RuntimeException;

/**
 * The public page: Shadoka's supplied file, served as supplied.
 *
 * Directive v13, Part A: the deployed HTML must be identical to
 * RAIX_Director_Scorecard_public_v14.html (v13 plus the automatic-saving
 * directive of 28 Sep 2026) or differ on line 922 only, where
 * API_BASE says where the five endpoints are. They are at {slug}/api here,
 * under the platform's mount, so line 922 is rewritten to that path on the way
 * out and nothing else is touched. The file on disk is never edited.
 *
 * The file is checked before every use: if it is not the supplied one (size and
 * md5), the page is not served at all rather than served changed. A new notice
 * version from Shadoka means a new file, new constants below, and a dated copy
 * in storage/archive/notice-versions (C5).
 */
final class ScorecardPage
{
    public const FILE          = 'page/RAIX_Director_Scorecard_public_v14.html';
    public const MD5           = '12678d771506f4a15022a4b3116eb286';
    public const BYTES         = 1329569;
    public const NOTICE        = 'SC-PN-4';
    public const API_LINE      = 922;
    public const API_LINE_TEXT = "const API_BASE='/api';";

    /**
     * Fixes to bugs in the supplied file, applied as it is served, each on one
     * line and each matched exactly. KNOWN DEVIATIONS from the directive's
     * "identical except line 922": approved by the owner on 29 Sep 2026 and to
     * be reported to SHADOKA so the next supplied file carries the fix, after
     * which the entry here must go (a new file will not match and html()
     * refuses to serve rather than patch the wrong text).
     *
     * line => [exact text the line must contain, what replaces it, why]
     *
     * @var array<int, array{0:string, 1:string, 2:string}>
     */
    public const PATCHES = [
        133 => [
            'grid-row:span 3;row-gap:6px',
            'grid-row:span 4;row-gap:6px',
            // Paired profile fields (Email, Phone, Company size, …) sit on a
            // 3-row subgrid: label, input, hint. The field error is a 4th
            // child, so with no row of its own it was drawn on top of the hint
            // ("Enter an email address." over "Used to identify your scorecard…").
            'profile form: field error overlapped the hint',
        ],
        144 => [
            'select.f.bad{border-color:var(--r1);background:rgba(210,86,78,.06)}',
            'select.f.bad{border-color:var(--r1);background-color:rgba(210,86,78,.06)}',
            // The `background` shorthand reset select.f's background-image, so
            // a select in the error state (Company size) lost its arrow.
            'profile form: a select in the error state lost its dropdown arrow',
        ],
        180 => [
            'select.rsel option{',
            'select.rsel option,select.f option{',
            // Only the questionnaire's selects styled their options. The error
            // tint above is near-transparent, so Chrome drew the open list
            // light, with the options' light text on it: unreadable.
            'profile form: an erroring select opened an unreadable light option list',
        ],

        /*
         * Admin sign-in from the page itself (owner's request, 29 Sep 2026).
         *
         * The supplied Admin dialog compared a PIN in JavaScript and never told
         * the server, so /api/records answered 401 until staff had also signed
         * in at /admin/login. The dialog now signs in to THIS app's admin on
         * the server with its password (the one changed at the console's
         * Settings), and the page's Exit signs out there too. The client-side
         * ADMIN_PIN comparison is no longer used. {{ADMIN}} and {{APP}} are
         * filled in by html().
         */
        /*
         * Requests from individuals (C4) as a view of the page itself, under
         * the page's own admin header (owner's request, 29 Sep 2026): a menu
         * entry, a view to render into, and the script that renders it
         * (page/patches/requests.js, inlined, talking to RequestsApiController).
         */
        328 => [
            '<button data-v="analytics" class="admin-only" onclick="go(\'analytics\')">Analytics</button>',
            '<button data-v="requests" class="admin-only" onclick="raixRequests()">Requests</button>'
                . '<button data-v="analytics" class="admin-only" onclick="go(\'analytics\')">Analytics</button>',
            'the admin menu gains Requests (C4)',
        ],
        380 => [
            '<section class="view" id="view-data"><div class="wrap" id="dataRoot" style="padding-top:40px;padding-bottom:80px"></div></section>',
            '<section class="view" id="view-data"><div class="wrap" id="dataRoot" style="padding-top:40px;padding-bottom:80px"></div></section>'
                . '<section class="view" id="view-requests"><div class="wrap" id="rqRoot" style="padding-top:40px;padding-bottom:80px"></div></section>',
            'the view the Requests screen renders into',
        ],
        1483 => [
            ":`style=\"--c:\${lv?lv.c:'var(--stone)'}\"`}>",
            ":`style=\"--c:\${lv?lv.c:'var(--stone)'};cursor:pointer\" onclick=\"raixOpenRecord('\${esc(r.id)}')\"`}>",
            // A record with no board had no click at all (a board row opens
            // its board modal; "Board not given" has none). It now opens the
            // same kind of modal for that one scorecard (requests.js).
            'Research data: a "Board not given" row opens a scorecard modal',
        ],
        2178 => [
            '</body>',
            '<script>{{REQUESTS_JS}}</script></body>',
            'the Requests view\'s script (page/patches/requests.js)',
        ],
        984 => [
            "function exitAdmin(){ adminOK=false;",
            "function exitAdmin(){ raixServerSignOut(); adminOK=false;",
            'Exit also ends the server-side admin session',
        ],
        1526 => [
            'Analytics and the research dataset sit behind this gate. It is demo-grade. Do not treat it as a security control.',
            'Analytics and the research dataset sit behind this gate. Sign in with this scorecard\'s admin password; it is checked on the server.',
            'the dialog no longer calls itself demo-grade, because it is now the real sign-in',
        ],
        1527 => [
            '<label class="f" for="pin">PIN</label>',
            '<label class="f" for="pin">Password</label>',
            'the dialog asks for the admin password',
        ],
        1532 => [
            'function checkPin(){',
            'function checkPin(){ return raixServerSignIn(); }' . self::SIGN_IN_JS . ' function raixUnusedLocalPinCheck(){',
            'Enter signs in on the server; the old client-side PIN comparison is left in place, uncalled',
        ],
        1877 => [
            'The PIN gate is demo-grade. Do not treat it as a security control.',
            'Signed in on the server with this scorecard\'s admin password.',
            'the console no longer calls the gate demo-grade',
        ],
        /* The phone layout, ported from the AI Sovereignty Scorecard's page
           (owner's request, 1 Oct 2026: "fix for mobile responsive"). The
           same rules and the same menu; only the line numbers differ. */
        /*
         * Phones (owner's request, 1 Oct 2026: "make it mobile responsive …
         * do not change anything in web version"). Every rule is inside
         * @media (max-width:900px) — the page's own phone breakpoint — so a
         * wider screen gets exactly the supplied page. Seen in screenshots at
         * 390px, the header was the problem on every screen: position:fixed
         * with a fixed 64px reserved under it, it grew to 130–150px (a 4-line
         * logo beside up to seven buttons), covered the top of every page
         * (Step 1 of 6, the results line, each admin heading) and pushed the
         * questionnaire 100px wider than the screen. On a phone it is now one
         * slim sticky bar, the logo and a menu button (line 322), and the
         * header's buttons open as a list below it (page/patches/requests.js,
         * window.raixMenu). Two earlier tries were dropped on the owner's
         * screenshots: a sideways-scrolling row (buttons off-screen) and
         * wrapped rows (untidy, one button alone on a row).
         * Also: the results buttons stack full width (primary first) instead
         * of two cramped columns, and the home timeline's line sits under its
         * dots.
         */
        317 => [
            '}',
            '}@media (max-width:900px){'
                . 'body{padding-top:0}'
                . 'nav,nav.scrolled{position:sticky;top:0;flex-wrap:wrap;align-items:center;gap:0 12px;padding:10px 16px;background:rgba(6,13,24,.97);border-bottom-color:var(--rule-soft)}'
                . 'nav .logo{flex:1 1 0;min-width:0;text-align:left;padding:0;font-size:17px;line-height:1.25}'
                . 'nav .raix-menu{display:inline-flex;flex-direction:column;justify-content:center;gap:4px;flex:0 0 auto;width:42px;height:38px;padding:0 11px;border:1px solid var(--rule);border-radius:3px;background:transparent;cursor:pointer}'
                . 'nav .raix-menu span{display:block;height:2px;background:var(--paper);border-radius:1px;transition:transform .2s,opacity .2s}'
                . 'nav.raix-open .raix-menu{border-color:var(--blue)}'
                . 'nav.raix-open .raix-menu span:nth-child(1){transform:translateY(6px) rotate(45deg)}'
                . 'nav.raix-open .raix-menu span:nth-child(2){opacity:0}'
                . 'nav.raix-open .raix-menu span:nth-child(3){transform:translateY(-6px) rotate(-45deg)}'
                . 'nav .nav-links{display:none;flex:1 1 100%;flex-direction:column;align-items:stretch;gap:2px;margin-top:10px;padding:8px 0 4px;border-top:1px solid var(--rule-soft)}'
                . 'nav.raix-open .nav-links{display:flex}'
                . 'nav .nav-links button{width:100%;justify-content:flex-start;text-align:left;white-space:nowrap;font-size:15px;padding:11px 12px}'
                . 'nav .nav-links .boxed,nav .nav-links .primary{justify-content:center;text-align:center;margin:6px 0 0}'
                . '#view-results .nav-row{flex-direction:column;align-items:stretch}'
                . '#view-results .nav-row>div{display:flex;flex-direction:column;gap:10px}'
                . '#view-results .nav-row>div:last-child>.btn.gold{order:-1}'
                . '#view-results .nav-row .btn{width:100%;justify-content:center;text-align:center}'
                . '.comps::before{left:4px}'
                // Footer: the page's 20px side margin (it kept the desktop 40px),
                // its three parts stacked, and each link whole — "Scorecard
                // privacy notice" was splitting across lines, the "|" left
                // dangling.
                . 'footer{margin-top:48px;padding:24px 20px 28px;flex-direction:column;align-items:flex-start;gap:10px;font-size:13px;line-height:1.5}'
                . 'footer .legal{flex-direction:column;align-items:flex-start;gap:10px}'
                . 'footer .legal .copy{white-space:normal}'
                . 'footer .legal>span:last-child{display:flex;flex-wrap:wrap;gap:8px 18px}'
                . 'footer .legal .sep{display:none}'
                . 'footer .legal a{white-space:nowrap;font-size:11px;letter-spacing:.1em}'
                . '}'
                // The questions' (i) tooltips are 260px wide and anchored at
                // the icon; on a phone that ran 106px off the screen (the
                // questionnaire scrolled sideways, and a tapped tooltip was cut
                // off). Here they span the caption's own row instead.
                . '@media (max-width:700px){.lk-cap{position:relative}.lk-info{position:static}'
                . '.lk-lo .lk-info::after,.lk-hi .lk-info::after{left:0;right:0;width:auto}'
                // Phone text sizes (owner's request: "make font size also
                // smaller for mobile"), each a step down from the desktop size.
                // Form fields stay 16px on purpose: below that, iPhone Safari
                // zooms the whole page in when a field is tapped.
                . 'body{font-size:15px}'
                . 'nav .logo{font-size:16px}'
                . '.hero h1{font-size:30px;margin-bottom:18px}.hero p{font-size:15px;margin-bottom:24px}.hero .cta .btn{font-size:14px}'
                . '.product-name{font-size:21px}.index-line{font-size:12px}'
                . '.comps-head span{font-size:19px}.comp .n{font-size:18px}.comp .q{font-size:14px}'
                . '.ladder-row .lr .t{font-size:19px}.ladder-row .lr .d{font-size:14px}'
                . 'h2.title{font-size:24px}h3.sub{font-size:19px}.lede{font-size:15px;margin-bottom:20px}.eyebrow{font-size:13px}'
                . '.btn{font-size:15px}.btn.sm{font-size:14px}'
                . '.dim-head h2{font-size:24px}.board-q{font-size:16px}.guide-body{font-size:15px;padding:16px 18px}.guide-tabs button{font-size:12px;padding:8px 10px}'
                . '.item .q .txt{font-size:16px}.lk-cap{font-size:14px}.rb .n{font-size:16px}'
                . '.level{font-size:56px}.meanbox div .v{font-size:24px}.brow .k{font-size:14px}.brow .v{font-size:15px}'
                . '.callout h4{font-size:18px}.callout p{font-size:15px}.analysis{font-size:16px}.cad .c .v{font-size:17px}'
                . '.modal h3{font-size:20px}.checks label{font-size:15px}'
                . '.stat .v{font-size:26px}.rec .t{font-size:15px}.rec .s{font-size:22px}.chart-card h4{font-size:17px}.empty b{font-size:18px}'
                . '.tabs button,.dash-tabs button{font-size:14px}.toast{font-size:14px}'
                . '}',
            'phones only: header, footer, results buttons, tooltips, home timeline, smaller text (inside the page\'s own stylesheet, after its 900px block)',
        ],
        /*
         * The header's logo and the phone header's menu button.
         *
         * Logo (owner's request, 9 Oct 2026): the supplied "RAI—X" becomes
         * "AI Director Readiness — The Enterprise Scorecard", in the markup the
         * other scorecards' logos use (name, <span> — </span>, family name).
         *
         * Menu button: `hidden`, so a wider screen never shows it (no style
         * outside the phone media query touches it); at 900px and narrower the
         * CSS above displays it. Its three bars become an X while the menu is
         * open.
         */
        322 => [
            'RAI<span>—</span>X</button>',
            'AI Director Readiness<span> — </span>The Enterprise Scorecard</button><button class="raix-menu" type="button" hidden aria-label="Open menu" aria-expanded="false" aria-controls="navLinks" onclick="raixMenu()"><span></span><span></span><span></span></button>',
            'header: the logo reads "AI Director Readiness — The Enterprise Scorecard"; phones: the header menu button',
        ],
        /*
         * The hero's list, sent filled in (page speed, 12 Oct 2026; a KNOWN
         * DEVIATION to report to SHADOKA with the others). The supplied page sends #heroComps empty and fills it from
         * its script at the very end of the page, after ~570KB of Chart.js and
         * jsPDF have been parsed. On a desktop the two-column hero then grows
         * by ~700px and its vertically centred left column drops 32px: the
         * layout shift PageSpeed reports (~0.2; "good" is under 0.1). HERO_COMPS
         * is exactly what that script writes (captured from the live page), so
         * its later innerHTML assignment changes nothing on screen.
         */
        354 => [
            'id="heroComps" aria-label="The seven competencies"></div>',
            'id="heroComps" aria-label="The seven competencies">' . self::HERO_COMPS . '</div>',
            'the hero list is sent filled in, so the hero no longer jumps when the script fills it',
        ],
    ];

    /** What the page's own script writes into #heroComps (see the line-354 patch). */
    private const HERO_COMPS = '<div class="comp"><div class="c">GOV</div><div class="n">Governance Oversight</div><div class="q">“Who is accountable for AI here, and when did this board last make a decision about it?”</div></div><div class="comp"><div class="c">DAT</div><div class="n">Data Inquiry</div><div class="q">“What data goes into our AI, where does it go, and how do we know?”</div></div><div class="comp"><div class="c">EXP</div><div class="n">Explanation &amp; Disclosure</div><div class="q">“Could we explain this decision to the person it was made about?”</div></div><div class="comp"><div class="c">FAI</div><div class="n">Fairness &amp; Affected Parties</div><div class="q">“Who could this system treat worse than others, and when did we last check?”</div></div><div class="comp"><div class="c">RIS</div><div class="n">Risk &amp; Irreversibility</div><div class="q">“What can this system do that we cannot undo, and how would we find out?”</div></div><div class="comp"><div class="c">HUM</div><div class="n">Human Judgment &amp; Workforce</div><div class="q">“Can a person still overrule this system, and does anyone actually do so?”</div></div><div class="comp"><div class="c">AUT</div><div class="n">Autonomy &amp; Agent Authority</div><div class="q">“What are our agents allowed to do on their own, and what stops them?”</div></div>';

    /**
     * The sign-in the Admin dialog now performs, on one line so it can ride on
     * the checkPin() patch. The same two requests the platform's own sign-in
     * page makes: fetch a CSRF token from /admin/login, post the password to
     * /admin/pin for this app. Its errors (wrong password, too many attempts)
     * come from the server and are shown under the field.
     */
    private const SIGN_IN_JS = " const RAIX_ADMIN='{{ADMIN}}',RAIX_APP='{{APP}}';"
        . " async function raixServerSignIn(){ const input=document.getElementById('pin'),err=document.getElementById('pin-err'); const pin=((input&&input.value)||'').trim();"
        . " if(!pin){ err.textContent='Enter the password.'; return; } err.textContent='';"
        . " try{ const r1=await fetch(RAIX_ADMIN+'/login?app='+encodeURIComponent(RAIX_APP),{headers:{Accept:'application/json'},credentials:'same-origin'}); const j1=await r1.json();"
        . " if(!(j1.data&&j1.data.authenticated)){ const r2=await fetch(RAIX_ADMIN+'/pin',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:new URLSearchParams({_token:j1.data.csrf,pin:pin,app:RAIX_APP})});"
        . " const j2=await r2.json().catch(()=>({})); if(!r2.ok||!j2.success){ err.textContent=j2.message||'Sign-in failed.'; return; }"
        . " if(j2.data&&/\\/admin\\/password$/.test(j2.data.redirect||'')){ location.href=j2.data.redirect; return; } } }"
        . " catch(e){ err.textContent='The server could not be reached. Try again.'; return; }"
        . " adminOK=true; updateNav(); closeModal(); const dest=pendingAdminView; pendingAdminView=null; go(dest||'analytics'); }"
        . " function raixServerSignOut(){ fetch(RAIX_ADMIN+'/logout',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',redirect:'manual',body:'app='+encodeURIComponent(RAIX_APP)}).catch(()=>{}); }";

    /**
     * The only requests the page may make are to its own host (requirement 2),
     * and the browser is told so. The supplied page has everything inline or as
     * a data: URI; it is SENT with its fonts, hero image and libraries as files
     * on this host (Platform\Core\PageAssets), hence 'self'. Downloads are blob: links.
     */
    public const CSP = "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'unsafe-inline'; "
        . "font-src 'self' data:; img-src 'self' data: blob:; connect-src 'self'; "
        . "base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    /** The in-page Requests view's script, inlined by the line-2178 patch. */
    public const REQUESTS_JS = 'page/patches/requests.js';

    /**
     * The share card: the Open Graph and Twitter tags social_meta() writes for
     * every app (the banner is apps/{slug}/assets/social/banner.jpg), added to
     * line 6, the <title> line, on its end (owner's request, 5 Oct 2026: a
     * share card on every app). Crawlers do not run JavaScript, so the tags
     * have to be in the served HTML. Another KNOWN DEVIATION, to be reported to
     * SHADOKA with the others; the page's own text is not touched.
     *
     * Not part of html() on purpose: the tags name the visitor's host, so
     * adding them only as the page is sent keeps the copy C5 archives one
     * fixed file however the site is reached.
     * The card's description is the Director Scorecard's own, word for word:
     * the manifest's describes this edition to staff, not to a reader.
     */
    public const TITLE_LINE        = 6;
    public const SHARE_DESCRIPTION = 'A self-assessment for an individual board member across 7 Responsible AI competencies.';

    /** $html (from html()) with the share card on the end of the <title> line. */
    public function withShareCard(string $html): string
    {
        $lines = explode("\n", $html);
        $line  = $lines[self::TITLE_LINE - 1] ?? '';
        $eol   = str_ends_with($line, "\r") ? "\r" : '';

        if (!preg_match('#^<title>([^<]+)</title>$#', rtrim($line, "\r"), $m)) {
            throw new RuntimeException('Line ' . self::TITLE_LINE . ' is not the <title> line.');
        }

        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // One line, so the page still differs from the supplied file on line 6 only.
        // The meta description too: the supplied page has none (PageSpeed's SEO
        // check flags it) and the platform's own layouts write one.
        $tags  = '<meta name="description" content="' . e(self::SHARE_DESCRIPTION ?: ($this->app->description ?? '')) . '">'
               . str_replace("\n", '', social_meta($this->app->slug, $title, self::SHARE_DESCRIPTION)) . favicon_data_link();

        $lines[self::TITLE_LINE - 1] = rtrim($line, "\r") . $tags . $eol;

        return implode("\n", $lines);
    }

    public function __construct(private readonly AppManifest $app)
    {
    }

    /**
     * A patch script as ONE line, so the served page still differs from the
     * supplied file only on the patched lines. The script is written for this:
     * every statement ends in a semicolon and comments are block comments.
     */
    private static function inlineScript(string $path): string
    {
        $js = (string) file_get_contents($path);

        if (stripos($js, '</script') !== false) {
            throw new RuntimeException('A patch script may not contain </script.');
        }

        $js = (string) preg_replace('#/\*.*?\*/#s', ' ', $js);
        $lines = array_filter(array_map('trim', preg_split('/\R/', $js) ?: []), static fn (string $l): bool => $l !== '');

        foreach ($lines as $line) {
            if (str_starts_with($line, '//')) {
                throw new RuntimeException('A patch script may not use // comments (it is joined onto one line).');
            }
        }

        return implode(' ', $lines);
    }

    public function path(): string
    {
        return $this->app->path(self::FILE);
    }

    /** The API address written into line 922: this app's /api — outside the /dev mount when config/apps.php lists it as unmounted. */
    public function apiBase(): string
    {
        return url($this->app->slug . '/api');
    }

    /** The supplied file, verified, with line 922 pointed at $apiBase and PATCHES applied. */
    public function html(?string $apiBase = null): string
    {
        $apiBase ??= $this->apiBase();

        // Written between single quotes in JavaScript: allow nothing that could
        // end the string or the script.
        if (!preg_match('#^(https://[A-Za-z0-9.-]+(:\d+)?)?(/[A-Za-z0-9._~-]+)*$#', $apiBase) || $apiBase === '') {
            throw new RuntimeException('API base is not a plain URL path.');
        }

        $html = (string) file_get_contents($this->path());

        if (strlen($html) !== self::BYTES || md5($html) !== self::MD5) {
            throw new RuntimeException('The scorecard page is not the supplied v14 file.');
        }

        // The supplied file mixes line endings (most lines end CRLF), so the
        // line is compared without its \r, and the \r is put back after it.
        $lines = explode("\n", $html);
        $line  = $lines[self::API_LINE - 1] ?? '';
        $eol   = str_ends_with($line, "\r") ? "\r" : '';

        if (rtrim($line, "\r") !== self::API_LINE_TEXT) {
            throw new RuntimeException('Line ' . self::API_LINE . ' is not the API_BASE line.');
        }

        if ($apiBase !== '/api') {
            $lines[self::API_LINE - 1] = "const API_BASE='" . $apiBase . "';" . $eol;
        }

        // Where the sign-in patch posts: this platform's /admin, and this app.
        $admin = url('admin');

        if (!preg_match('#^(/[A-Za-z0-9._~-]+)*$#', $admin) || !preg_match('/^[a-z0-9-]+$/', $this->app->slug)) {
            throw new RuntimeException('Admin path is not a plain URL path.');
        }

        foreach (self::PATCHES as $number => [$from, $to]) {
            $target = $lines[$number - 1] ?? '';

            if (substr_count($target, $from) !== 1) {
                throw new RuntimeException('Patch for line ' . $number . ' does not match the file.');
            }

            $to = strtr($to, ['{{ADMIN}}' => $admin, '{{APP}}' => $this->app->slug]);

            if (str_contains($to, '{{REQUESTS_JS}}')) {
                $to = str_replace('{{REQUESTS_JS}}', self::inlineScript($this->app->path(self::REQUESTS_JS)), $to);
            }
            $lines[$number - 1] = str_replace($from, $to, $target);
        }

        return implode("\n", $lines);
    }
}
