# RAI-X

A multi-app platform. RAIX is the shared kernel; each product lives in its own
folder under `apps/` and plugs in through a single manifest file.

Currently mounted:

- **The Director Scorecard** (`apps/raix-director-scorecard`, served at `/`) — a
  self-assessment for an individual board member across seven Responsible AI
  competencies. A director's level is their **weakest** competency.
- **The Enterprise Scorecard** (`apps/enterprise-ai-scorecard`, served at
  `/enterprise-ai-scorecard`) — a self-assessment of an **organisation's**
  readiness for AI across six dimensions, thirty questions on a five-point
  scale. An organisation's level is the band of its **overall average**; its
  weakest dimension is reported alongside and does not cap the level. The
  public page (v33) keeps scorecards in `es_records`; since 5 Oct 2026 the
  admin console reads `Services/RecordMirror`'s copy of them in
  `es_submissions`, as the AI Sovereignty Scorecard does (first deploy:
  `php apps/enterprise-ai-scorecard/scripts/mirror-records.php`).
- **The Director Scorecard (Public)** (`apps/raix-director-scorecard-public`,
  served at `/raix-director-scorecard-public`) — since 29 Sep 2026 this is
  Shadoka's supplied privacy build, run to
  `reference/Directive_Privacy_DEV_Public_Director_Scorecard_v13_28Sep2026.md`:
  - **Page.** `page/RAIX_Director_Scorecard_public_v14.html` — v13 plus
    `reference/Directive_DEV_Automatic_Saving_All_Scorecards_28Sep2026.md`: the
    page saves itself when the results appear and re-sends the same `id` after
    "Review answers" or a save button — served byte for
    byte except line 922 (`API_BASE`, pointed at this app's `/api`) and the bug
    fixes in `ScorecardPage::PATCHES`. That is a known deviation, approved
    29 Sep 2026:
    - CSS in the profile form:
      - line 133: field errors overlapped the hint;
      - line 144: an erroring select lost its arrow;
      - line 180: an erroring select's option list opened unreadable.
    - Requests inside the page (lines 328, 380, 1483, 2178): a Requests menu
      entry, "Board not given" rows in Research data opening a scorecard modal,
      a view, and `page/patches/requests.js` inlined before `</body>`. It
      talks to `/api/admin/requests/…` (RequestsApiController, admin only).
    - Admin sign-in on the server (lines 984, 1526, 1527, 1532, 1877): the
      Admin dialog and Exit sign in and out of the server, and the text that
      called the gate "demo-grade" is corrected.

    Report these to SHADOKA. Served with a CSP that allows no other host. The file is size- and md5-checked on every
    request and refused rather than served if it has changed.
  - **API.** The directive's five endpoints over one table, `dsp_records`
    (each record stored exactly as received; a repeat of a stored `id`
    replaces it with the revised copy): `POST /api/scorecards`,
    `GET /api/benchmark` (public), and `GET /api/records`,
    `PUT|DELETE /api/admin/synthetic`, which need this app's admin sign-in.
  - **Admin.** The page's Admin dialog signs in to this app's admin on the
    server with the app's password (patched; the supplied file only compared a
    PIN in JavaScript), and its Exit signs out there too. Signing in at `/admin`
    works as well, since it is the same session. **Requests** finds a
    person's records by email and exports, corrects, restricts or deletes them.
  - **Admin console (since 5 Oct 2026).** Analytics, Research data, the
    exports, the board view and the Data seeder are routed again, as on the
    AI Sovereignty and Enterprise Scorecards. They read `dsp_submissions`,
    which `Services/RecordMirror` keeps in step with `dsp_records`: every save,
    re-save, correction, restriction and deletion reaches it (a restricted
    record has no copy), and each seeded demo row gets a `syn-s{id}` record so
    the page and the console show one demo set. `record_id` tags each copy;
    rows without one are the previous edition's. Wipe never deletes a
    scorecard the page saved. After a restore or a first deploy run
    `php apps/raix-director-scorecard-public/scripts/mirror-records.php`. The
    instrument editor stays unrouted: the page carries its own questions.
  - **Retention.** Records are deleted 24 months after completion by
    `apps/raix-director-scorecard-public/scripts/retention.php` (schedule it
    daily), which the page also runs at most once a day. The same job trims
    every log in `config/logging.php` to 30 days and redacts email addresses
    in what it keeps (`Platform\Core\LogRetention`). Under XAMPP that is only
    `storage/logs`: `SERVER_LOG_DIR` and `PHP_LOG_DIR` are left empty because
    those logs are shared by every local site. Set them on a dedicated host.
  - **Notice copies (C5).** The first time a page with a new md5 is served, a
    dated copy of it, labelled with its `NOTICE_VERSION`, is kept in
    `storage/archive/notice-versions/`, beside the supplied file and the
    replaced edition's notice. That folder's README explains each file.
  - **The previous edition.** Its public flow is still present, unrouted; its
    `dsp_submissions` rows (no `record_id`) still show in the console.
- **The AI Sovereignty Scorecard** (`apps/ai-sovereignty-scorecard`, served at
  `/ai-sovereignty-scorecard`) — ported from `AI_Sovereignty_Scorecard/index.html`,
  which is the Enterprise Scorecard's build with a different instrument: four
  sovereignty dimensions (data, infrastructure, decisional, adaptive), sixteen
  questions on a five-point scale, the same overall-average rule, consent gate
  and required identity. Its own `sov_` tables and its own admin. Matched to its
  reference exactly, including its JSON export and codebook shapes.
- **The AI Executive Readiness Scorecard** (`apps/ai-executive-readiness-scorecard`,
  served at `/ai-executive-readiness-scorecard`) — ported from
  `AI_Executive_Readiness_Scorecard/index.html`. A PAIRED instrument: four
  dimensions, twelve questions, each rated twice — for the respondent and for
  their organization's leaders — so one questionnaire is 24 ratings and yields
  two profiles (each level = band of that profile's overall mean) plus the
  self-minus-leaders difference. Its own `exr_` tables (every rating and score
  row carries a `scope`) and its own admin with five Analytics panes, including
  Comparison. The admin screens, exports and PDFs are computed from reference-
  shaped records (`Services/Records.php` + `Services/Stats.php`), mirroring the
  reference's own functions.

The two rules being opposites is the point, and it is why `ScoringRule` is an
interface: the platform owns the mechanics, each instrument owns its rule. See
`apps/enterprise-ai-scorecard/Services/Scoring/MeanRule.php` for the argument.

---

## Requirements

PHP 8.2+ with the `mbstring` and `pdo_mysql` extensions (on Ubuntu:
`sudo apt install php8.2-mbstring php8.2-mysql`, for the CLI and the web
server's PHP alike), MySQL/MariaDB 10.4+, Apache with `mod_rewrite`. Composer is optional —
nothing in the kernel depends on it.

## Install

```bash
cp .env.example .env          # then set DB_* and APP_URL
php scripts/migrate.php --install
php scripts/seed.php          # creates the admin identity the console runs as
php scripts/test.php
```

> **The admin console has no sign-in.** `/admin` and every section under it
> answer without a credential: the sign-in screen, the shared PIN and the
> password change were removed on 22 Sep 2026 at the owner's instruction.
> The console acts as one administrator record (`ADMIN_EMAIL`, or the
> earliest active one) for the audit trail and for the role its sidebar is
> built from. Capabilities shape what it shows; they do not keep anyone out.
> Anything that must restrict it belongs in front of this application — the
> web server, the network, or a proxy.

Point a vhost at `RAIX/public`. Under plain XAMPP the root `.htaccess` mounts
the platform at **`http://localhost/RAIX/dev/`** — no vhost, and no `/public` in
the URL — and refuses the source directories. `/RAIX/` itself is a 302 to the
mount; 302 and not 301, because `/dev` is temporary. `public/` as the document
root is still the intended deployment.

The mount is named twice and the two must agree: `^dev/(.*)$ → public/$1` in the
root `.htaccess`, and `APP_MOUNT=dev` in `.env`. `SCRIPT_NAME` says
`/RAIX/public` whichever way the request arrived, so nothing in PHP can discover
the segment on its own; with `APP_MOUNT` unset, every link and every routed path
silently loses `/dev`.

Only the platform is mounted. `RAIX_Director_Scorecard/`,
`RAIX_Director_Scorecard_Public/`, `Enterprise_AI_Scorecard/`,
`AI_Sovereignty_Scorecard/` and `AI_Executive_Readiness_Scorecard/` are standalone
single-file builds that Apache serves directly, and no rewrite may touch them —
the root `.htaccess` used to end in a catch-all into `public/`, which 404'd them.

Links are generated relative to however the site was reached: `base_url_path()`
drops a trailing `/public` from `SCRIPT_NAME` when the request did not come
through it and puts `APP_MOUNT` back in its place, so `/RAIX/dev/...` and
`/RAIX/public/...` each produce self-consistent pages.

### Moving an app out of `/dev`

Apps can leave the mount one at a time. An app listed in `config/apps.php`
`'unmounted'` answers at `/RAIX/{slug}` and its console at `/RAIX/admin/{slug}`;
its old `/RAIX/dev/...` addresses answer **301** (308 for a POST) with the path
and query kept. **Adding the slug is the whole move — there is nothing to run**,
here or on the server.

How: the root `.htaccess` names no app. It sends every app-shaped path outside
the mount (`^[a-z0-9]+(-[a-z0-9]+)*(/.*)?$`, real files and folders excepted) to
the front controller, and `Platform\Core\MountGuard` reads the list per request —
a request on the wrong side is redirected to the right one (a moved app's old
`/dev` address 301s out; `/RAIX/enterprise-ai-scorecard`, while that app still
lives under `/dev`, 302s in), so no app ever answers at two addresses.

**Removing the slug moves the app back, and that is safe.** Every one of these
redirects carries `Cache-Control: no-store`. Without it a browser keeps a 301
forever and stops asking: an app moved out and back again sent everyone who had
followed the first redirect to the outside address, by their own browser, where
the server could no longer correct it (30 Sep 2026 — the fix for anyone already
caught is clearing the browser's cache). No call site changes: `url()` decides the
mount from the TARGET path (`path_is_mounted()`), so links to a moved app are
right from every page. What stays under `/dev`: published assets (one address for
every app) and the default app, which also answers unslugged paths and cannot be
listed. The shared admin endpoints (`/admin` and `/admin/login|pin|logout|password`)
are served on both sides of the mount, and links to them stay on the side the
current page is on.

## Admin passwords — one per app

Each app has its **own** admin. `seed.php` creates, per app, a role
(`{slug}-admin`) holding only that app's capabilities and one account in it
(`admin@{slug}.raix.local`). Each app's public **Admin** dialog asks for that
app's password and signs in as that app's account, so one app's password never
opens another app's console. Signed in to one app and opening the other sends
you to the other's sign-in.

| | Where |
|---|---|
| Password | `settings` row `app.{slug}.pin_hash` (hashed, case-sensitive) |
| Account it signs in as | `settings` row `app.{slug}.admin_user_id` |
| Change it | that app's console › **Settings** (asks for the current one) |
| Strength | new passwords: 8–64 characters with an uppercase letter and a number — `Auth::PASSWORD_RULES`, enforced by the server, the Settings checklist and the reset script alike |
| Forgotten | `php scripts/admin-password.php --app={slug}` (prints a new one once, clears lockout) |
| Status | `php scripts/admin-password.php --list` |

Nothing about them is in `.env`.

## Commands

| Command | Does |
|---|---|
| `php scripts/migrate.php` | Apply pending migrations: platform first, then each app |
| `php scripts/migrate.php --pending` | List without applying |
| `php scripts/migrate.php --install` | Create the database, then apply |
| `php scripts/seed.php` | Run every seeder (idempotent) |
| `php scripts/seed.php --app=raix-director-scorecard` | One app only |
| `php scripts/test.php` | Run every `*_test.php` |
| `php scripts/publish-assets.php` | Copy each app’s assets into `public/assets/apps/{slug}/` |

Assets live in `apps/{slug}/assets/` and are published into `public/assets/`.
In development (`APP_DEBUG=true`) the front controller republishes anything
whose source is newer on each page render, so editing a stylesheet and
reloading just works. Run `publish-assets.php` at deploy, or with `--clean` to
force a fresh copy. Asset URLs carry `?v=<mtime>`, so a republished file is a
new URL and cannot be served from cache.
For local work, `php -S 127.0.0.1:8000 -t public public/index.php` serves the
site; the front controller hands real files back to the dev server so published
assets are served the way Apache serves them.

## Layout

```
public/      document root — front controller and published assets, nothing else
platform/    shared kernel: Core, Admin, Narrative, Validation, Support
apps/        ONE FOLDER PER APP; _template/ is the skeleton to copy
config/      platform config (apps.php is the registry)
database/    platform migrations and seeders
storage/     logs, cache, exports — never web-served
scripts/     CLI entry points
docs/        architecture notes
```

## Adding an app

```bash
cp -r apps/_template apps/your-slug
# fill in apps/your-slug/app.php, then add the slug to config/apps.php
php scripts/migrate.php
```

Nothing else in the platform changes. The router mounts the app's routes under
`/your-slug`, the migrator runs its migrations with its table prefix, and the
admin console picks up its nav sections and capabilities from the manifest.

**The slug is frozen once data references it** — it appears in table names,
published asset paths, admin capabilities and public URLs simultaneously.

## Rules this codebase holds to

- **A submission pins to an instrument version.** Published instruments are
  immutable; editing creates a new version. Without this, editing a question
  silently rewrites the meaning of every score ever taken.
- **Scoring is server-side.** The client posts marks only; floor, mean and
  level are derived and written by the server.
- **Boards live at platform level.** `organisations` is shared, so the same
  board scored by two different instruments is one row. Anything that DELETES
  from that table must consider every app's submissions, not just its own —
  see the note under Known issues.
- **Synthetic data is a first-class column**, indexed, excluded by default.
- **No instrument wording lives in code.** Each app seeds it from
  `apps/{slug}/database/seeds/data/instrument-v1.json`, dumped verbatim out of
  that app's reference build and verified by `instrument_integrity_test.php`.
  The Enterprise and AI Sovereignty dumps are reproducible:
  `node apps/{slug}/database/seeds/data/extract-instrument.mjs <out.json>`.

See `docs/architecture.md` for the reasoning behind each.

## Known issues

None open. (Fixed 25 Sep 2026: every app's seed purge, and the Director
Scorecard's Wipe, used to delete demo `organisations` rows that other apps'
records still pointed at, blanking their links through `ON DELETE SET NULL`.
The purge now unions `{slug}_submissions` across `AppRegistry::all()`, and Wipe
goes through the purge.)

## Legacy

`reference/RAIX_Director_Scorecard/index.html` is the original single-file build,
`Enterprise_AI_Scorecard/index.html` is the Enterprise Scorecard's, and
`RAIX_Director_Scorecard_Public/index.html` is the public edition's, and
`AI_Sovereignty_Scorecard/index.html` is the AI Sovereignty Scorecard's, and
`AI_Executive_Readiness_Scorecard/index.html` is the AI Executive Readiness
Scorecard's. Each is the
reference for its app's UI port. Neither is served by the platform, and
the root `.htaccess` deliberately does not rewrite them.
