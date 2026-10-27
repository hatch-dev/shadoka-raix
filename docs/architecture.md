# Architecture

Written for whoever picks this up next — including the version of us that has
forgotten why any of it is shaped this way.

## The shape

RAIX is a platform, not an app. `platform/` holds code that any product would
need; each product lives in `apps/{slug}/` and registers itself through one
manifest. The router, migrator, seeder, test runner and admin console all ask
`AppRegistry` which apps exist rather than knowing any app by name.

Only one app is enabled today. The structure is still the point: nothing in the
router, migrator, seeder, test runner or admin console knows that app by name,
so a second one costs a manifest and a line in `config/apps.php` rather than a
refactor of everything that had assumed there was only ever one.

## Decisions, and what they cost to reverse

### 1. Instrument versioning

**A submission pins to an instrument row. Published instruments are immutable.**

The legacy build hard-coded its seven competencies in JavaScript, and carried a
`LEVEL_ALIAS` map to patch records written before the four rungs were renamed
(`Absent/Stated/Practiced/Verified` → `Unaware/Aware/Practiced/Systematic`).
That map is the scar. Once an admin can edit an item through a UI, an
unversioned schema silently rewrites the meaning of every score ever taken —
and nobody finds out, because old rows keep rendering.

So `ds_rungs` carries its own band cut points rather than reading
`config/scoring.php` at query time: a version keeps scoring the way it scored on
the day it was published, whatever the config later says.

Retrofitting this after real submissions exist means guessing which version each
one was taken under. That guess cannot be made correctly.

### 2. Scoring is server-authoritative

The client posts marks only. `floor_score`, `mean_score`, `level_value` and the
per-competency rows in `ds_submission_scores` are derived server-side.

In the legacy build the browser computed its own score and stored the answer, so
anyone could post any result. The derived columns are stored rather than
computed on read only because analytics would otherwise re-aggregate 35 marks
per record on every query — they are a cache, and the Scorer is the only writer.

### 3. Boards live at platform level

`organisations` is a platform table, not `ds_organisations`.

This is the main payoff of the multi-app design. When the same board is scored
by the Director Scorecard and by an organisation-level instrument, both point at
one row, and a director's floor can be placed beside their board's
organisational score. Give each app its own copy and that join is gone —
you are matching boards by typed name forever.

`match_key` (lowercased, punctuation-stripped) carries the uniqueness, because
the board name is free text a director types.

### 4. Synthetic data is structural

`is_synthetic` is an indexed column on `submissions` and `organisations`, and
every generation run is recorded in `ds_seed_runs` with its seed and options.

Demo records are the most dangerous rows in a research database: they look
exactly like real ones. The legacy build flagged them but re-implemented the
exclusion at each call site, which is a rule you eventually forget somewhere.

**Two things the legacy seeder did that must not be ported:**

- It derived each synthetic director's *"meetings with AI as a decision item"*
  and *"hours of AI education"* from that record's own archetype target, so the
  Correlation tab reported r = +0.49 on a relationship that had been
  manufactured. The `n >= 30` guard did not help — the default seed produced 38
  records and cleared it. The source marks this
  `DEMO SCAFFOLD, REMOVE ON THE NEXT DEVELOPMENT PASS`. Do not generate those
  two fields.
- It timestamped records with `Date.now() - daysAgo` and then forced the year,
  which pushed about one record in ten into the future. Generate dates within
  the target year directly.

### 5. `public/` is the document root

Everything else — `platform/`, `apps/`, `config/`, `database/`, `storage/`,
`.env` — sits above it and is unreachable by URL even if a rewrite rule is
missed or `mod_rewrite` is off.

The root `.htaccess` additionally refuses the source directories. That is
deliberate belt-and-braces: a denial list that is the *only* protection fails
open the moment a directory is added and not listed.

### 6. No instrument wording in code

The seven competencies, 35 statements, 35 evidence prompts, four rungs and all
28 guide panels live in
`apps/raix-director-scorecard/database/seeds/data/instrument-v1.json`, dumped
verbatim out of the legacy `index.html` rather than retyped.

`instrument_integrity_test.php` compares every one of them character for
character against that file — 177 assertions. If a column type ever truncates a
statement, or a seeder edit drops a guide panel, the test says so by name.

## Request lifecycle

```
public/index.php
  └─ platform/bootstrap.php     helpers → autoloader → timezone → error mode
  └─ Router::loadApps()         each app's routes under /{slug} (admin.php under /admin/{slug})
  └─ Router::dispatch()         method + path → [Controller, method]
       └─ falls back to the default app's prefix, so '/assess' reaches it slug-free
  └─ Response::send()           status, headers, security headers, body
```

An app never writes its own slug into a path. That is what keeps the slug — and
later, a move to subdomains — a configuration decision.

## Namespaces

| Prefix | Directory |
|---|---|
| `Platform\` | `platform/` |
| `Apps\RaixDirectorScorecard\` | `apps/raix-director-scorecard/` |

The `Apps\` branch converts StudlyCase back to the kebab-case folder name, so an
app is reachable by namespace before the registry has loaded — which matters,
because the registry is itself a `Platform\` class.

## Config

`config()` reads platform config only. An app's own config is reached through
`$app->config('scoring.rule')`, or `$this->appConfig(...)` inside a controller,
which falls back to platform config when the app declares nothing.

This separation is the point: the floor rule is the Director Scorecard's rule.
A sibling instrument may aggregate entirely differently, and should not have to
share a config file to say so.

### 7. The clock is pinned to one timezone

`Database::alignTimezone()` issues `SET time_zone` on every connection, to the
fixed offset of `config('app.timezone')`.

This is not housekeeping. On the machine this was built on, PHP ran in UTC while
MySQL's `NOW()` returned host-local time — **5.5 hours ahead**. Every comparison
between a PHP-written `DATETIME` and `NOW()` was wrong by that margin. It
surfaced as a rate limiter that never counted past one: rows were being deleted
as "expired more than an hour ago" the instant they were written.

A *fixed offset* is used rather than a named zone because MySQL's named-timezone
tables are usually not loaded on a default install. `tests/clock_test.php` fails
loudly if the two clocks ever drift apart again.

### 8. The token is the credential

A director never authenticates. A submission carries a 32-hex `public_token`,
and that token is the only way to reach it — nothing accepts a submission id
from a client.

The alternative is asking board members to hold an account containing an
unflattering self-assessment of their own competence. That is both a worse
experience and a much larger breach if it ever leaks. The cost is that a lost
token is a lost scorecard, which is the right trade for an anonymous instrument.

For the same reason the public endpoints carry **no CSRF token**: they are
unauthenticated and hold no ambient authority, so a token would protect nothing
while forcing every respondent to accept a session cookie.

### 9. Admin sections come from manifests, gated by capability

The sidebar is assembled by `AdminNav` from every enabled app’s `admin_nav`,
filtered by what the acting role holds. No app is named in the admin shell.

**The console has no sign-in.** It was removed on 22 Sep 2026 at the owner’s
instruction: there is no `/admin/login`, no shared PIN and no password change,
and `/admin` answers to anyone who can reach it. `Auth` still resolves ONE
administrator record — `ADMIN_EMAIL`, or the earliest active row — so every
screen has an actor for the audit trail and a role to build the sidebar from.

Read the capability checks in that light. They decide what the console offers
the identity it runs as; they are no longer a boundary, because nobody is asked
who they are. Narrowing `ADMIN_EMAIL` to a researcher or viewer account is the
only restriction left inside the application; anything stronger belongs in front
of it.

Two consequences worth knowing:

- A section’s **path is derived from its label** — “Research data” becomes
  `/admin/{slug}/research-data`. A section listed in a manifest without a
  matching route is a dead link in everyone’s sidebar, so `admin_access_test`
  asserts every advertised section resolves to a real route.
- Every admin action opens with `guard(capability)` as its first line, rather
  than relying on route metadata. It is visible at the top of the method and
  cannot be lost when a route file is reorganised, which is how ungated admin
  routes usually happen.

### 10. The codebook is generated, never written

`CodebookExporter` builds every field from the published instrument row. That
is a fix for two defects the legacy codebook carried:

- It was hard-coded, and declared `instrument: "RAI-X Responsible AI Scorecard",
  version: "10.0"` while exporting the seven **director** competencies — another
  instrument’s name stamped on this one’s content. Anyone citing it cited the
  wrong instrument.
- It documented a `board_coverage` rule that nothing computed, sending readers
  looking for a field that did not exist.

The rule now: no rule appears in the codebook unless it is implemented, and
nothing is written by hand that the database can state. `export_test` asserts
both — including that the string `board_coverage` does not reappear.

Exports also format numbers consistently. CSV writes every score at 2dp and
JSON uses `JSON_PRESERVE_ZERO_FRACTION`, because without them a single file
carries `1.00` in one column and `1` in the next, and the same JSON field
decodes as int for one record and float for another.

### 11. PDF is the browser’s, not the server’s

The director’s “Download PDF” is `window.print()` over a print stylesheet.
A PHP PDF library is a dependency to keep current, and headless Chrome is a
binary to install and keep running — neither belongs in a XAMPP deployment that
has to hand one director one result.

`print.css` is loaded `media="print"`, which has a trap worth remembering: a
rule written there to hide something **on screen** never applies, because the
sheet is only consulted while printing. The screen-side `.print-footer{display:none}`
therefore lives in `scorecard.css`. Verified by rendering the page through
Chrome with `Emulation.setEmulatedMedia` and printing it.

### 12. Editing means drafting, never editing

`InstrumentVersions` refuses to touch a published or archived version at all.
Editing is: clone the published version into a draft, change the draft, publish
it. Publishing archives the previous version rather than replacing it, because
its submissions still point at it and must keep scoring the way they scored.

Three things the editor deliberately will not do:

- **Change a competency code.** It identifies the competency in every export,
  analytics query and codebook already published; renaming it breaks
  comparability across versions for nothing the name field does not offer.
- **Add or remove statements.** `ds_submission_responses` rows point at item
  ids, and a competency that gains or loses an item has stopped being the same
  measurement. That is a bigger change than an edit, and should be a deliberate
  one.
- **Hold two drafts at once.** They would race to publish, and whichever lost
  would have its edits vanish with no explanation.

`instrument_versioning_test` asserts the negative cases, which are the ones that
matter: a published version refuses edits, and a submission taken under v1.0
still reads v1.0's wording and scores identically after v1.1 is published over
it.

### 13. No declaration without an implementation

An audit on 22 Sep 2026 found the codebase claiming things that did not exist:
an empty `platform/Narrative/` directory, a manifest field `uses` naming two
"toolkits" (one of which had no directory at all) that nothing ever read,
`CLAUDE_API_KEY` and `NARRATIVE_LIVE` in `.env.example` that nothing consumed,
and a `benchmark_min_peers` config key whose feature had never been written.

None of it broke anything, which is exactly why it is worth naming: a false
declaration costs nothing until someone trusts it. The rules now:

- A config key exists only if something reads it.
- A manifest field exists only if something consumes it — `uses` was deleted
  rather than given an artificial consumer.
- An empty directory is not a plan. Create it when the code lands.
- A capability's comment describes what it actually gates. `ds.export` no longer
  claims to cover PDF, which is `window.print()` and not an export route.

### 14. Both PDFs are the browser's

A director's result and the full research report are both print-first HTML, not
generated files. The report lives at `/admin/{slug}/report` behind `ds.export`,
with its own light-ground stylesheet, chosen page breaks, and `thead` set to
`table-header-group` so column headers repeat across pages.

This keeps two dependencies out of a XAMPP deployment — a PHP PDF library to
keep current, and headless Chrome as a running binary — for documents that are
generated occasionally and read once. It also means the report is proofreadable
on screen before it is printed, because it is the same document either way.

The report states its own scope in the document, not just in the UI: an export
including synthetic records carries a red banner saying so, and the footer
repeats it, because a PDF gets emailed on without the screen it came from.

## Still to build

Nothing outstanding. The Director Scorecard is feature-complete against the
legacy single-file build, including year-over-year, the board drilldown, the
per-statement breakdown, the peer benchmark and the research report that were
unported until now.

The one legacy feature deliberately **not** reproduced is the AI-written
analysis. It was switched off in the legacy build too (`LIVE_ANALYSIS = false`),
its `fetch` carried no auth headers so it could never have worked as written,
and the canned floor narratives it fell back to are the ones served today — from
the instrument, per competency. If it is ever wanted, it belongs behind the
server, never as a key in client JavaScript.


## Built and verified

- Kernel, registry, router, migrator, seeder, test runner
- 17 tables; instrument v1.0 seeded from a verbatim dump and checked
  string-by-string (177 assertions)
- Scoring: `FloorRule` + `Scorer`, reproducing the legacy result exactly
- Admin identity, CSRF, rate limiting, audit trail (no sign-in: see 9)
- The public submission API: start, save, submit, reopen, commitments

305 assertions, green from a clean install.
