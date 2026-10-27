/**
 * The assessment: a profile step, then one step per competency.
 *
 * Differences from the legacy single-file version, all deliberate:
 *
 *  - The questionnaire is fetched, not hard-coded. There is one instrument, in
 *    the database.
 *  - Answers are saved to the server as they are given, so closing the tab no
 *    longer loses the scorecard. The legacy build kept them in sessionStorage.
 *  - Nothing here computes a score. Marks go up, a result comes back. The
 *    browser cannot assert a level.
 *  - Remit is recorded as context and never skips a competency; a director
 *    scores all of them whatever their committee covers.
 */

import { api, ApiError, tokenStore, carryForward } from './api.js';
import { esc, toast, initReveal, numberWord, modal, closeModal } from './ui.js';

const root = document.getElementById('assessRoot');
const GUIDE_TABS = [
  ['what', 'What it is'],
  ['why', 'Why it matters'],
  ['how', 'How to build it'],
  ['example', 'What it looks like'],
];

const state = {
  instrument: null,
  token: null,
  step: 0,          // 0 = profile, 1..n = competencies
  answers: new Map(), // itemId -> { mark, evidence_note }
  guideIndex: 0,
  saving: false,
  reviewing: false,   // reopened a finished scorecard to change answers
  carry: null,        // board context handed over by "Save · score another seat"
  board: null,        // the seat being scored, named by the resume prompt
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

  // "Save · score another seat" leaves board context behind for the next
  // scorecard. Its presence means a deliberately fresh start, so the token
  // remembered in this browser is dropped rather than reopened — it belongs to
  // the finished scorecard we just came from, and resuming it here would send
  // the director straight back to the result they just left.
  state.carry = carryForward.take();

  if (state.carry) {
    tokenStore.clear();
  }

  // ?resume=<token> is an explicit request — the "Review answers" link on a
  // finished scorecard uses it. A token remembered in this browser is only a
  // convenience, so a FINISHED scorecard found that way goes to its result
  // rather than silently reopening the questionnaire.
  const requested = new URLSearchParams(location.search).get('resume');
  const resume = state.carry ? null : (requested || tokenStore.get());

  /*
   * Both requests go out together. They are independent, and waiting for the
   * instrument before even asking about the saved scorecard was half of why
   * this page sat empty. Usually there is no instrument request at all.
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
        '<div class="empty"><b>The scorecard could not be loaded</b>' + esc(error.message) + '</div>';
      return;
    }
  }

  if (resume) {
    const existing = await pending[1];

    // An unknown or stale token simply starts a fresh scorecard.
    if (!existing) {
      tokenStore.clear();
      render();
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

    // Both shapes carry `responses`: a finished scorecard's result payload
    // includes the raw marks precisely so review needs no second request.
    Object.entries(existing.responses || {}).forEach(([itemId, response]) => {
      state.answers.set(Number(itemId), response);
    });

    if (complete) {
      // Reviewing: go straight back into the questionnaire, first competency.
      state.step = 1;
      render();
      return;
    }

    if (state.answers.size > 0) {
      offerResume();
      return;
    }
  }

  render();
})();

function resultsUrl(token) {
  return document.documentElement.dataset.resultsBase + '/' + encodeURIComponent(token);
}

/**
 * Offer to pick a half-finished scorecard back up.
 *
 * A dialog over the assessment, as in the single-file build — not a panel that
 * replaces the page. Replacing it left the director looking at an otherwise
 * empty page with the footer riding up under the question.
 *
 * The profile step is rendered behind it, which is also exactly what "Start
 * over" lands on, so dismissing the dialog leaves them somewhere sensible.
 */
function offerResume() {
  const step = firstUnansweredStep();
  const total = state.instrument.competencies.length + 1;

  state.step = 0;
  render();

  modal(
    '<h3>Resume where you left off?</h3>' +
    '<p class="muted">You have a scorecard in progress for your seat at ' +
    '<b style="color:var(--paper)">' + esc(state.board || 'Anonymous') + '</b>, ' +
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
    tokenStore.clear();
    state.step = 0;
    render();
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
  window.scrollTo({ top: 0 });
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
   * Board context carried over from the scorecard just finished, if any. Only
   * sector, size and year: scoring another seat means a different seat, so the
   * name, the role and the tenure are asked again. Every field stays editable —
   * the second seat may well be on another board entirely.
   */
  const carried = state.carry || {};
  const carriedYear = years.includes(carried.assessment_year) ? carried.assessment_year : year;

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
    'positions. Nothing on this page affects your score. Everything here is optional ' +
    'except the year.</p>' +
    '<form class="form" id="profileForm" novalidate>' +
      field('full', 'board_name', 'The board you are scoring your actions on',
        '<input class="f" id="board_name" name="board_name" maxlength="191" placeholder="e.g. Meridian Capital Group">',
        'One scorecard per seat. A director on three boards usually scores differently on each.') +
      field('', 'director_name', 'Your name',
        '<input class="f" id="director_name" name="director_name" maxlength="120" placeholder="Optional">') +
      field('', 'assessment_year', 'Assessment year',
        '<select class="f" id="assessment_year" name="assessment_year">' +
          years.map((y) => '<option value="' + y + '"' + (y === carriedYear ? ' selected' : '') + '>' + y + '</option>').join('') +
        '</select>') +
      field('', 'seat_role', 'Your role on this board',
        '<select class="f" id="seat_role" name="seat_role">' + options(reference.roles) + '</select>') +
      field('', 'tenure', 'Years in this seat',
        '<select class="f" id="tenure" name="tenure">' + options(reference.tenures) + '</select>') +
      field('', 'sector', 'Sector',
        '<select class="f" id="sector" name="sector">' + options(reference.sectors, carried.sector) + '</select>') +
      field('', 'company_size', 'Company size (employees)',
        '<select class="f" id="company_size" name="company_size">' +
          options(reference.company_sizes, carried.company_size) + '</select>') +
      '<div class="full" style="border-top:1px solid var(--rule-soft);padding-top:16px;margin-top:4px">' +
        '<p class="hint" style="margin:0 0 10px">Optional. Used only to explore associations ' +
        'across the dataset. These are only ever reported once ' +
        esc(numberWord(state.instrument.rules.correlation_min_n ?? 30)) +
        ' or more directors have scored, and only as associations.</p></div>' +
      field('', 'systems_seen', 'How many of the company’s AI systems are you aware of?',
        '<input class="f" id="systems_seen" name="systems_seen" type="number" min="0" max="10000" placeholder="e.g. 12">',
        'Count the systems you know about, whether from an inventory, a briefing, or a '
        + 'demonstration. If you cannot put a number on it, leave it blank.') +
      field('', 'board_seats', 'Directors on this board',
        '<input class="f" id="board_seats" name="board_seats" type="number" min="1" max="100" placeholder="e.g. 11">') +
      field('', 'agenda_meetings', 'Meetings in the last 12 months with AI as a decision item',
        '<input class="f" id="agenda_meetings" name="agenda_meetings" type="number" min="0" max="100" placeholder="e.g. 2">',
        'Count decision items only. An update in the CEO report is not one.') +
      field('', 'education_hours', 'Hours of AI education you took in the last 12 months',
        '<input class="f" id="education_hours" name="education_hours" type="number" min="0" max="2000" step="0.5" placeholder="e.g. 6">',
        'Count sessions you attended, not sessions the board offered.') +
      '<div class="full"><div class="f" style="display:block;margin-bottom:8px">' +
        'Which competencies fall inside your committee remit?</div><div class="checks" id="remit">' +
        state.instrument.competencies
          .map(
            (c) =>
              '<label><input type="checkbox" value="' + esc(c.code) + '">' +
              '<span>' + esc(c.code) + ' · ' + esc(c.name) + '</span></label>',
          )
          .join('') +
        '</div><div class="hint">Recorded as context only. You score all ' +
        esc(numberWord(state.instrument.competencies.length)) +
        ' competencies whatever your remit.</div></div>' +
    '</form>' +
    '<div class="err" id="profileError"></div>' +
    '<div class="nav-row"><a class="btn quiet" href="' + esc(homeUrl()) + '">Cancel</a>' +
    '<button class="btn gold" id="beginScoring">Begin scoring →</button></div>';

  document.getElementById('beginScoring').addEventListener('click', beginScoring);
}

function field(className, id, label, control, hint) {
  return (
    '<div' + (className ? ' class="' + className + '"' : '') + '>' +
    '<label class="f" for="' + esc(id) + '">' + esc(label) + '</label>' +
    control +
    (hint ? '<div class="hint">' + esc(hint) + '</div>' : '') +
    '</div>'
  );
}

function homeUrl() {
  return document.documentElement.dataset.homeBase || '/';
}

async function beginScoring() {
  const button = document.getElementById('beginScoring');
  const error = document.getElementById('profileError');
  const value = (id) => {
    const element = document.getElementById(id);
    return element && element.value !== '' ? element.value : null;
  };

  const number = (id) => {
    const raw = value(id);
    return raw === null ? null : Number(raw);
  };

  const profile = {
    board_name: value('board_name'),
    director_name: value('director_name'),
    seat_role: value('seat_role'),
    tenure: value('tenure'),
    sector: value('sector'),
    company_size: value('company_size'),
    assessment_year: number('assessment_year'),
    systems_seen: number('systems_seen'),
    board_seats: number('board_seats'),
    agenda_meetings: number('agenda_meetings'),
    education_hours: number('education_hours'),
    remit: [...document.querySelectorAll('#remit input:checked')].map((input) => input.value),
  };

  button.disabled = true;
  button.textContent = 'Starting…';
  error.textContent = '';
  error.classList.remove('show');

  try {
    const started = await api.start(profile);
    state.token = started.token;
    tokenStore.set(started.token);
    state.step = 1;
    render();
  } catch (cause) {
    error.textContent = cause.message;
    error.classList.add('show');
    button.disabled = false;
    button.textContent = 'Begin scoring →';
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
    '<div class="nav-row"><button class="btn quiet" id="back">← Back</button>' +
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

  document.getElementById('back').addEventListener('click', () => {
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
