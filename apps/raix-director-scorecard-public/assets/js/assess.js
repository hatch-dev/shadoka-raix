/**
 * The assessment: a consent gate, a profile step, then one step per competency.
 *
 * Ported from RAIX_Director_Scorecard_Public/index.html. Differences from that
 * build, all deliberate:
 *
 *  - The questionnaire is fetched (or inlined by the page), not hard-coded.
 *  - Answers are saved to the server as they are given, so closing the tab no
 *    longer loses the scorecard. The reference kept them in sessionStorage.
 *  - Nothing here computes a score. Marks go up, a result comes back.
 *  - The consent gate and the five required fields are enforced by the SERVER.
 *    The checks below run first only so an error can appear next to its field
 *    without a round trip; the server applies the same rules and wording.
 *  - Remit is recorded as context and never skips a competency.
 */

import { api, ApiError, tokenStore, carryForward, consentStore } from './api.js';
import { esc, toast, initReveal, numberWord, modal, closeModal } from './ui.js';
import { openConsentDialog } from './consent.js';

const root = document.getElementById('assessRoot');
const GUIDE_TABS = [
  ['what', 'What it is'],
  ['why', 'Why it matters'],
  ['how', 'How to build it'],
  ['example', 'What it looks like'],
];

/*
 * The fields this edition requires, in the reference's order. Board and
 * company size were made optional, and the role required, on 25 Sep 2026, at
 * the user's request.
 *
 * Declared above the boot IIFE: renderProfile() reads it on the very first
 * render, and a `const` further down the module would still be in its temporal
 * dead zone then (the Enterprise port crashed exactly that way).
 */
const REQUIRED = ['director_name', 'director_email', 'director_phone', 'seat_role'];

const state = {
  instrument: null,
  token: null,
  step: 0,          // 0 = profile, 1..n = competencies
  answers: new Map(), // itemId -> { mark, evidence_note }
  guideIndex: 0,
  saving: false,
  reviewing: false,   // reopened a finished scorecard to change answers
  carry: null,        // board context handed over by "Save · score another seat"
  profile: null,      // what was typed on step 0, so Back can put it back
  board: null,        // the seat being scored, named by the resume prompt
  consent: false,
};

/* ------------------------------------------------------------------ boot -- */

/**
 * The instrument, from the page if it is there.
 *
 * The assessment page inlines it, so the first step can be drawn immediately
 * rather than after a round trip. The fetch stays as a fallback for a page
 * cached from before the payload was inlined.
 */
function inlineInstrument() {
  const element = document.getElementById('instrumentData');
  if (!element) return null;

  try {
    return JSON.parse(element.textContent);
  } catch {
    return null;
  }
}

(async function boot() {
  state.instrument = inlineInstrument();

  // "Save · score another seat" leaves board context behind for the next
  // scorecard. Its presence means a deliberately fresh start, so the token
  // remembered in this browser is dropped rather than reopened — resuming it
  // would send the director straight back to the result they just left.
  state.carry = carryForward.take();

  if (state.carry) {
    tokenStore.clear();
  }

  // ?resume=<token> is an explicit request (the "Review answers" link). A
  // token remembered in this browser is only a convenience, so a FINISHED
  // scorecard found that way goes to its result instead.
  const requested = new URLSearchParams(location.search).get('resume');
  const resume = state.carry ? null : (requested || tokenStore.get());

  // Both requests go out together; usually there is no instrument request.
  const pending = [
    state.instrument ? null : api.instrument(),
    resume ? api.show(resume).catch(() => null) : null,
  ];

  if (!state.instrument) {
    try {
      state.instrument = await pending[0];
    } catch (error) {
      root.innerHTML =
        '<div class="empty"><b>The scorecard could not be loaded</b>' + esc(error.message) + '</div>';
      return;
    }
  }

  if (resume) {
    const existing = await pending[1];

    // An unknown or stale token simply starts a fresh scorecard.
    if (!existing) {
      tokenStore.clear();
      start();
      return;
    }

    const complete = existing.status === 'complete';

    if (complete && !requested) {
      location.href = resultsUrl(resume);
      return;
    }

    state.token = resume;
    state.reviewing = complete;
    state.board = existing.board || null;
    state.profile = existing.profile || null;
    // A scorecard that exists was started, which means its terms were accepted
    // and recorded. Asking again would be asking about a consent already held.
    state.consent = true;

    // Both shapes carry `responses`: a finished scorecard's result payload
    // includes the raw marks precisely so review needs no second request.
    Object.entries(existing.responses || {}).forEach(([itemId, response]) => {
      state.answers.set(Number(itemId), response);
    });

    if (complete) {
      state.step = 1;
      render();
      return;
    }

    if (state.answers.size > 0) {
      offerResume();
      return;
    }

    render();
    return;
  }

  start();
})();

function resultsUrl(token) {
  return document.documentElement.dataset.resultsBase + '/' + encodeURIComponent(token);
}

function homeUrl() {
  return document.documentElement.dataset.homeBase || '/';
}

/**
 * A fresh scorecard opens on the consent gate, as the reference's
 * `startAssessment()` does.
 *
 * The profile step is rendered behind it, so dismissing the dialog leaves the
 * form it gates on screen — and Begin re-opens it, because nothing can be
 * started without it.
 */
function start() {
  state.step = 0;
  render();

  // Already accepted on the way in: the nav and the hero open the gate in place
  // and navigate here on Continue. The server still refuses a scorecard that
  // arrives without acceptance, so trusting this skips nothing.
  if (consentStore.accepted()) {
    state.consent = true;
    return;
  }

  openConsent();
}

/** The gate, for someone who arrived at /assess directly. */
function openConsent() {
  openConsentDialog(state.instrument.consent, {
    cancelHref: homeUrl(),
    onAccept: () => {
      state.consent = true;
      consentStore.accept();
      closeModal();
      render();
    },
  });
}

/**
 * Offer to pick a half-finished scorecard back up — a dialog over the
 * assessment, as in the reference, not a panel that replaces it.
 */
function offerResume() {
  const step = firstUnansweredStep();
  const total = state.instrument.competencies.length + 1;

  state.step = 0;
  render();

  modal(
    '<h3>Resume where you left off?</h3>' +
    '<p class="muted">You have a scorecard in progress for your seat at ' +
    '<b style="color:var(--paper)">' + esc(state.board || 'your board') + '</b>, ' +
    'at step ' + (step + 1) + ' of ' + total + '.</p>' +
    '<div class="nav-row">' +
      '<button class="btn quiet sm" id="startOver">Start over</button>' +
      '<button class="btn gold sm" id="resume">Resume</button>' +
    '</div>'
  );

  document.getElementById('resume').addEventListener('click', () => {
    closeModal();
    state.step = step;
    render();
  });

  document.getElementById('startOver').addEventListener('click', () => {
    closeModal();
    state.token = null;
    state.answers.clear();
    state.profile = null;
    state.board = null;
    state.consent = false;
    tokenStore.clear();
    start();
  });
}

function firstUnansweredStep() {
  const index = state.instrument.competencies.findIndex((competency) =>
    competency.items.some((item) => !state.answers.has(item.id)),
  );

  return index === -1 ? state.instrument.competencies.length : index + 1;
}

/* ---------------------------------------------------------------- render -- */

function render() {
  window.scrollTo({ top: 0 }); // each step opens at the top, not where the last one ended
  state.step === 0 ? renderProfile() : renderCompetency(state.step - 1);
  initReveal();
}

function progress(step) {
  const total = state.instrument.competencies.length + 1;
  const percent = Math.round((step / total) * 100);

  return (
    '<div class="prog"><div class="lbl">Step ' + (step + 1) + ' of ' + total +
    '</div><div class="bar"><i style="width:' + percent + '%"></i></div>' +
    '<div class="lbl">' + percent + '%</div></div>'
  );
}

/* --------------------------------------------------------------- profile -- */

function renderProfile() {
  const reference = state.instrument.reference || {};
  const year = new Date().getFullYear();
  const years = [year - 2, year - 1, year, year + 1];

  /*
   * Two sources, and state.profile wins: it is what THIS director typed, put
   * back when they press Back. state.carry is the "score another seat"
   * hand-off and deliberately holds only sector, size and year — a different
   * seat means the name, contact details, role and tenure are asked again.
   */
  const p = state.profile || state.carry || {};
  const chosenYear = years.includes(p.assessment_year) ? p.assessment_year : year;
  const remit = Array.isArray(p.remit) ? p.remit : [];
  const text = (key) => (p[key] === null || p[key] === undefined ? '' : String(p[key]));

  const options = (values, selected) =>
    ['<option value="">Select…</option>']
      .concat(
        (values || []).map(
          (value) =>
            '<option value="' + esc(value) + '"' +
            (value === selected ? ' selected' : '') + '>' + esc(value) + '</option>',
        ),
      )
      .join('');

  root.innerHTML =
    progress(0) +
    '<div class="eyebrow">Context</div>' +
    '<h2 class="title">Your seat</h2>' +
    '<p class="lede">The scorecard is about you, not the company. A little context about ' +
    'your seat lets your answers be read alongside those of directors in comparable ' +
    'positions. Nothing on this page affects your score.</p>' +
    '<div class="reqnote" style="margin-bottom:24px">Fields marked ' +
    '<span style="color:var(--r1);font-weight:700">*</span> are required, so that the ' +
    'scorecard can be returned to you and compared with your own prior year. Nothing ' +
    'entered here is verified, and none of it is published.</div>' +
    '<form class="form" id="profileForm" novalidate>' +
      field('full', 'director_name', 'Full name',
        '<input class="f" id="director_name" name="director_name" maxlength="120" autocomplete="name" ' +
        'placeholder="First and last name" value="' + esc(text('director_name')) + '">', null, true) +
      field('', 'director_email', 'Email',
        '<input class="f" id="director_email" name="director_email" type="email" maxlength="254" ' +
        'autocomplete="email" placeholder="you@company.com" value="' + esc(text('director_email')) + '">',
        'Where your scorecard is sent.', true) +
      field('', 'director_phone', 'Phone',
        '<input class="f" id="director_phone" name="director_phone" type="tel" maxlength="40" ' +
        'autocomplete="tel" placeholder="+1 555 123 4567" value="' + esc(text('director_phone')) + '">',
        'Include the country code if you are outside the United States.', true) +
      // Directly under Email, ahead of the board (moved on request, 25 Sep 2026).
      field('', 'seat_role', 'Your role on this board',
        '<select class="f" id="seat_role" name="seat_role">' + options(reference.roles, p.seat_role) + '</select>', null, true) +
      field('full', 'board_name', 'The board you are scoring your actions on',
        '<input class="f" id="board_name" name="board_name" maxlength="191" autocomplete="organization" ' +
        'placeholder="e.g. Meridian Capital Group" value="' + esc(text('board_name')) + '">',
        'One scorecard per seat. A director on three boards usually scores differently on each.') +
      field('', 'company_size', 'Company size (employees)',
        '<select class="f" id="company_size" name="company_size">' +
          options(reference.company_sizes, p.company_size) + '</select>') +
      field('', 'assessment_year', 'Assessment year',
        '<select class="f" id="assessment_year" name="assessment_year">' +
          years.map((y) => '<option value="' + y + '"' + (y === chosenYear ? ' selected' : '') + '>' + y + '</option>').join('') +
        '</select>') +
      field('', 'tenure', 'Years in this seat',
        '<select class="f" id="tenure" name="tenure">' + options(reference.tenures, p.tenure) + '</select>') +
      field('', 'sector', 'Sector',
        '<select class="f" id="sector" name="sector">' + options(reference.sectors, p.sector) + '</select>') +
      '<div class="full" style="border-top:1px solid var(--rule-soft);padding-top:16px;margin-top:4px">' +
        '<p class="hint" style="margin:0 0 10px">Optional. Used only to explore associations ' +
        'across the dataset. These are only ever reported once ' +
        esc(numberWord(state.instrument.rules.correlation_min_n ?? 30)) +
        ' or more directors have scored, and only as associations.</p></div>' +
      field('', 'systems_seen', 'How many of the company’s AI systems are you aware of?',
        '<input class="f" id="systems_seen" name="systems_seen" type="number" min="0" max="10000" placeholder="e.g. 12" value="' + esc(text('systems_seen')) + '">',
        'Count the systems you know about, whether from an inventory, a briefing, or a '
        + 'demonstration. If you cannot put a number on it, leave it blank.') +
      field('', 'board_seats', 'Directors on this board',
        '<input class="f" id="board_seats" name="board_seats" type="number" min="1" max="100" placeholder="e.g. 11" value="' + esc(text('board_seats')) + '">') +
      field('', 'agenda_meetings', 'Meetings in the last 12 months with AI as a decision item',
        '<input class="f" id="agenda_meetings" name="agenda_meetings" type="number" min="0" max="100" placeholder="e.g. 2" value="' + esc(text('agenda_meetings')) + '">',
        'Count decision items only. An update in the CEO report is not one.') +
      field('', 'education_hours', 'Hours of AI education you took in the last 12 months',
        '<input class="f" id="education_hours" name="education_hours" type="number" min="0" max="2000" step="0.5" placeholder="e.g. 6" value="' + esc(text('education_hours')) + '">',
        'Count sessions you attended, not sessions the board offered.') +
      '<div class="full"><div class="f" style="display:block;margin-bottom:8px">' +
        'Which competencies fall inside your committee remit?</div><div class="checks" id="remit">' +
        state.instrument.competencies
          .map(
            (c) =>
              '<label><input type="checkbox" value="' + esc(c.code) + '"' +
              (remit.includes(c.code) ? ' checked' : '') + '>' +
              '<span>' + esc(c.code) + ' · ' + esc(c.name) + '</span></label>',
          )
          .join('') +
        '</div><div class="hint">Recorded as context only. You score all ' +
        esc(numberWord(state.instrument.competencies.length)) +
        ' competencies whatever your remit.</div></div>' +
    '</form>' +
    '<div class="formerr" id="profileError"></div>' +
    '<div class="nav-row"><a class="btn quiet" href="' + esc(homeUrl()) + '">Cancel</a>' +
    '<button class="btn gold" id="beginScoring">' +
    (state.token ? 'Continue →' : 'Begin scoring →') + '</button></div>';

  // An error clears as soon as its field is touched, as in the reference.
  REQUIRED.forEach((id) => {
    const element = document.getElementById(id);
    if (!element) return;
    const clear = () => clearField(id);
    element.addEventListener('input', clear);
    element.addEventListener('change', clear);
  });

  document.getElementById('beginScoring').addEventListener('click', beginScoring);
}

/**
 * One form cell.
 *
 * The hint and the error share ONE .fmsg element: the paired cells are a
 * three-row subgrid (label / control / messages), and a fourth child would be
 * painted over the third.
 */
function field(className, id, label, control, hint, required = false) {
  const messages =
    (hint ? '<div class="hint">' + esc(hint) + '</div>' : '') +
    (required ? '<div class="ferr" id="e-' + esc(id) + '"></div>' : '');

  return (
    '<div' + (className ? ' class="' + className + '"' : '') + '>' +
    '<label class="f" for="' + esc(id) + '">' + esc(label) +
    (required ? '<span class="req">*</span>' : '') + '</label>' +
    control +
    (messages ? '<div class="fmsg">' + messages + '</div>' : '') +
    '</div>'
  );
}

/* ---------------------------------------------------- profile validation -- */

/*
 * The reference's checks, in its own words. RespondentIdentity.php applies the
 * same rules on the server; identity_test.php keeps the two in step.
 */
const EMAIL_RE = /^[^\s@,;:<>()[\]\\"]+@[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,24}$/;
const BLOCKED_EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net', 'test.com', 'email.com',
  'mailinator.com', 'yopmail.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
  'temp-mail.org', 'trashmail.com', 'sharklasers.com', 'throwawaymail.com', 'getnada.com',
  'maildrop.cc', 'dispostable.com', 'fakeinbox.com'];

function nameError(value) {
  if (!value || value.length < 2) return 'Enter your full name.';
  if (value.length > 120) return 'That name is too long.';
  if (!/\p{L}/u.test(value)) return 'Enter your name in letters.';
  if (/\d/.test(value)) return 'A name should not contain digits.';
  // No two-part rule — see RespondentIdentity::nameError(). A mononym is a name.
  return null;
}

function emailError(value) {
  if (!value) return 'Enter an email address.';
  if (value.length > 254 || value.includes('..') || !EMAIL_RE.test(value)) {
    return 'That does not look like a valid email address.';
  }
  const domain = value.split('@').pop().toLowerCase();
  if (BLOCKED_EMAIL_DOMAINS.includes(domain) || ['.test', '.invalid', '.example', '.local'].some((s) => domain.endsWith(s))) {
    return 'Use an address you actually read. Disposable and placeholder domains are not accepted.';
  }
  return null;
}

function phoneError(value) {
  if (!value) return 'Enter a phone number.';
  if (/[A-Za-z]/.test(value)) return 'Enter digits only, with an optional leading +.';
  const digits = value.replace(/\D/g, '');
  if (digits.length < 7) return 'That number is too short. Include the area or city code.';
  if (digits.length > 15) return 'That number is too long. Check the digits.';
  if (/^(\d)\1+$/.test(digits)) return 'Enter a real phone number.';
  return null;
}

function clearField(id) {
  const element = document.getElementById(id);
  const error = document.getElementById('e-' + id);
  if (element) element.classList.remove('bad');
  if (error) {
    error.classList.remove('show');
    error.textContent = '';
  }
}

function fieldFail(id, message) {
  const element = document.getElementById(id);
  const error = document.getElementById('e-' + id);
  if (element) element.classList.add('bad');
  if (error) {
    error.textContent = message;
    error.classList.add('show');
  }
}

/** Show every failure at once, and the count in the box under the form. */
function reportFailures(failures) {
  failures.forEach(([id, message]) => fieldFail(id, message));

  const box = document.getElementById('profileError');
  box.textContent = failures.length === 1
    ? 'One required field still needs an answer.'
    : failures.length + ' required fields still need an answer.';
  box.classList.add('show');

  const first = document.getElementById(failures[0][0]);
  if (first) {
    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => first.focus({ preventScroll: true }), 260);
  }
}

function validateProfile() {
  const read = (id) => (document.getElementById(id)?.value || '').trim();

  REQUIRED.forEach(clearField);

  const failures = [
    ['director_name', nameError(read('director_name'))],
    ['director_email', emailError(read('director_email'))],
    ['director_phone', phoneError(read('director_phone'))],
    ['seat_role', read('seat_role') ? null : 'Select your role on this board.'],
  ].filter(([, message]) => message);

  const box = document.getElementById('profileError');

  if (failures.length) {
    reportFailures(failures);
    return false;
  }

  box.classList.remove('show');
  box.textContent = '';
  return true;
}

async function beginScoring() {
  // Nothing may start without the terms. Dismissing the gate leaves the form
  // on screen, so Begin is where it is asked for again.
  if (!state.consent) {
    openConsent();
    return;
  }

  if (!validateProfile()) return;

  const button = document.getElementById('beginScoring');
  const box = document.getElementById('profileError');
  const value = (id) => {
    const element = document.getElementById(id);
    const raw = element ? element.value.trim() : '';
    return raw === '' ? null : raw;
  };

  const number = (id) => {
    const raw = value(id);
    return raw === null ? null : Number(raw);
  };

  const profile = {
    director_name: value('director_name'),
    director_email: value('director_email'),
    director_phone: value('director_phone'),
    board_name: value('board_name'),
    company_size: value('company_size'),
    seat_role: value('seat_role'),
    tenure: value('tenure'),
    sector: value('sector'),
    assessment_year: number('assessment_year'),
    systems_seen: number('systems_seen'),
    board_seats: number('board_seats'),
    agenda_meetings: number('agenda_meetings'),
    education_hours: number('education_hours'),
    remit: [...document.querySelectorAll('#remit input:checked')].map((input) => input.value),
    consent: true,
  };

  const label = button.textContent;
  button.disabled = true;
  button.textContent = state.token ? 'Saving…' : 'Starting…';

  try {
    /*
     * Back to step 0 edits the scorecard that already exists. Starting again
     * would open a SECOND record under a new token and strand every answer
     * already saved against the first.
     */
    if (state.token) {
      await api.saveProfile(state.token, profile);
    } else {
      const started = await api.start(profile);
      state.token = started.token;
      tokenStore.set(started.token);
    }

    state.profile = profile;
    state.board = profile.board_name;
    state.step = 1;
    render();
  } catch (cause) {
    button.disabled = false;
    button.textContent = label;

    // The server names the field; put its message where the field is.
    const fields = cause instanceof ApiError ? cause.errors || {} : {};
    const failures = Object.entries(fields)
      .filter(([id, messages]) => REQUIRED.includes(id) && messages?.length)
      .map(([id, messages]) => [id, messages[0]]);

    if (failures.length) {
      reportFailures(failures);
      return;
    }

    if (fields.consent) {
      state.consent = false;
      consentStore.clear();
      openConsent();
      return;
    }

    box.textContent = cause.message;
    box.classList.add('show');
  }
}

/* ------------------------------------------------------------ competency -- */

function renderCompetency(index) {
  const competency = state.instrument.competencies[index];
  const last = index === state.instrument.competencies.length - 1;
  const promptFrom = state.instrument.rules.evidence_prompt_from;

  state.guideIndex = 0;

  const items = competency.items
    .map((item, position) => {
      const answer = state.answers.get(item.id) || {};
      const mark = answer.mark || '';

      const options = state.instrument.rungs
        .map(
          (rung) =>
            '<option value="' + rung.value + '"' + (mark === rung.value ? ' selected' : '') + '>' +
            esc(rung.name) + ' — ' + esc(rung.description) + '</option>',
        )
        .join('');

      return (
        '<div class="item" id="item-' + item.id + '">' +
        '<div class="q"><span class="num">' + esc(competency.code) + ' ' + (position + 1) +
        '</span><span class="txt">' + esc(item.statement) + '</span></div>' +
        '<div class="rsel-wrap"><select class="f rsel" data-item="' + item.id + '" ' +
        'aria-label="' + esc(item.statement) + '">' +
        '<option value="">Choose a level…</option>' + options + '</select></div>' +
        '<div class="artifact' + (mark >= promptFrom ? ' show' : '') + '" data-artifact="' + item.id + '">' +
        '<label class="f" for="note-' + item.id + '">When, and where? <span class="opt">optional</span></label>' +
        '<input class="f" id="note-' + item.id + '" data-note="' + item.id + '" maxlength="255" ' +
        'value="' + esc(answer.evidence_note || '') + '" ' +
        'placeholder="e.g. Audit committee minutes, Mar 12, item 4 · written question to the CEO, June">' +
        '<div class="hint">Leave it blank if you cannot place it. The mark still counts.</div></div>' +
        '<div class="warn" data-warn="' + item.id + '"></div>' +
        '</div>'
      );
    })
    .join('');

  // The code and the position are separate elements: rendered as adjacent text
  // they read as "GOV1 of 7" to a screen reader, and to anything else parsing
  // textContent.
  root.innerHTML =
    progress(index + 1) +
    '<div class="dim-head" data-competency="' + esc(competency.code) + '">' +
    '<div class="code"><span class="code-mark">' + esc(competency.code) + '</span>' +
    '<span class="code-pos muted">' + (index + 1) + ' of ' +
    state.instrument.competencies.length + '</span></div>' +
    '<div><h2>' + esc(competency.name) + '</h2><p>' + esc(competency.description) + '</p>' +
    '<div class="board-q">Board asks: “' + esc(competency.board_question) + '”</div></div></div>' +
    guideCard(competency) +
    items +
    '<div class="err" id="stepError"></div>' +
    // Reviewing a finished scorecard reopens the answers, not the profile: the
    // server will not move contact details under a reported result.
    '<div class="nav-row">' +
    (state.reviewing && index === 0
      ? '<a class="btn quiet" href="' + esc(resultsUrl(state.token)) + '">← Back to results</a>'
      : '<button class="btn quiet" id="back">← Back</button>') +
    '<button class="btn gold" id="next">' + (last ? 'See results →' : 'Next competency →') + '</button></div>';

  paintGuide(competency);
  bindCompetency(competency, last);
}

function guideCard(competency) {
  return (
    '<div class="guide">' +
    '<div class="guide-tabs" role="tablist">' +
    GUIDE_TABS.map(
      ([key, label], i) =>
        '<button role="tab" aria-selected="' + (i === 0) + '" data-guide="' + i + '"' +
        (i === 0 ? ' class="on"' : '') + '>' + esc(label) + '</button>',
    ).join('') +
    '</div><div class="guide-body" id="guideBody"></div>' +
    '<div class="guide-foot"><button class="guide-nav" data-guide-move="-1" aria-label="Previous">←</button>' +
    '<div class="guide-dots">' +
    GUIDE_TABS.map((_, i) => '<i' + (i === 0 ? ' class="on"' : '') + '></i>').join('') +
    '</div><button class="guide-nav" data-guide-move="1" aria-label="Next">→</button></div></div>'
  );
}

function paintGuide(competency) {
  const [key] = GUIDE_TABS[state.guideIndex];
  const body = document.getElementById('guideBody');
  if (!body) return;

  body.className = 'guide-body' + (key === 'example' ? ' ex' : '');
  body.textContent = competency.guide[key] || '';

  document.querySelectorAll('[data-guide]').forEach((tab, i) => {
    tab.classList.toggle('on', i === state.guideIndex);
    tab.setAttribute('aria-selected', String(i === state.guideIndex));
  });
  document.querySelectorAll('.guide-dots i').forEach((dot, i) => {
    dot.classList.toggle('on', i === state.guideIndex);
  });
}

function bindCompetency(competency, last) {
  document.querySelectorAll('[data-guide]').forEach((tab) => {
    tab.addEventListener('click', () => {
      state.guideIndex = Number(tab.dataset.guide);
      paintGuide(competency);
    });
  });

  document.querySelectorAll('[data-guide-move]').forEach((button) => {
    button.addEventListener('click', () => {
      const step = Number(button.dataset.guideMove);
      state.guideIndex = (state.guideIndex + step + GUIDE_TABS.length) % GUIDE_TABS.length;
      paintGuide(competency);
    });
  });

  document.querySelectorAll('.rsel').forEach((select) => {
    select.addEventListener('change', () => onMark(select));
  });

  document.querySelectorAll('[data-note]').forEach((input) => {
    input.addEventListener('change', () => onNote(input));
  });

  document.getElementById('back')?.addEventListener('click', () => {
    state.step -= 1;
    render();
  });

  document.getElementById('next').addEventListener('click', () => onNext(competency, last));
}

function onMark(select) {
  const itemId = Number(select.dataset.item);
  const mark = select.value === '' ? null : Number(select.value);
  const note = document.querySelector('[data-note="' + itemId + '"]');
  const box = document.querySelector('[data-artifact="' + itemId + '"]');

  if (mark === null) {
    state.answers.delete(itemId);
  } else {
    state.answers.set(itemId, {
      mark,
      evidence_note: note ? note.value : null,
    });
  }

  if (box) box.classList.toggle('show', mark !== null && mark >= state.instrument.rules.evidence_prompt_from);

  const warn = document.querySelector('[data-warn="' + itemId + '"]');
  if (warn) {
    warn.textContent = '';
    warn.classList.remove('show');
  }

  queueSave([itemId]);
}

function onNote(input) {
  const itemId = Number(input.dataset.note);
  const answer = state.answers.get(itemId);

  // A note without a mark has nothing to attach to; the mark comes first.
  if (!answer) return;

  answer.evidence_note = input.value;
  queueSave([itemId]);
}

/* ------------------------------------------------------------ saving ----- */

let pending = new Set();
let saveTimer = null;

function queueSave(itemIds) {
  itemIds.forEach((id) => pending.add(id));

  clearTimeout(saveTimer);
  saveTimer = setTimeout(flushSave, 600);
}

async function flushSave() {
  if (!state.token || pending.size === 0 || state.saving) return;

  const ids = [...pending];
  pending.clear();
  state.saving = true;

  const responses = ids.map((itemId) => {
    const answer = state.answers.get(itemId);
    return {
      item_id: itemId,
      mark: answer ? answer.mark : null,
      evidence_note: answer ? answer.evidence_note : null,
    };
  });

  try {
    await api.save(state.token, responses);
  } catch (error) {
    // Put them back so the next flush retries rather than losing the answer.
    ids.forEach((id) => pending.add(id));
    if (error instanceof ApiError && error.status === 0) {
      toast('Saving failed — your answers are still on this page.');
    }
  } finally {
    state.saving = false;
  }
}

/* -------------------------------------------------------------- advance -- */

async function onNext(competency, last) {
  const unanswered = competency.items.filter((item) => !state.answers.has(item.id));

  competency.items.forEach((item) => {
    const warn = document.querySelector('[data-warn="' + item.id + '"]');
    if (!warn) return;
    const missing = !state.answers.has(item.id);
    warn.textContent = missing ? 'Choose a level to continue.' : '';
    warn.classList.toggle('show', missing);
  });

  if (unanswered.length > 0) {
    const first = document.getElementById('item-' + unanswered[0].id);
    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  clearTimeout(saveTimer);
  await flushSave();

  if (!last) {
    state.step += 1;
    render();
    return;
  }

  const button = document.getElementById('next');
  const error = document.getElementById('stepError');
  button.disabled = true;
  button.textContent = 'Scoring…';

  try {
    await api.submit(state.token);
    location.href = resultsUrl(state.token);
  } catch (cause) {
    error.textContent = cause.message;
    error.classList.add('show');
    button.disabled = false;
    button.textContent = 'See results →';
  }
}
