# Directive: Public Director Scorecard — privacy changes and hosting setup

28 September 2026. This replaces every earlier directive for this scorecard (versions 8 to 12). Do not use any of them.

## 1. What this directive does

The public Director Scorecard has been changed so that it meets European privacy law (the GDPR and the UK GDPR). Some of the changes are in the HTML file. The rest must be made on the server and in the AWS hosting. This directive covers both.

There is one file and one way of running it. You deploy the file as supplied, and it talks to your API. In short, you will:

1. Replace the deployed HTML file with the new file supplied with this directive.
2. Serve the API at `/api` on the same domain as the page. If you serve it anywhere else, change one line (Step 2).
3. Build five API endpoints with the exact behavior described in Part B.
4. Set up storage, logs, deletion, and access as described in Part C.

What the page does:

- **Saving.** Before the questionnaire starts, the person agrees to take part in the research study. When the results page appears, the page sends **one** scorecard to the server, without the person clicking anything. If they review and change their answers, or click a save button, the page sends the same scorecard again with the same `id`. The browser never downloads other people's scorecards.
- **Admin pages.** The admin pages (analytics, research data, exports, demonstration data) read and write through endpoints that must be protected by a server-side login.
- **Unfinished answers.** The only thing the page keeps in the browser is the person's own unfinished answers, in their browser tab, so that a page reload does not lose them. Nothing needs to be done about this.

## 2. The result you are aiming for

These are the requirements. Each one is tested in Section 9.

1. **One record out, three numbers in.** A person completing the scorecard sends exactly one record to the server and receives back only three numbers for the benchmark. Their browser never receives any other person's data.
2. **No third-party requests.** The page makes no requests to any host other than the page's own host. Fonts and the PDF library are inside the file.
3. **Every record carries research consent.** The page asks for one consent, to take part in the research study, before the questionnaire starts, so every record it sends has `org.consent.research` set to `true`. The server must refuse any record without it.
4. **Storage in the United States.** All scorecard data and all backups are stored in an AWS region in the United States.
5. **Logs kept for 30 days.** Every access log and application log is deleted after 30 days, and no log contains a person's name, email, or phone number.
6. **Records deleted within 24 months.** Every record is deleted whole, including from backups and snapshots, no later than 24 months after it was completed.
7. **Individual requests can be answered.** Shadoka staff can find one person's scorecard by email address and then export it, correct it, restrict its use, withdraw consent, or delete it.
8. **Admin data needs a login.** The endpoints that return stored scorecards or change demonstration data work only for logged-in Shadoka staff.
9. **Board name is optional.** The server accepts records with an empty board name and never treats "no board" as a board.

The privacy notice inside the HTML file makes promises to the people who complete the scorecard. Requirements 3 to 7 exist because the notice says these exact sentences:

> Your information is stored by Amazon Web Services, which hosts the scorecard for SHADOKA, in the United States.

> Everything you give, including your answers, is deleted no later than 24 months after you complete the scorecard. Aggregate findings, which identify no one, may be kept and published after that.

> Server logs, which record the address of the device you connect from, are kept for 30 days.

> It will not be sold, will not be shared with third parties for their own purposes, and will not be used for marketing unrelated to this scorecard.

> You may withdraw your consent at any time, and you may ask to see, correct, receive a copy of, restrict the use of, or delete your information, by contacting SHADOKA, LLC at shadoka.com/contact.

If the hosting cannot meet any of these, tell Shadoka before you deploy. Do not change the notice text yourself.

## 3. Files

**New file (replaces the deployed HTML completely):**

`RAIX_Director_Scorecard_public_v13.html` — 2,179 lines, 1,329,403 bytes, md5 `30621ad1`

The page is a single self-contained file. It needs no other files.

## 4. Before you start

**Check 1.** Confirm the supplied file identity: 2,179 lines, 1,329,403 bytes, md5 beginning `30621ad1`.

**Check 2.** Search the file for `fetch(`. There must be exactly five results, and every one must begin `fetch(API_BASE+`. The page calls no other server.

**Check 3.** If you changed an earlier deployed file to connect it to your storage, write down what you changed. **Do not copy those changes into the new file.** The new file's single connection point is line 922 (Step 2), and the server contract in Part B replaces any earlier wiring.

## 5. Part A — the HTML file

### Step 1 (whole file)

Replace the deployed HTML file with `RAIX_Director_Scorecard_public_v13.html`. Do not merge the two files. Keep a dated copy of the old deployed file (see Part C, item C5).

### Step 2 (line 922) — only if the API is not at `/api` on the same domain

The file calls the API at `/api` on the page's own domain. If you serve the endpoints there, make no change. If you serve them somewhere else, point the page there.

**Find:**

```js
const API_BASE='/api';
```

**Replace with:**

```js
const API_BASE='https://YOUR-API-ADDRESS';
```

Replace `https://YOUR-API-ADDRESS` with your real address, with no slash at the end. This is the only edit to the HTML.

### Do not change

- The privacy notice text: the `PRIVACY` block, the function `noticeBody`, and `NOTICE_VERSION` on line 1023. Shadoka changes these.
- The two consent checkboxes and their wording.
- Anything that would add a `<script src=...>`, `<link href=...>`, font, analytics tag, or other request to another host.

## 6. Part B — the server (five endpoints)

All endpoints use HTTPS and JSON. Serving them on the same domain as the page avoids CORS, and we recommend it. If you serve them on a different domain:

- allow requests only from the page's own address;
- answer the browser's `OPTIONS` preflight;
- for the three login endpoints, name the page's exact address (not `*`) and set `Access-Control-Allow-Credentials: true`.

### B1. `POST {API_BASE}/scorecards` — save one scorecard (public)

The page sends this automatically when the results page appears, and again with the same `id` if the person reviews and changes their answers or clicks a save button.

- **Request body:** one JSON object, one scorecard. It is never an array.
- **Server must:**
  - reject anything that is not a single object (respond `400`);
  - reject a body larger than 256 KB (respond `413`);
  - reject the record if `synthetic` is not `false`, if `org.consent.scorecard` is not `true`, or if `org.consent.research` is not `true` (respond `400`). A record without research consent must never be stored;
  - accept an empty `org.org` (the board name is optional);
  - store the object exactly as received;
  - if a record with the same `id` is already stored, **replace** it with the new one. The newer copy carries the person's revised answers;
  - respond `200` or `201` with the body `{}`.
- **Server must not** return any stored data in the response.

**The consent object** inside every record looks like this:

```json
{"notice": "SC-PN-4", "at": "2026-09-28T14:02:11.000Z", "scorecard": true, "research": true, "terms": "https://shadoka.com/terms/"}
```

`notice` is the version of the privacy notice the person saw, and `at` is when they agreed. `research` is always `true` in a record the server accepts.

**Fields in the record.** Store all of them.

| Field | Content |
|---|---|
| `id` | random text identifier, for example `raixsc-mul6w9agvusz` |
| `timestamp` | date and time completed (ISO 8601) |
| `year` | the year completed |
| `synthetic` | always `false` for real scorecards |
| `userName`, `org.name` | full name |
| `userEmail`, `org.email` | email address |
| `userPhone`, `org.phone` | phone number, or empty (phone is optional) |
| `org.org` | name of the board, or empty (optional) |
| `org.consent` | the consent object above |
| `org.role`, `org.tenure`, `org.sector`, `org.size`, `org.year`, `org.systems`, `org.seats`, `org.agenda`, `org.edu`, `org.own` | details about the seat |
| `dims`, `floor`, `floorCode`, `mean`, `level` | scores |
| `responses` | the answers |
| `artifacts` | optional free-text notes the person added to their answers |
| `consequences` | internal flags |

### B2. `GET {API_BASE}/benchmark?floor={number}&exclude={id}` — three numbers only (public)

The results page calls this to show where the person's score sits. It needs no login, because it returns only numbers.

- Use only stored records where:
  - `synthetic` is `false`;
  - the record is not restricted;
  - `floor` is not `null`;
  - `id` is not the `exclude` value.
- Let `n` be the number of those records.
- **If `n` is less than 5**, respond `{"n": n, "median": null, "below": null}`. The page then shows "No benchmark yet".
- **If `n` is 5 or more**, sort their `floor` values from lowest to highest and respond:
  - `n`: the count;
  - `median`: the value at position `floor(n / 2)` in the sorted list, counting from 0;
  - `below`: how many values are strictly less than the `floor` in the request.

Example response: `{"n": 12, "median": 2.6, "below": 4}`

**This endpoint must never return records, names, or any other field.**

### B3. `GET {API_BASE}/records` — stored scorecards (Shadoka staff only)

Only the admin pages call this. The page sends it with `credentials: 'include'`, so the login must work with cookies.

- **Without a valid Shadoka staff login:** respond `401` with no data.
- **With a valid login:** respond with a JSON array containing:
  - every synthetic record (`synthetic: true`);
  - every real record that is not restricted.
- **Never** include restricted records.
- **Login:** use real server-side authentication (for example Amazon Cognito) with named staff accounts only. Multi-factor authentication is strongly advised.

The page still contains an admin PIN screen. It is not a security control. Security comes from the server's login.

### B4. `PUT {API_BASE}/admin/synthetic` — replace the demonstration records (Shadoka staff only)

The admin pages call this to create demonstration data. The page sends it with `credentials: 'include'`.

- **Without a valid Shadoka staff login:** respond `401`.
- **Request body:** `{"records": [ ... ]}`, an array of records in the same shape as B1.
- Reject the request (respond `400`) if any record has `synthetic` other than `true`.
- Delete every stored record where `synthetic` is `true`, then store the new ones. Never touch a real record.
- Respond `200` with `{}`.

### B5. `DELETE {API_BASE}/admin/synthetic` — delete the demonstration records (Shadoka staff only)

- **Without a valid Shadoka staff login:** respond `401`.
- Delete every stored record where `synthetic` is `true`. Never touch a real record.
- Respond `200` with `{}`.

There is no endpoint that deletes real records in bulk, and there must not be one. Deleting real records is done only on the server (see C3 and C4).

## 7. Part C — hosting and data handling

**C1. Region.** Put the data store, its backups, snapshots, and any copies in an AWS region in the United States.

**C2. Encryption.** Encryption at rest must be on for the data store, backups, and snapshots. All traffic uses HTTPS.

**C3. Deletion.** Build one daily job that deletes every record whose `timestamp` is more than 24 months old, as a whole record, including from backups and snapshots.

If backups cannot be cleaned record by record, set every backup and snapshot to expire in less than 30 days, so that deleted records, including those deleted on request, do not survive in them.

**C4. Requests from individuals.** Give Shadoka staff a simple way to find records by email address (`userEmail` or `org.email`) and then:

- **export** the person's record as a JSON file;
- **correct** a field;
- **restrict** the record: set a top-level field `restricted` to `true`. A restricted record is excluded from B2 and B3 and from every export, but is not deleted;
- **withdraw consent:** delete the whole record;
- **delete** the whole record.

Shadoka must be able to finish any request within one month.

**C5. Keep every notice version.** The privacy notice is inside the HTML file, and its version is on line 1023 (`NOTICE_VERSION`, currently `SC-PN-4`). Keep a dated copy of every HTML file you deploy, including the one you are replacing, labeled with the `NOTICE_VERSION` it contains. Each consent record names the version the person saw, so these copies show exactly what each person agreed to.

**C6. Logs.**

- Set a retention of **30 days** on every log that records requests: CloudFront, API Gateway, load balancer, S3 access logs, and Lambda or application logs in CloudWatch.
- Do not write request bodies to any log. The `POST /scorecards` and `PUT /admin/synthetic` bodies contain names and emails. Turn off full request logging on API Gateway, and remove any code that prints a body.

**C7. No added scripts.** Do not add analytics, tag managers, chat widgets, fonts, or monitoring scripts at the hosting layer (for example through CloudFront). The privacy notice does not cover them.

**C8. Access.** Only named Shadoka staff accounts can read the data store or call B3, B4, and B5. Remove any shared or test accounts before launch.

**C9. Incidents.** If you find or suspect that anyone has seen scorecard data without permission, tell Shadoka's named contact **within 24 hours**. Shadoka has 72 hours to report it to the authorities.

## 8. Things that will break the privacy promises

- Storing any record without research consent.
- Returning restricted records from B3, or using them in the benchmark (B2).
- Returning anything except `n`, `median`, and `below` from B2.
- Accepting `synthetic: true` at B1, or anything other than `synthetic: true` at B4.
- Leaving B3, B4, or B5 open without a server-side login.
- Adding any endpoint that deletes real records in bulk.
- Keeping any record, backup, or snapshot past the deletion limit in C3.
- Leaving any log with no expiry, or logging request bodies.
- Loading anything from another host, including fonts and libraries.
- Editing the privacy notice text or the consent wording.

## 9. Acceptance criteria

The work is done when all of these pass.

**The file and the page**

1. The deployed HTML is identical to the supplied file, or differs on line 922 only.
2. Opening the page and completing a scorecard produces network requests only to the page's own host. Check the browser's Network panel with the cache disabled.
3. Before the consent box is ticked, the page makes no request to any host except its own.
4. The results page makes exactly one `GET` to `/benchmark`, and its response contains only `n`, `median`, and `below`.
5. When the results page appears, the page makes exactly one `POST` to `/scorecards` without any click, and its body is a single object. Clicking "Save this scorecard" sends the same `id` again, and the server still holds one record.
6. No public page makes any request to `/records` or `/admin/synthetic`.

**The endpoints**

7. `GET /records`, `PUT /admin/synthetic`, and `DELETE /admin/synthetic` without a staff login return `401` and no data.
8. With four or fewer real scorecards stored, the results page shows "No benchmark yet". With five or more, it shows "Against N other directors".
9. A test record sent to `POST /scorecards` with `org.consent.research` set to `false` is refused with `400` and is not stored.
10. A test scorecard saved with research consent appears in `GET /records`. A test record with a `timestamp` more than 24 months old is deleted by the daily job, including from backups.
11. A test scorecard with an empty board name is accepted at B1 and stored with `org.org` empty.
12. From the admin page, "Default" demo data creates records through `PUT /admin/synthetic`, and "Delete" removes them through `DELETE /admin/synthetic`. No real record changes.

**Requests, storage, and logs**

13. A test record can be found by email, exported, corrected, restricted, and deleted. After restriction it no longer appears in `GET /records`, and withdrawing consent deletes it.
14. Every log group and log bucket shows a 30-day retention, and a test save leaves no name or email in any log.
15. The data store, backups, and snapshots are in a US region, with encryption at rest on.
16. The notice shown before the profile form contains the five sentences quoted in Section 2 exactly, and ends with "Privacy notice version SC-PN-4."

## 10. Checklist by area

**HTML file**
- [ ] Supplied file identity checked (md5 `30621ad1`)
- [ ] Five `fetch(` calls found, all beginning `fetch(API_BASE+`
- [ ] Earlier storage changes noted and not copied across
- [ ] API served at `/api` on the same domain, or line 922 changed; nothing else changed
- [ ] Old deployed file kept with its date and notice version

**Endpoints**
- [ ] B1 `POST /scorecards`: single object only, 256 KB limit, rejects `synthetic` not `false`, `scorecard` not `true` and `research` not `true`, accepts an empty board, same `id` stored once, responds `{}`
- [ ] B2 `GET /benchmark`: unrestricted real records only; three numbers only; `n < 5` returns nulls; median at position `floor(n/2)`
- [ ] B3 `GET /records`: `401` without login; returns synthetic records plus unrestricted real records only
- [ ] B4 `PUT /admin/synthetic`: login; synthetic records only; replaces the synthetic set
- [ ] B5 `DELETE /admin/synthetic`: login; deletes synthetic records only
- [ ] No bulk-delete endpoint for real records
- [ ] CORS limited to the page's address, or API served on the same domain

**Data store, backups, snapshots**
- [ ] US region
- [ ] Encryption at rest on
- [ ] Daily deletion job: every record deleted 24 months after completion
- [ ] Backups and snapshots cleaned, or set to expire in under 30 days

**Requests**
- [ ] Find by email; export, correct, restrict (`restricted: true`), delete (including on withdrawal of consent)

**Logs**
- [ ] 30-day retention on CloudFront, API Gateway, load balancer, S3, and CloudWatch logs
- [ ] No request bodies logged

**Hosting layer**
- [ ] No added scripts, tags, or fonts
- [ ] HTTPS only

**Operations**
- [ ] Named staff accounts only; shared and test accounts removed
- [ ] Incident contact agreed; 24-hour reporting to Shadoka in place
