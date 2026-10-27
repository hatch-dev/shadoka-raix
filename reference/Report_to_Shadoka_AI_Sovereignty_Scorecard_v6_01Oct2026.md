# Report to SHADOKA: AI Sovereignty Scorecard v6 deployment

1 October 2026. In reply to *Directive: AI Sovereignty Scorecard — privacy changes and hosting setup* (28 September 2026).

## 1. Summary

The v6 page is deployed as the public AI Sovereignty Scorecard, and the four endpoints in Part B are live behind it. Every acceptance criterion we can test in the development environment passes.

The directive allows the deployed file to differ from the supplied file on line 1023 only. **Ours differs on twenty-four more lines, and one of them changes when the scorecard is saved** (Section 3d). Section 3 lists each one, with the reason. We ask you to fold them into your next file (Section 6), so that the next deployment needs only line 1023.

Section 4 lists two things we have not done as the directive writes them, and Section 5 lists what remains for the production hosting.

## 2. The file

| Check | Result |
|---|---|
| Supplied file identity | 2,492 lines, 1,322,687 bytes, md5 `391f0fc663410e362133339ef82d7a2a` ✔ |
| `fetch(` calls | exactly four, all `fetch(API_BASE+` ✔ |
| Earlier storage changes | none copied across; the previous edition is archived (C5) |
| Line 1023 | `const API_BASE='/RAIX/dev/ai-sovereignty-scorecard/api';`. The API is on the page's own domain, under the path the platform mounts the app at. |
| Notice text, `noticeBody`, `NOTICE_VERSION` (`SOV-PN-1`), consent wording | unchanged |

The server checks the file's size and md5 before every page view and refuses to serve it if either has changed. The changes in Section 3 are applied as the page is served. The file on disk is never edited.

The page is also served with a `Content-Security-Policy` header that allows requests to its own host only (`default-src 'none'; connect-src 'self'`; fonts and images from `data:`/`blob:` only). This is a server header, not a change to the file. It enforces requirement 2 in the browser.

## 3. Lines that differ from the supplied file

### 3a. Two bugs in the profile form (lines 132 and 144)

The same two bugs were in the Director Scorecard v13 and Enterprise Scorecard v32 files.

**Line 132: a field's error message is drawn on top of its hint.**
Paired profile fields (Email, Phone, Organization size, and so on) sit on a three-row subgrid: label, input, hint. The error message is a fourth child with no row of its own, so "Enter an email address." was drawn over the hint text.

| | |
|---|---|
| Supplied | `grid-row:span 3;row-gap:6px` |
| Deployed | `grid-row:span 4;row-gap:6px` |

**Line 144: a select in the error state loses its arrow, and its option list cannot be read.**
The `background` shorthand resets `select.f`'s `background-image`, so Organization size lost its dropdown arrow when flagged. The error tint is also nearly transparent, so Chrome drew the open option list light, with the options' light text on it.

| | |
|---|---|
| Supplied | `input.f.bad,select.f.bad{border-color:var(--r1);background:rgba(210,86,78,.06)}` |
| Deployed | `input.f.bad,select.f.bad{border-color:var(--r1);background-color:rgba(210,86,78,.06)}select.f option{background:var(--ink-3);color:var(--paper)}` |

### 3b. Admin sign-in on the server (lines 1084, 1547, 1548, 1553, 1895)

In the supplied file, the Admin dialog compares a PIN in JavaScript and never tells the server. Because B2–B4 require a server-side login, `GET /records` answered `401` even after the PIN was accepted, so the admin views stayed empty until staff also signed in somewhere else. The dialog now signs in on the server.

| Line | Supplied | Deployed |
|---|---|---|
| 1553 | `function checkPin(){` | `checkPin()` now calls a new `raixServerSignIn()`, inserted on the same line. It fetches a CSRF token, posts the password to the platform's admin sign-in for this app, and shows the server's error under the field. On success it does what the original did: opens the pending view, or Research data with the admin console. The original PIN comparison is kept, renamed `raixUnusedLocalPinCheck` and never called. |
| 1084 | `function exitAdmin(){ adminOK=false;` | `function exitAdmin(){ raixServerSignOut(); adminOK=false;`. Exit also ends the server session. |
| 1547 | "…It is demo-grade; do not treat it as a security control." | "…Sign in with this scorecard's admin password; it is checked on the server." |
| 1548 | `<label … for="pin">PIN</label>` | `<label … for="pin">Password</label>` |
| 1895 | "The PIN gate is demo-grade; do not treat it as a security control." | "Signed in on the server with this scorecard's admin password." |

`const ADMIN_PIN='RAIX2026'` (line 1473) is still in the file but no longer used. We suggest removing it, because it publishes the old PIN to every visitor.

### 3c. Requests and Settings views inside the page (lines 334, 365, 2491)

C4 asks for "a simple way" for staff to answer requests from individuals. We built it as a view inside the page's own admin area, with a second view for changing the admin password.

| Line | Change |
|---|---|
| 334 | The admin menu gains two buttons after "Data seeder": **Requests** and **Settings**. |
| 365 | Two empty `<section class="view">` containers are added after Research data, for those views to render into. |
| 2491 | One inline `<script>` is added before `</body>`. It renders the two views and talks only to this app's own API on the same host, so it adds no third-party request. |

**What Requests does (C4).** Staff search by email address (`userEmail` or `org.email`). Restricted records are found too. The search is sent as a POST, so the address never appears in an access-log URL. Opening a record allows staff to:

- **export** it as a JSON file;
- **correct** the name, email, phone or organization (both copies of each field are kept in step), or the whole record as JSON. The corrected record must still pass the B1 checks, and the id cannot change;
- **restrict** it: sets `restricted: true`, and the record disappears from B2 and every export but is kept. The restriction can be lifted;
- **delete** it, with the reason recorded as either a deletion request or withdrawn consent. Staff must type DELETE to confirm.

The same screen is also available in the platform's admin console. Every action is written to the audit log with the record id and no personal data.

### 3d. Saving on a button, as on the Enterprise Scorecard (lines 1376, 1438, 1449)

In the supplied file the page sends the scorecard as soon as the results page appears, and offers one button, "Score another organization". At the owner's request, the page now saves **only when the person presses a save button**, as the Enterprise Scorecard v32 page does. It shows the same two buttons as that page.

| Line | Supplied | Deployed |
|---|---|---|
| 1376 | `async function showResults(){`, which shows the results and sends the scorecard | `showResults()` shows the results and sends nothing. The supplied function is kept, renamed `raixUnusedAutoSaveShowResults` and never called. |
| 1438 | one gold button, "Score another organization" | "Save · score another organization" (quiet) and "Save this scorecard" (gold) |
| 1449 | `async function scoreAnotherOrganization(){` | a new `raixSaveThisScorecard()` is defined on the same line, before it |

- **"Save this scorecard"** sends the scorecard and shows "Saved". Pressing it again with nothing changed sends nothing more.
- **"Save · score another organization"** is your own `scoreAnotherOrganization()`. It saves if not yet saved, then shows the consent step before the next organization's questionnaire, as v6 requires.
- **After "Review answers"** the results are unsaved again until the person saves. That save carries the same `id` and replaces the stored copy, as B1 requires.
- **A person who leaves without pressing either button** is not stored at all.

**This departs from the directive**, which says the page sends the scorecard when the results page appears (section 1, "Saving"; acceptance criteria 4 and 5). Section 7 reports both criteria as they now behave. The server side is unchanged: B1 still accepts one object, still refuses a record without research consent, and still replaces on a repeated `id`.

### 3e. The home page flickered as it loaded (lines 8–15, 356)

On the live site the "What is measured" panel flickered while the page loaded. A frame-by-frame recording of a slowed load showed two causes:

1. **The panel's list arrives late.** It is empty in the file (`<div class="comps" id="heroComps">`). `initChrome()` fills it, and `initChrome()` is the last statement of a 1.3 MB script. The browser first paints the panel with no dimensions, its heading centred against an empty list. Then the list appears and the column jumps.
2. **The fonts swap in.** The embedded fonts are `font-display:swap`, so the hero first paints in fallback fonts. When the real fonts arrive the text rewraps (the headline goes from five lines to four), and the panel moves again.

| Line | Supplied | Deployed |
|---|---|---|
| 356 | `<div class="comps" id="heroComps" …></div>` | The four dimensions are written in as the page is served, from the page's own `DIMS`, in exactly the markup `initChrome()` writes. Its later `innerHTML` write therefore changes nothing on screen. |
| 8–14 | `font-display:swap` on each embedded font | `font-display:block`. The fonts are inside the file, so they are ready almost at once and are never swapped in after a fallback. |
| 15 | `</style>` | A small style and script added after it, in `<head>`. They keep the hero hidden (its background still shows) until the embedded fonts are ready, at most 1.5 seconds. The hero then appears once, in its final layout. |

After the fix, the same recording goes from a blank frame straight to the finished page, with nothing moving.

Suggestion for the next file: put the four dimensions in the HTML, and use `font-display:block`, or hold the hero until `document.fonts` is ready.

### 3f. Phone layout (lines 320, 325, 2491)

At the owner's request the page was made to work on phones, **with nothing changed at desktop widths**. The added rules are appended to the page's own `@media (max-width:900px)` block, as more rules inside phone-only media queries. A controlled comparison showed the page pixel-identical with and without them at 1440 px and 1024 px.

At 390 px wide, the screenshots showed these problems:

- **The header covered the page.** It is `position:fixed`, with 64 px reserved under it. On a phone it grew to 130–150 px (a four-line logo beside up to seven buttons) and covered the top of every screen: "Step 1 of 6", the results line, and each admin heading. On the questionnaire it also ran past the screen edge.
- **The results buttons were cramped.** The four buttons sat in two narrow columns with their labels wrapped.
- **The questions' (i) tooltips ran off the screen.** They are 260 px wide and anchored at the icon. On a phone that put them 106 px past the screen edge, so the page scrolled sideways and a tapped tooltip was cut off.
- **The timeline line missed its dots.** On the home page the line ran to the right of the dots.

On phones the page now does this:

- **Header:** one slim bar that stays at the top: the logo and a menu button (☰, added on line 325 with the `hidden` attribute, so wider screens never show it). The button opens the header's buttons as a full-width list below the bar. A choice, a tap outside, or Escape closes it. The script is the last part of the line-2491 patch.
- **Results buttons:** stacked full width, with "Save this scorecard" first.
- **Tooltips:** each spans its caption's row.
- **Timeline:** the line sits under the dots.
- **Footer:** the page's 20 px side margin, its three parts stacked, and each link whole ("Scorecard privacy notice" was splitting across lines).
- **Text:** one step smaller at 700 px and below (body 17 → 15 px, hero headline 38 → 30 px, titles 28 → 24 px, intro text 18 → 15 px). Form fields stay 16 px, because below that iPhone Safari zooms the page in when a field is tapped.

After the change, no screen is wider than the phone, including the admin views.

## 4. Requirements not met as written

**C8 / B2: named staff accounts.** The admin sign-in is server-side and cookie-based, as B2 requires, but it is a **single admin password for this scorecard**, not separate named accounts per member of staff. The password can only be changed from Settings by someone who knows the current one, and repeated wrong attempts are throttled. There is no multi-factor authentication. If you need named accounts or MFA before launch, please tell us.

**The daily deletion job (C3).** A daily job deletes every record whose `timestamp` is more than 24 months old. It also runs at most once a day whenever the page or an endpoint is used, so a missed schedule cannot keep a record past the limit. Each record's deletion clock starts at the earlier of its own `timestamp` and its first arrival at the server, so a future-dated timestamp cannot extend it, and a re-save never moves it later. Backups are covered in Section 5.

## 5. To be done on the production hosting

These can only be set on the production AWS account, not in development. They are listed so that you know they are outstanding.

| Item | Status |
|---|---|
| C1. Data store, backups and snapshots in a US region | to be confirmed on production |
| C2. Encryption at rest on data store, backups and snapshots; HTTPS only | to be confirmed on production |
| C3. Backups and snapshots expire in under 30 days | to be applied on production. A script is ready: it surveys AWS Backup, RDS, EBS and Lightsail, sets retention to 29 days on the resources that hold the scorecard data, and deletes anything older. |
| C3. Daily deletion job scheduled | to be installed on production: the cron entry is ready (daily, 03:20), with a check that reports if the job has not run in 26 hours |
| C6. 30-day retention on CloudFront, API Gateway, load balancer, S3 and CloudWatch logs | to be applied on production. A script is ready: it finds where requests are logged and sets 30-day retention on those log groups and buckets. A log-rotation file keeps the web server's own logs for 30 days. The application's own log is already trimmed to 30 days, with email addresses redacted from what it keeps. |
| C6. No request bodies logged | the application never logs a body; full request logging must stay off at the hosting layer |
| C7. No scripts, tags or fonts added at the hosting layer | none added |
| C9. Incident contact | **please name a contact.** Until one is agreed, we will report to shadoka.com/contact within 24 hours. |

## 6. What we ask of SHADOKA

1. **Fold the twenty-four lines in Section 3 into the next file**, or tell us which of them you do not want. Then the next deployment can differ on line 1023 only, as the directive intends.
2. **Remove `ADMIN_PIN`** from the file (Section 3b).
3. **Confirm whether a single admin password is acceptable** for launch, or whether you need named accounts and MFA (Section 4).
4. **Name an incident contact** for C9.

## 7. Acceptance criteria

Tested on the development server in headless Chrome with the cache disabled, plus automated server tests.

| # | Criterion | Result |
|---|---|---|
| 1 | Deployed HTML identical to the supplied file, or different on line 1023 only | **Differs on line 1023 and twenty-four more lines**: see Section 3 |
| 2 | Requests only to the page's own host | Pass. No other host in a full run, and no CSP violations. |
| 3 | No request to another host before consent | Pass |
| 4 | Results page makes exactly one `POST /scorecards`, body a single object | **Changed (Section 3d):** the results page sends nothing. Pressing "Save this scorecard" makes exactly one `POST`, body a single object. Pressing it again sends nothing more. |
| 5 | Review → change → results sends a second `POST`, same `id`; server holds one copy with the change | **Changed (Section 3d):** after Review → change → results, the second `POST` is sent when "Save this scorecard" is pressed. Same `id`, and the server holds one copy with the change. |
| 6 | No public page calls `/records` or `/admin/synthetic` | Pass |
| 7 | B2, B3, B4 without login return `401` and no data | Pass |
| 8 | Record with `research: false` refused with `400`, not stored | Pass |
| 9 | Test record appears in `GET /records`; a record over 24 months old is deleted by the daily job | Pass, apart from backups, which are a production item (Section 5) |
| 10 | Empty organization name accepted and stored empty | Pass |
| 11 | "Default" demo data via `PUT /admin/synthetic`; "Delete" via `DELETE`; no real record changes | Pass |
| 12 | Find by email; export, correct, restrict, delete; restricted not in `GET /records`; withdrawal deletes | Pass |
| 13 | 30-day retention on every log; no name or email in any log | Application log: pass. Hosting logs: production item (Section 5). |
| 14 | US region, encryption at rest | Production item (Section 5) |
| 15 | Notice has the six sentences exactly and ends "Privacy notice version SOV-PN-1." | Pass |

**C5 (notice versions).** Dated copies are kept of the supplied file, of every page as actually served (labelled `SOV-PN-1`), and of the edition this one replaced, which had no notice version. They are never overwritten or deleted, and they sit outside the 24-month deletion.
