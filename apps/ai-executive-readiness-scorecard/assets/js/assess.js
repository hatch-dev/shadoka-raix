/**
 * The questionnaire: a consent gate, a profile step, then one step per
 * dimension, with every question rated twice — for the respondent and for the
 * organization's leaders.
 *
 * Differences from the reference single-file version, all deliberate:
 *
 *  - The questionnaire is served, not hard-coded. There is one instrument, in
 *    the database.
 *  - Answers are saved to the server as they are given, so closing the tab no
 *    longer loses the scorecard. The reference kept them in sessionStorage.
 *  - Nothing here computes a score. Marks go up, a result comes back. The
 *    browser cannot assert a level.
 *  - Consent is a fact the server records, not a checkbox that enables a button.
 *    The validation below is the reference's, re-run in the browser so an error
 *    lands next to its field; the server runs the same rules and is what decides.
 */

import { api, ApiError, tokenStore, consentStore, progressStore } from './api.js';
import { esc, toast, initReveal, numberWord, modal, closeModal } from './ui.js';
import { openConsentDialog } from './consent.js';

const root = document.getElementById('assessRoot');

/*
 * The five fields the instrument requires.
 *
 * Declared up here, above the boot IIFE, because renderProfile() reads it on the
 * very first render — and a `const` further down the module is still in its
 * temporal dead zone at that point. It threw "Cannot access 'REQUIRED' before
 * initialization" and the page rendered the profile form with no consent gate
 * over it, which looked like a missing feature rather than a crash.
 */
const REQUIRED = [
  'respondent_name',
  'respondent_email',
  'respondent_phone',
  'organisation_name',
  'organisation_size',
];

/* The two ratings each question collects, in the order they are asked. */
const SCOPES = ['self', 'leadership'];

/** One rating's key in state.answers: a question and whose practice it rates. */
function key(itemId, scope) {
  return itemId + ':' + scope;
}

const state = {
  instrument: null,
  token: null,
  step: 0, // 0 = profile, 1..n = dimensions
  answers: new Map(), // "itemId:scope" -> rating
  guideIndex: 0,
  saving: false,
  reviewing: false, // reopened a finished scorecard to change answers
  profile: null, // what was typed on step 0, so Back can put it back
  organisation: null, // named by the resume prompt
  consent: false,
};

/* ------------------------------------------------------------------ boot -- */

/**
 * The instrument, from the page if it is there.
 *
 * The assessment page inlines it, so the first step can be drawn immediately
 * rather than after a round trip. The fetch stays as a fallback: a page cached
 * from before the payload was inlined has no such block, and CSS is cached hard
 * here while JS revalidates, so the two can arrive out of step.
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

  // ?resume=<token> is an explicit request — the "Review answers" link on a
  // finished scorecard uses it. A token remembered in this browser is only a
  // convenience, so a FINISHED scorecard found that way goes to its result
  // rather than silently reopening the questionnaire.
  const requested = new URLSearchParams(location.search).get('resume');
  const resume = requested || tokenStore.get();

  /*
   * Both requests go out together. They are independent, and waiting for the
   * instrument before even asking about the saved scorecard is half of why a
   * page like this sits empty. Usually there is no instrument request at all.
   */
  const pending = [
    state.instrument ? null : api.instrument(),
    resume ? api.show(resume).catch(() => null) : null,
  ];

  if (!state.instrument) {
    try {
      state.instrument = await pending[0];
    } catch (error) {
      root.innerHTML =
        '<div class="empty"><b>The scorecard could not be loaded</b>' +
        esc(error.message) +
        '</div>';
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
    state.organisation = existing.organisation || null;
    // A scorecard that already exists was started, which means its terms were
    // already accepted and recorded. Asking again would be asking about a
    // consent the database already holds.
    state.consent = true;

    // Both shapes carry `answers`: a finished scorecard's result payload
    // includes the raw ratings precisely so review needs no second request.
    (existing.answers || []).forEach((answer) => {
      state.answers.set(key(answer.item_id, answer.scope), answer.mark);
    });

    if (complete) {
      // Reviewing: go straight back into the questionnaire, first dimension.
      state.step = 1;
      render();
      return;
    }

    /*
     * The step it was left on, as the reference's progress blob held it. Only
     * this session knows it; without it, the first dimension still unanswered
     * is the best guess.
     */
    const saved = progressStore.get();
    const step = saved && saved.token === resume ? savedStep(saved.step) : firstUnansweredStep();

    // "Resume" in the dialog is an explicit request: open at that step, and do
    // not ask the same question a second time.
    if (requested) {
      state.step = step;
      render();
      return;
    }

    // Arrived here with only a remembered token — a reload, say. Offer it back,
    // as the reference offered any saved scorecard past its profile step.
    if (step > 0) {
      offerResume(step);
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
 * A fresh scorecard opens on the consent gate.
 *
 * The profile step is rendered behind it, so dismissing the dialog leaves the
 * page showing the form it gates rather than an empty column — and the form's
 * Begin button re-opens it, because nothing can be started without it.
 */
function start() {
  state.step = 0;
  render();

  /*
   * Already accepted on the way in.
   *
   * The nav and the hero open the gate in place and navigate here on Continue,
   * which is the reference's flow. Asking a second time on arrival would be
   * asking about a decision that has just been made.
   *
   * The gate is not skipped by trusting this: the server refuses to start a
   * scorecard that arrives without acceptance, and records the timestamp and
   * the terms itself.
   */
  if (consentStore.accepted()) {
    state.consent = true;
    return;
  }

  openConsent();
}

/** The gate, for someone who arrived at /assess directly. */
function openConsent() {
  openConsentDialog(state.instrument.consent, {
    // Nothing behind this page worth staying on, so Cancel leaves.
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
 * Offer to pick a half-finished scorecard back up.
 *
 * A dialog over the assessment, as in the reference build — not a panel that
 * replaces the page — and over the step it names, which is the screen the
 * reference's dialog sat over when "Start your scorecard" was pressed
 * mid-questionnaire. The nav's own copy of this dialog is in app.js; this one
 * is for arriving at /assess with only a remembered token.
 */
function offerResume(step) {
  const total = state.instrument.dimensions.length + 1;

  state.step = step;
  render();

  const panel = modal(
    '<h3>Resume where you left off?</h3>' +
      '<p class="muted">You have a scorecard in progress for ' +
      '<b style="color:var(--paper)">' +
      esc(state.organisation || 'your organization') +
      /*
       * The reference's own wording and its own arithmetic: `at step
       * ${saved.step} of ${DIMS.length+1}` — the raw step index against the
       * seven input screens, NOT the "of 8" the progress bar shows. The two
       * disagree by one in that build; reproduced rather than tidied, because
       * this sentence is what a returning respondent is shown.
       */
      '</b>, at step ' +
      step +
      ' of ' +
      total +
      '.</p>' +
      '<div class="nav-row">' +
      '<button class="btn quiet sm" id="startOver">Start over</button>' +
      '<button class="btn gold sm" id="resume">Resume</button>' +
      '</div>',
  );

  panel.querySelector('#resume').addEventListener('click', () => {
    closeModal();
  });

  panel.querySelector('#startOver').addEventListener('click', () => {
    closeModal();
    // Delete the abandoned draft. Not awaited: the page stays, the blank form
    // appears at once, and a failure changes nothing the respondent sees.
    if (state.token) api.discardQuietly(state.token);
    state.token = null;
    state.answers.clear();
    state.organisation = null;
    state.profile = null;
    state.consent = false;
    tokenStore.clear();
    progressStore.clear();
    // The reference's "Start over" is `clearProgress(); consentGate()`: a new
    // scorecard goes back through the terms, so an earlier acceptance in this
    // session does not carry over to it.
    consentStore.clear();
    start();
  });
}

/** A saved step, kept inside the screens that exist: 1 … number of dimensions. */
function savedStep(step) {
  const n = Number(step);
  if (!Number.isFinite(n) || n < 0) return firstUnansweredStep();
  return Math.min(Math.floor(n), state.instrument.dimensions.length);
}

function firstUnansweredStep() {
  const index = state.instrument.dimensions.findIndex((dimension) =>
    dimension.items.some((item) => SCOPES.some((scope) => !state.answers.has(key(item.id, scope)))),
  );

  return index === -1 ? state.instrument.dimensions.length : index + 1;
}

/* ---------------------------------------------------------------- render -- */

function render() {
  window.scrollTo({ top: 0 }); // each step opens at the top, not where the last ended
  state.step === 0 ? renderProfile() : renderDimension(state.step - 1);
  initReveal();

  // The reference's `saveProgress()` on every step change: where this
  // scorecard stands, for the "Resume where you left off?" dialog. A finished
  // scorecard being reviewed is not "in progress", so it is not recorded.
  if (state.token && !state.reviewing) {
    progressStore.set({
      token: state.token,
      step: Math.min(state.step, state.instrument.dimensions.length),
      total: state.instrument.dimensions.length + 1,
      organisation: state.profile?.organisation_name || state.organisation || null,
    });
  }
}

function progress(step) {
  /*
   * `total` is the number of screens that take input: the profile, then one per
   * dimension. The bar fills against it, so the last dimension sits just short
   * of full rather than at 100%.
   *
   * The COUNT says one more, because the result is the step after the last
   * question — seven screens to fill in, eight to get through. That is the
   * reference's arithmetic (`Step ${step+1} of ${total+1}`, filling `step/total`)
   * and it is deliberate: dropping the +1 makes the questionnaire claim to end a
   * screen before it does.
   */
  const total = state.instrument.dimensions.length + 1;
  const percent = Math.round((step / total) * 100);

  return (
    '<div class="prog"><div class="lbl">Step ' +
    (step + 1) +
    ' of ' +
    (total + 1) +
    '</div><div class="bar"><i style="width:' +
    percent +
    '%"></i></div><div class="lbl">' +
    percent +
    '%</div></div>'
  );
}

/* --------------------------------------------------------------- profile -- */

/*
 * "Begin scoring" only the first time. Once a scorecard is open, this form is
 * an edit of a record that already exists, and a button offering to begin
 * something invites exactly the second submission it no longer makes.
 */
function beginLabel() {
  return state.token ? 'Continue →' : 'Begin scoring →';
}

function renderProfile() {
  const reference = state.instrument.reference || {};
  const year = new Date().getFullYear();
  const years = [year - 2, year - 1, year, year + 1];

  /*
   * state.profile is what this respondent typed a moment ago and is coming Back
   * to, so every field prints it. A new scorecard starts blank, as the
   * reference's `blank()` does — nothing is carried over from a previous one.
   */
  const carried = state.profile || {};
  const carriedYear = years.includes(carried.assessment_year) ? carried.assessment_year : year;

  /*
   * Every input prints what is carried, not just the name field. This is the
   * whole of "Back keeps what I typed" for the text and number fields; the
   * selects do it through options() below.
   */
  const keep = (id) => ' value="' + esc(carried[id] === null || carried[id] === undefined ? '' : carried[id]) + '"';

  const options = (values, selected) =>
    ['<option value="">Select…</option>']
      .concat(
        (values || []).map(
          (value) =>
            '<option value="' +
            esc(value) +
            '"' +
            (value === selected ? ' selected' : '') +
            '>' +
            esc(value) +
            '</option>',
        ),
      )
      .join('');

  const minimumN = state.instrument.rules?.correlation_min_n ?? 30;

  root.innerHTML =
    progress(0) +
    '<div class="eyebrow">Context</div>' +
    '<h2 class="title">You and your organization</h2>' +
    '<p class="lede">You will assess yourself within your current leadership responsibilities ' +
    'and your organization’s leaders collectively. This context helps readers understand whose ' +
    'experience the two profiles describe. Nothing on this page affects either score.</p>' +
    '<div class="reqnote" style="margin-bottom:24px">Fields marked ' +
    '<span style="color:var(--r1);font-weight:700">*</span> are required, so that your results ' +
    'can be returned to you and compared with your own prior assessments. Nothing entered here ' +
    'is verified, and none of it is published.</div>' +
    '<form class="form" id="profileForm" novalidate>' +
    field(
      'full',
      'respondent_name',
      'Full name',
      '<input class="f" id="respondent_name" autocomplete="name" maxlength="120" placeholder="First and last name"' + keep('respondent_name') + '>',
      null,
      true,
    ) +
    field(
      '',
      'respondent_email',
      'Email',
      '<input class="f" id="respondent_email" type="email" autocomplete="email" maxlength="254" placeholder="you@company.com"' + keep('respondent_email') + '>',
      'Use an address where you can be contacted about your results.',
      true,
    ) +
    field(
      '',
      'respondent_phone',
      'Phone',
      '<input class="f" id="respondent_phone" type="tel" autocomplete="tel" maxlength="40" placeholder="+1 555 123 4567"' + keep('respondent_phone') + '>',
      'Include the country code if you are outside the United States.',
      true,
    ) +
    field(
      'full',
      'organisation_name',
      'Organization',
      '<input class="f" id="organisation_name" autocomplete="organization" maxlength="191" placeholder="e.g. Meridian Capital Group"' + keep('organisation_name') + '>',
      'The organization whose leaders you will assess alongside yourself.',
      true,
    ) +
    field(
      '',
      'organisation_size',
      'Organization size (employees)',
      '<select class="f" id="organisation_size">' +
        options(reference.organisation_sizes, carried.organisation_size) +
        '</select>',
      null,
      true,
    ) +
    field(
      '',
      'assessment_year',
      'Assessment year',
      '<select class="f" id="assessment_year">' +
        years
          .map(
            (y) =>
              '<option value="' + y + '"' + (y === carriedYear ? ' selected' : '') + '>' + y + '</option>',
          )
          .join('') +
        '</select>',
    ) +
    field(
      '',
      'role',
      'Your role',
      '<select class="f" id="role">' + options(reference.roles, carried.role) + '</select>',
    ) +
    field(
      '',
      'tenure',
      'Years in this role',
      '<select class="f" id="tenure">' + options(reference.tenures, carried.tenure) + '</select>',
    ) +
    field(
      '',
      'sector',
      'Sector',
      '<select class="f" id="sector">' + options(reference.sectors, carried.sector) + '</select>',
    ) +
    field(
      '',
      'systems_inventory',
      'How many AI systems are on your organization’s inventory?',
      '<input class="f" id="systems_inventory" type="number" min="0" max="100000" placeholder="Count, if an inventory exists"' + keep('systems_inventory') + '>',
      'Enter the number of AI systems listed in an inventory or register. If no such list exists, ' +
        'leave this field blank.',
    ) +
    '<div class="full" style="border-top:1px solid var(--rule-soft);padding-top:16px;margin-top:4px">' +
    '<p class="hint" style="margin:0 0 10px">Optional. Used only to explore associations across ' +
    'the dataset. These are only ever reported once ' +
    esc(numberWord(minimumN)) +
    ' or more respondents have reported both values, and only as associations.</p></div>' +
    field(
      '',
      'agenda_meetings',
      'Executive meetings in the last 12 months with AI as a decision item',
      '<input class="f" id="agenda_meetings" type="number" min="0" max="1000" step="1" placeholder="e.g. 4"' + keep('agenda_meetings') + '>',
      'Decision items only. A standing update in the technology report is not one.',
    ) +
    field(
      '',
      'education_hours',
      'Hours of AI education you took in the last 12 months',
      '<input class="f" id="education_hours" type="number" min="0" max="5000" step="0.5" placeholder="e.g. 6"' + keep('education_hours') + '>',
      'Count sessions you attended, not sessions that were offered.',
    ) +
    '</form>' +
    '<div class="formerr" id="profileError"></div>' +
    '<div class="nav-row">' +
    '<a class="btn quiet" href="' +
    esc(homeUrl()) +
    '">Cancel</a>' +
    '<button class="btn gold" id="beginScoring">' + beginLabel() + '</button></div>';

  document.getElementById('beginScoring').addEventListener('click', beginScoring);

  // Typing into a field that failed clears its error, so the page stops
  // shouting at a problem the respondent is in the middle of fixing.
  REQUIRED.forEach((id) => {
    const element = document.getElementById(id);
    if (!element) return;

    const clear = () => {
      element.classList.remove('bad');
      const error = document.getElementById('e-' + id);
      if (error) {
        error.classList.remove('show');
        error.textContent = '';
      }
    };

    element.addEventListener('input', clear);
    element.addEventListener('change', clear);
  });
}

function field(className, id, label, control, hint, required = false) {
  return (
    '<div' +
    (className ? ' class="' + className + '"' : '') +
    '><label class="f" for="' +
    esc(id) +
    '">' +
    esc(label) +
    (required ? '<span class="req">*</span>' : '') +
    '</label>' +
    control +
    // The hint and the error live in one cell. The two-column fields are
    // subgrids of three rows, so a fourth child would be clamped back onto the
    // hint and painted over it.
    (hint || required
      ? '<div class="fmsg">' +
        (hint ? '<div class="hint">' + esc(hint) + '</div>' : '') +
        (required ? '<div class="ferr" id="e-' + esc(id) + '"></div>' : '') +
        '</div>'
      : '') +
    '</div>'
  );
}

/* ---------------------------------------------------- profile validation -- */

/*
 * The reference's nameError / emailError / phoneError, in its own words.
 *
 * These run here so a message lands next to its field without a round trip. They
 * are NOT the rule: Services/RespondentIdentity.php runs the same checks on the
 * server and is what decides, because anything that bypasses this form bypasses
 * everything in this file. Keep the two in step — the messages below are asserted
 * against that class by identity_test.php.
 */

const EMAIL_RE =
  /^[^\s@,;:<>()[\]\\"]+@[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,24}$/;

const BLOCKED_EMAIL_DOMAINS = [
  'example.com', 'example.org', 'example.net', 'test.com', 'email.com',
  'mailinator.com', 'yopmail.com', 'guerrillamail.com', '10minutemail.com',
  'tempmail.com', 'temp-mail.org', 'trashmail.com', 'sharklasers.com',
  'throwawaymail.com', 'getnada.com', 'maildrop.cc', 'dispostable.com',
  'fakeinbox.com',
];

const BLOCKED_SUFFIXES = ['.test', '.invalid', '.example', '.local'];

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

  if (
    BLOCKED_EMAIL_DOMAINS.includes(domain) ||
    BLOCKED_SUFFIXES.some((suffix) => domain.endsWith(suffix))
  ) {
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

function fieldFail(id, message) {
  const element = document.getElementById(id);
  const error = document.getElementById('e-' + id);

  if (element) element.classList.add('bad');
  if (error) {
    error.textContent = message;
    error.classList.add('show');
  }
}

function validateProfile() {
  const read = (id) => (document.getElementById(id).value || '').trim();

  REQUIRED.forEach((id) => {
    const element = document.getElementById(id);
    if (element) element.classList.remove('bad');
    const error = document.getElementById('e-' + id);
    if (error) {
      error.classList.remove('show');
      error.textContent = '';
    }
  });

  const failures = [
    ['respondent_name', nameError(read('respondent_name'))],
    ['respondent_email', emailError(read('respondent_email'))],
    ['respondent_phone', phoneError(read('respondent_phone'))],
    [
      'organisation_name',
      read('organisation_name').length < 2
        ? 'Enter the name of the organization you are scoring.'
        : null,
    ],
    ['organisation_size', read('organisation_size') ? null : 'Select the organization’s size.'],
  ].filter((failure) => failure[1]);

  failures.forEach(([id, message]) => fieldFail(id, message));

  const box = document.getElementById('profileError');

  if (failures.length) {
    box.textContent =
      failures.length === 1
        ? 'One required field still needs an answer.'
        : failures.length + ' required fields still need an answer.';
    box.classList.add('show');

    const first = document.getElementById(failures[0][0]);
    if (first) {
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => first.focus({ preventScroll: true }), 260);
    }

    return false;
  }

  box.classList.remove('show');
  return true;
}

async function beginScoring() {
  // Nothing can start without the terms. Dismissing the gate and pressing Begin
  // re-opens it rather than proceeding — the server refuses either way, and
  // asking again is a better answer than an error about a dialog they closed.
  if (!state.consent) {
    openConsent();
    return;
  }

  if (!validateProfile()) return;

  const button = document.getElementById('beginScoring');
  const box = document.getElementById('profileError');

  const value = (id) => {
    const element = document.getElementById(id);
    return element && element.value.trim() !== '' ? element.value.trim() : null;
  };

  const number = (id) => {
    const raw = value(id);
    return raw === null ? null : Number(raw);
  };

  const profile = {
    respondent_name: value('respondent_name'),
    respondent_email: value('respondent_email'),
    respondent_phone: value('respondent_phone'),
    organisation_name: value('organisation_name'),
    organisation_size: value('organisation_size'),
    role: value('role'),
    tenure: value('tenure'),
    sector: value('sector'),
    assessment_year: number('assessment_year'),
    systems_inventory: number('systems_inventory'),
    agenda_meetings: number('agenda_meetings'),
    education_hours: number('education_hours'),
    consent: true,
  };

  /*
   * Coming Back to this form does not open a second scorecard.
   *
   * The record already exists under state.token, and every mark saved so far
   * hangs off it, so a second api.start() would strand them under a token
   * nothing would ever read again -- and leave two rows for one respondent in
   * the dataset. An edit is a PATCH of the profile that is already there.
   */
  const resuming = Boolean(state.token);

  button.disabled = true;
  button.textContent = resuming ? 'Saving…' : 'Starting…';
  box.textContent = '';
  box.classList.remove('show');

  try {
    if (resuming) {
      await api.saveProfile(state.token, profile);
    } else {
      const started = await api.start(profile);
      state.token = started.token;
      tokenStore.set(started.token);
    }

    state.organisation = profile.organisation_name;
    state.profile = profile;
    state.step = 1;
    render();
  } catch (cause) {
    /*
     * The server may have caught something this page did not — an address on a
     * disposable domain this file's list does not carry, say. Put its message on
     * the field it belongs to, not only in the summary box.
     */
    let placed = false;

    Object.entries(cause.errors || {}).forEach(([field, messages]) => {
      if (!REQUIRED.includes(field) || !messages?.length) return;
      fieldFail(field, messages[0]);
      placed = true;
    });

    box.textContent = cause.message;
    box.classList.add('show');

    if (placed) {
      const first = document.getElementById(
        REQUIRED.find((id) => document.getElementById(id)?.classList.contains('bad')),
      );
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    button.disabled = false;
    button.textContent = beginLabel();
  }
}

/* ------------------------------------------------------------- dimension -- */

/** The label each rating goes by — the reference's SCOPE_LABEL. */
function scopeLabel(scope) {
  const found = (state.instrument.scopes || []).find((s) => s.key === scope);
  return found ? found.label : scope === 'self' ? 'You' : 'Your organization’s leaders';
}

/**
 * One rating row: the shared end labels, each with its "i" carrying what a 1 or
 * a 5 means for THIS rating, and the five numbered buttons between them — the
 * reference's `.scope-rating` block, a radio group per scope.
 */
function ratingRow(dimension, item, index, scope) {
  const value = state.answers.get(key(item.id, scope));
  const label = scopeLabel(scope);
  const anchors = item.anchors[scope];

  const buttons = [1, 2, 3, 4, 5]
    .map(
      (n) =>
        '<button class="lk-btn' +
        (value === n ? ' sel' : '') +
        '" type="button" role="radio" aria-checked="' +
        (value === n) +
        '" tabindex="' +
        (value === n || (!value && n === 1) ? 0 : -1) +
        '" aria-label="' +
        n +
        ' out of 5" data-item="' +
        item.id +
        '" data-scope="' +
        scope +
        '" data-mark="' +
        n +
        '">' +
        n +
        '</button>',
    )
    .join('');

  return (
    '<div class="scope-rating"><div class="scope-label" style="color:var(--gold)" id="label-' +
    scope +
    '-' +
    index +
    '">' +
    esc(label) +
    '</div><div class="lik" id="lik-' +
    scope +
    '-' +
    item.id +
    '" role="radiogroup" aria-label="' +
    esc(label + ': ' + item.question) +
    '" data-item="' +
    item.id +
    '" data-scope="' +
    scope +
    '">' +
    '<div class="lk-cap lk-lo">' +
    esc(item.low_label) +
    '<button type="button" class="lk-info" aria-label="' +
    esc(label + ', score 1. ' + anchors.low) +
    '" data-tip="' +
    esc(anchors.low) +
    '">i</button></div><div class="lk-btns">' +
    buttons +
    '</div><div class="lk-cap lk-hi">' +
    esc(item.high_label) +
    '<button type="button" class="lk-info" aria-label="' +
    esc(label + ', score 5. ' + anchors.high) +
    '" data-tip="' +
    esc(anchors.high) +
    '">i</button></div></div></div>'
  );
}

function renderDimension(index) {
  const dimension = state.instrument.dimensions[index];
  const last = index === state.instrument.dimensions.length - 1;

  state.guideIndex = 0;

  const items = dimension.items
    .map(
      (item, qi) =>
        '<div class="item" id="it-' +
        item.id +
        '"><div class="q"><span class="num">' +
        esc(dimension.code) +
        ' ' +
        (qi + 1) +
        '</span><span class="txt"><span class="sub">' +
        esc(item.label) +
        '</span>' +
        esc(item.question) +
        '</span></div><div class="rsel-wrap">' +
        SCOPES.map((scope) => ratingRow(dimension, item, qi, scope)).join('') +
        '</div><div class="warn" id="warn-' +
        item.id +
        '" role="alert"></div></div>',
    )
    .join('');

  // The code and the position are separate elements: rendered as adjacent text
  // they read as "JDG1 of 4" to a screen reader.
  root.innerHTML =
    progress(index + 1) +
    '<div class="dim-head" data-dimension="' +
    esc(dimension.code) +
    '"><div class="code"><span class="code-mark">' +
    esc(dimension.code) +
    '</span><span class="code-pos muted">' +
    (index + 1) +
    ' of ' +
    state.instrument.dimensions.length +
    '</span></div><div><h2>' +
    esc(dimension.name) +
    '</h2><p>' +
    esc(dimension.description) +
    '</p></div></div>' +
    guideCard() +
    '<p class="reqnote">' +
    esc(state.instrument.text?.instructions || '') +
    '</p>' +
    items +
    '<div class="err" id="stepError"></div>' +
    /*
     * Back to step 0 needs something to put back. A scorecard RESUMED from a
     * stored token has its ratings but not its profile — GET /api/submissions
     * returns only the organisation name, on purpose — so on the first
     * dimension the button is offered only when this page still holds what was
     * typed. An empty form that then overwrites a good record is worse than no
     * button.
     */
    '<div class="nav-row">' +
    (index > 0 || state.profile ? '<button class="btn quiet" id="back">← Back</button>' : '<span></span>') +
    '<button class="btn gold" id="next">' +
    (last ? 'See results →' : 'Next dimension →') +
    '</button></div>';

  paintGuide(dimension);
  bindDimension(dimension, last);
}

function guideTabs() {
  return (
    state.instrument.reference?.guide_tabs || [
      { key: 'what', label: 'What it is' },
      { key: 'why', label: 'Why it matters' },
      { key: 'how', label: 'How to build it' },
    ]
  );
}

function guideCard() {
  const tabs = guideTabs();

  return (
    '<div class="guide">' +
    '<div class="guide-tabs" role="tablist">' +
    tabs
      .map(
        (tab, i) =>
          '<button role="tab" aria-selected="' +
          (i === 0) +
          '" data-guide="' +
          i +
          '"' +
          (i === 0 ? ' class="on"' : '') +
          '>' +
          esc(tab.label) +
          '</button>',
      )
      .join('') +
    '</div><div class="guide-body" id="guideBody"></div>' +
    '<div class="guide-foot"><button class="guide-nav" data-guide-move="-1" aria-label="Previous">←</button>' +
    '<div class="guide-dots">' +
    tabs.map((_, i) => '<i' + (i === 0 ? ' class="on"' : '') + '></i>').join('') +
    '</div><button class="guide-nav" data-guide-move="1" aria-label="Next">→</button></div></div>'
  );
}

function paintGuide(dimension) {
  const tabs = guideTabs();
  const body = document.getElementById('guideBody');
  if (!body) return;

  /*
   * innerHTML, as the reference's paintGuide() assigns it: the guide text is
   * the instrument's own, seeded from the reference and never respondent input.
   * Everything a respondent types goes through esc().
   */
  body.innerHTML = dimension.guide[tabs[state.guideIndex].key] || '';

  document.querySelectorAll('[data-guide]').forEach((tab, i) => {
    tab.classList.toggle('on', i === state.guideIndex);
    tab.setAttribute('aria-selected', String(i === state.guideIndex));
  });
  document.querySelectorAll('.guide-dots i').forEach((dot, i) => {
    dot.classList.toggle('on', i === state.guideIndex);
  });
}

function bindDimension(dimension, last) {
  const tabs = guideTabs();

  document.querySelectorAll('[data-guide]').forEach((tab) => {
    tab.addEventListener('click', () => {
      state.guideIndex = Number(tab.dataset.guide);
      paintGuide(dimension);
    });
  });

  document.querySelectorAll('[data-guide-move]').forEach((button) => {
    button.addEventListener('click', () => {
      const step = Number(button.dataset.guideMove);
      state.guideIndex = (state.guideIndex + step + tabs.length) % tabs.length;
      paintGuide(dimension);
    });
  });

  document.querySelectorAll('.lk-btn').forEach((button) => {
    button.addEventListener('click', () =>
      setRating(dimension, Number(button.dataset.item), button.dataset.scope, Number(button.dataset.mark)),
    );
  });

  document.querySelectorAll('.lik[role="radiogroup"]').forEach((group) => {
    group.addEventListener('keydown', (event) => onRatingKey(event, dimension, group));
  });

  // Absent on the first dimension of a resumed scorecard — see renderDimension.
  document.getElementById('back')?.addEventListener('click', () => {
    state.step -= 1;
    render();
  });

  document.getElementById('next').addEventListener('click', () => onNext(dimension, last));
}

/** likSet(): record one rating, repaint its group, and clear its warning once both are in. */
function setRating(dimension, itemId, scope, n) {
  if (!SCOPES.includes(scope) || !Number.isInteger(n) || n < 1 || n > 5) return;

  state.answers.set(key(itemId, scope), n);

  document.querySelectorAll('#lik-' + scope + '-' + itemId + ' .lk-btn').forEach((button) => {
    const on = Number(button.dataset.mark) === n;
    button.classList.toggle('sel', on);
    button.setAttribute('aria-checked', String(on));
    button.tabIndex = on ? 0 : -1;
  });

  if (SCOPES.every((s) => state.answers.has(key(itemId, s)))) {
    document.getElementById('warn-' + itemId)?.classList.remove('show');
  }

  queueSave([key(itemId, scope)]);
}

/**
 * likKey(): the arrow keys move the choice round the five, Home and End jump to
 * the ends — how a radio group is operated from the keyboard.
 */
function onRatingKey(event, dimension, group) {
  const keys = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];

  if (event.target.getAttribute('role') !== 'radio' || !keys.includes(event.key)) return;

  event.preventDefault();

  const itemId = Number(group.dataset.item);
  const scope = group.dataset.scope;
  const current = state.answers.get(key(itemId, scope)) || 1;
  const forward = ['ArrowRight', 'ArrowDown'].includes(event.key);

  const n =
    event.key === 'Home' ? 1 : event.key === 'End' ? 5 : ((current - 1 + (forward ? 1 : 4)) % 5) + 1;

  setRating(dimension, itemId, scope, n);
  group.querySelectorAll('.lk-btn')[n - 1].focus();
}

/* ------------------------------------------------------------ saving ----- */

let pending = new Set();
let saveTimer = null;

function queueSave(keys) {
  keys.forEach((k) => pending.add(k));

  clearTimeout(saveTimer);
  saveTimer = setTimeout(flushSave, 600);
}

async function flushSave() {
  if (!state.token || pending.size === 0 || state.saving) return;

  const keys = [...pending];
  pending.clear();
  state.saving = true;

  const responses = keys.map((k) => {
    const [itemId, scope] = k.split(':');
    return {
      item_id: Number(itemId),
      scope,
      mark: state.answers.has(k) ? state.answers.get(k) : null,
    };
  });

  try {
    await api.save(state.token, responses);
  } catch (error) {
    // Put them back so the next flush retries rather than losing the rating.
    keys.forEach((k) => pending.add(k));
    if (error instanceof ApiError && error.status === 0) {
      toast('Saving failed — your answers are still on this page.');
    }
  } finally {
    state.saving = false;
  }
}

/* -------------------------------------------------------------- advance -- */

/**
 * nextDim(): every question needs both ratings. Each one missing any gets the
 * reference's warning naming which — "Choose a rating for You and Your
 * organization’s leaders." — and the page scrolls to the first.
 */
async function onNext(dimension, last) {
  let first = null;

  dimension.items.forEach((item) => {
    const missing = SCOPES.filter((scope) => !state.answers.has(key(item.id, scope)));
    const warn = document.getElementById('warn-' + item.id);

    if (warn) {
      warn.textContent = missing.length
        ? 'Choose a rating for ' + missing.map(scopeLabel).join(' and ') + '.'
        : '';
      warn.classList.toggle('show', missing.length > 0);
    }

    if (missing.length && !first) first = document.getElementById('it-' + item.id);
  });

  if (first) {
    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
