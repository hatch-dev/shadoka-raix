# Directive: Automatic saving — all four scorecards

28 September 2026.

## 1. What this directive does

Every scorecard must now be saved **automatically as soon as the results page appears**, without the person clicking anything. If the person then reviews and changes their answers, the saved scorecard must be **updated**, not duplicated.

| Scorecard | File you have | What to do |
|---|---|---|
| Director | `RAIX_Director_Scorecard_public_v13.html` (md5 `30621ad1`) | Two one-line edits to the HTML (Part A), one server change, and wording changes to its directive |
| Enterprise | `Enterprise_Scorecard_v32.html` (md5 `ab670f40`) | One one-line edit to the HTML (Part B), and wording changes to its directive. No server change |
| Executive Readiness | `AI_Executive_Readiness_Scorecard_v4.html` (md5 `7607791a`) | **No change.** It already works this way |
| Sovereignty | `AI_Sovereignty_Scorecard_v6.html` (md5 `391f0fc6`) | **No change.** It already works this way |

The edited Director and Enterprise files are supplied with this directive, for checking:

- `RAIX_Director_Scorecard_public_v14.html` — 2,179 lines, 1,329,569 bytes, md5 `12678d77`
- `Enterprise_Scorecard_v33.html` — 2,277 lines, 1,334,762 bytes, md5 `06af2d76`

You may either apply the edits below or replace your file with the supplied one. The result must be the same file, with the same md5.

## 2. The result you are aiming for

1. **Saved on arrival.** When the results page appears, the page sends one record to `POST /scorecards` without any click.
2. **Updated on revision.** If the person clicks "Review answers", changes an answer, and returns to the results, the page sends the scorecard again with the **same `id`**. The server replaces the stored copy, so it holds one record with the changed answer.
3. **Save buttons still work.** "Save this scorecard" sends the same `id` again, and the toast says "Saved". "Save · score another seat" (Director) or "Save · score another organization" (Enterprise) does the same, then starts a new scorecard, which gets a new `id`.
4. **Failure is visible.** If the automatic save fails, the page shows this message: "Your scorecard could not be saved. Click “Save this scorecard” to try again."
5. **Research consent still applies.** As before, a scorecard without research consent is never sent.

## 3. Part A — Director Scorecard

**File:** `RAIX_Director_Scorecard_public_v13.html` (md5 `30621ad1`). This file uses Windows line endings (CRLF). Keep them unchanged. Each edit replaces text within a single line.

### Step A1 (line 1279) — one identifier per scorecard

**Find:**

```js
  return {id:uid('raixsc'), timestamp:new Date().toISOString(), year:A.profile.year, org:A.profile,
```

**Replace with:**

```js
  if(!A.recordId) A.recordId=uid('raixsc'); return {id:A.recordId, timestamp:new Date().toISOString(), year:A.profile.year, org:A.profile,
```

### Step A2 (line 1288) — save when the results appear

**Find:**

```js
  go('results'); renderResults(lastRecord);
```

**Replace with:**

```js
  go('results'); renderResults(lastRecord); saveResearch(lastRecord).catch(()=>toast('Your scorecard could not be saved. Click “Save this scorecard” to try again.'));
```

After both steps, the file differs from the original on lines 1279 and 1288 only, and its md5 is `12678d77`.

### Server change (B1)

In the Director directive, B1 currently says "treat a second request with the same `id` as the same scorecard (store it once, not twice)". The server must now **replace** the stored copy, because the second request carries the revised answers. If your code keeps the first copy and ignores the second, change it.

### Wording changes to the Director directive

Replace these passages in `Directive_Privacy_DEV_Public_Director_Scorecard_v13_28Sep2026.md`.

In **Section 1**, replace:

> - **Saving.** Before the questionnaire starts, the person agrees to take part in the research study. When they save, the page sends **one** scorecard to the server. The browser never downloads other people's scorecards.

with:

> - **Saving.** Before the questionnaire starts, the person agrees to take part in the research study. When the results page appears, the page sends **one** scorecard to the server, without the person clicking anything. If they review and change their answers, or click a save button, the page sends the same scorecard again with the same `id`. The browser never downloads other people's scorecards.

In **B1**, replace:

> The page sends this when the person clicks a save button.

with:

> The page sends this automatically when the results page appears, and again with the same `id` if the person reviews and changes their answers or clicks a save button.

In **B1**, replace:

> - treat a second request with the same `id` as the same scorecard (store it once, not twice);

with:

> - if a record with the same `id` is already stored, **replace** it with the new one. The newer copy carries the person's revised answers;

In **Section 9**, replace criterion 5:

> 5. Clicking "Save this scorecard" makes exactly one `POST` to `/scorecards`, and its body is a single object.

with:

> 5. When the results page appears, the page makes exactly one `POST` to `/scorecards` without any click, and its body is a single object. Clicking "Save this scorecard" sends the same `id` again, and the server still holds one record.

Addendum 1 to the Director directive still applies. Its 24-hour rule also cleans up any duplicates saved before this change.

## 4. Part B — Enterprise Scorecard

**File:** `Enterprise_Scorecard_v32.html` (md5 `ab670f40`). This file already keeps one identifier per scorecard.

### Step B1 (line 1322) — save when the results appear

**Find:**

```js
  go('results'); renderResults(lastRecord);
```

**Replace with:**

```js
  go('results'); renderResults(lastRecord); saveResearch(lastRecord).catch(()=>toast('Your scorecard could not be saved. Click “Save this scorecard” to try again.'));
```

After this step, the file differs from the original on line 1322 only, and its md5 is `06af2d76`.

### Server

No change. The Enterprise directive's B1 already says to replace a record that arrives again with the same `id`.

### Wording changes to the Enterprise directive

Replace these passages in `Directive_Privacy_DEV_Enterprise_Scorecard_v32_28Sep2026.md`.

In **Section 1**, replace:

> - **Saving.** When the person clicks "Save this scorecard" or "Save · score another organization", the page sends **one** scorecard to the server. If the person then uses "Review answers", changes an answer, and saves again, the page sends the same scorecard again, with the same `id` and the updated answers. The browser never downloads other people's scorecards.

with:

> - **Saving.** When the results page appears, the page sends **one** scorecard to the server, without the person clicking anything. If the person then uses "Review answers" and changes an answer, or clicks a save button, the page sends the same scorecard again, with the same `id` and the updated answers. The browser never downloads other people's scorecards.

In **B1**, replace:

> The page sends this when the person clicks a save button, and again with the same `id` if the person reviews their answers and saves again.

with:

> The page sends this automatically when the results page appears, and again with the same `id` if the person reviews and changes their answers or clicks a save button.

In **Section 9**, replace criteria 4 and 5:

> 4. Clicking "Save this scorecard" makes exactly one `POST` to `/scorecards`, and its body is a single object. "Save · score another organization" does the same, then starts a new scorecard.
> 5. Saving, then using "Review answers", changing one answer, and saving again sends a second `POST` with the same `id`. Afterward the server holds one copy of that record, with the changed answer.

with:

> 4. When the results page appears, the page makes exactly one `POST` to `/scorecards` without any click, and its body is a single object. Clicking "Save this scorecard" sends the same `id` again. "Save · score another organization" does the same, then starts a new scorecard with a new `id`.
> 5. Using "Review answers", changing one answer, and returning to the results sends a second `POST` with the same `id`. Afterward the server holds one copy of that record, with the changed answer.

## 5. Part C — Executive Readiness and Sovereignty

No change. Both already save when the results page appears, keep one identifier per scorecard, and their directives already tell the server to replace a record that arrives again with the same `id`. Confirm that criteria 4 and 5 in their directives pass.

## 6. Acceptance criteria

For both the Director and the Enterprise scorecard:

1. Complete a test scorecard with research consent. When the results page appears, exactly one `POST` to `/scorecards` is made, without any click, and the server holds one record.
2. Click "Review answers", change one answer, and return to the results. A second `POST` is made with the same `id`, and the server still holds one record, now with the changed answer.
3. Click "Save this scorecard". A `POST` is made with the same `id`, the toast says "Saved", and the server still holds one record.
4. Click "Save · score another seat" (Director) or "Save · score another organization" (Enterprise), then complete the next scorecard. Its results page saves it automatically under a new `id`, and the server now holds two records.
5. With the server returning an error, reaching the results page shows "Your scorecard could not be saved. Click “Save this scorecard” to try again." When the server works again, clicking "Save this scorecard" saves it, with no duplicate.
6. The edited file's md5 matches the one given in Section 1.

## 7. Checklist

- [ ] Director: Steps A1 and A2 applied, line endings unchanged, md5 `12678d77`
- [ ] Director: B1 replaces a record with the same `id` instead of keeping the first copy
- [ ] Director directive: Section 1, B1, and criterion 5 wording replaced
- [ ] Enterprise: Step B1 applied, md5 `06af2d76`
- [ ] Enterprise directive: Section 1, B1, and criteria 4 and 5 wording replaced
- [ ] Executive Readiness and Sovereignty: criteria 4 and 5 of their directives confirmed passing; no change made
- [ ] All six acceptance criteria above pass for both Director and Enterprise
