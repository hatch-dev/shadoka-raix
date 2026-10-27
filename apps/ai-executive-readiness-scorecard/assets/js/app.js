/**
 * Boot for every public page: chrome, the "How scoring works" dialog, and the
 * Admin gate.
 *
 * The dialog's content comes from the instrument endpoint rather than being
 * written into the markup, so the five levels and their descriptions are stated
 * once — in the database — and this page cannot drift from the questionnaire.
 */

import { api, consentStore, progressStore, tokenStore } from './api.js';
import { initChrome, modal, esc, closeModal, toast } from './ui.js';
import { openConsentDialog } from './consent.js';

initChrome();

let instrumentPromise = null;

function instrument() {
  if (!instrumentPromise) instrumentPromise = api.instrument();
  return instrumentPromise;
}

document.querySelectorAll('[data-open-help]').forEach((button) => {
  button.addEventListener('click', openHelp);
});

async function openHelp() {
  let data;
  try {
    data = await instrument();
  } catch (error) {
    const panel = modal(
      '<h3>How scoring works</h3><p class="muted">' +
        esc(error.message) +
        '</p><div class="nav-row"><span></span><button class="btn sm" data-close-modal>Close</button></div>',
    );
    bindClose(panel);
    return;
  }

  /*
   * The reference's openHelp(), in its words: the instructions, one paragraph
   * on how the two profiles are scored, the five levels lowest first with the
   * range each covers, and the disclaimer. The ranges are read off the
   * instrument's own cut points, so the dialog cannot state a boundary the
   * scorer does not use.
   */
  const rungs = data.rungs;
  const edge = (value) => Number(value).toFixed(2);

  const levels = rungs
    .map(
      (rung, i) =>
        '<div style="padding:10px 0;border-top:1px solid var(--rule)"><b style="color:' +
        esc(rung.colour) +
        '">' +
        esc(rung.name) +
        '</b><span class="small muted"> · ' +
        (i === 0 ? '1.00' : edge(rung.band_min)) +
        ' ' +
        (i === rungs.length - 1 ? 'to 5.00' : 'to below ' + edge(rung.band_max)) +
        '</span><p class="small muted">' +
        esc(rung.description) +
        '</p></div>',
    )
    .join('');

  const panel = modal(
    '<h3>How scoring works</h3><p class="muted">' +
      esc(data.text.instructions) +
      '</p><p class="muted" style="margin:14px 0">The scorecard asks 12 questions across four ' +
      'dimensions. Each question uses a scale from 1 to 5. Use the descriptions at each end to ' +
      'decide how to rate yourself and your organization’s leaders. Both ratings are required for ' +
      'each question. Your answers produce a profile for you and a profile for the leadership team. ' +
      'For each profile, a dimension score is the average of its three ratings and the overall ' +
      'score is the average of its 12 ratings. A level is assigned before scores are rounded for ' +
      'display.</p>' +
      levels +
      '<p class="small muted" style="margin-top:10px">' +
      esc(data.text.disclaimer) +
      '</p><div class="nav-row"><span></span><button class="btn sm" data-close-modal>Close</button></div>',
  );

  bindClose(panel);
}

function bindClose(panel) {
  panel.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', closeModal);
  });
}

/* ------------------------------------------------- starting a scorecard -- */

/*
 * "Start your scorecard", in the nav and in the hero.
 *
 * The reference's `startAssessment()` opens its dialog over the screen you are
 * already on and only calls `go('assess')` once the terms are accepted. These
 * are anchors pointing at /assess, so that the page still works with scripting
 * off — the click is intercepted to put the dialog where the reference puts it,
 * and the navigation happens on Continue.
 */

document.querySelectorAll('[data-start-assessment]').forEach((link) => {
  link.addEventListener('click', (event) => {
    // Let a middle-click or a modifier-click open the assessment in a new tab,
    // as any other link would.
    if (event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    startAssessment(link.getAttribute('href'));
  });
});

async function startAssessment(href) {
  /*
   * The reference's `startAssessment()`: a scorecard in progress past its
   * profile step is offered back BEFORE the terms, in a dialog over whatever
   * page this is — the hero, the results, or a dimension of the questionnaire
   * itself — naming the organisation and the step it was left on.
   */
  const saved = progressStore.get();

  if (saved && saved.token && saved.step > 0) {
    offerResume(href, saved);
    return;
  }

  openTerms(href);
}

/**
 * The reference's consentGate(): the terms over the page you are on. Cancel
 * stays here; Continue starts a blank scorecard at /assess.
 */
async function openTerms(href) {
  const panel = modal('<h3>Before you begin</h3><p class="muted">Loading…</p>');

  let data;
  try {
    data = await instrument();
  } catch (error) {
    /*
     * The terms could not be read, so they cannot be shown, so nothing may be
     * accepted here. The link still works: /assess asks again, and the server
     * refuses a scorecard that arrives without acceptance either way.
     */
    panel.innerHTML =
      '<h3>Before you begin</h3><p class="muted" style="margin-bottom:14px">' +
      esc(error.message) +
      '</p><div class="nav-row"><button class="btn quiet sm" data-close-modal>Cancel</button>' +
      '<a class="btn gold sm" href="' + esc(href) + '">Continue anyway</a></div>';
    bindClose(panel);
    return;
  }

  openConsentDialog(data.consent, {
    // Cancel stays on this page, as the reference's does.
    cancelHref: null,
    onAccept: () => {
      consentStore.accept();
      // `consentAccept()` starts from `blank()`: accepting the terms here is
      // the start of a NEW scorecard, so nothing remembered may reopen an old one.
      tokenStore.clear();
      progressStore.clear();
      window.location.href = href;
    },
  });
}

/**
 * "Resume where you left off?" — the reference's dialog, word for word, with its
 * own arithmetic: `at step ${saved.step} of ${DIMS.length+1}`.
 *
 * Resume reopens the scorecard at that step. Start over deletes the draft and
 * opens the terms again, as this reference's `clearProgress(); consentGate()`
 * does — a new scorecard is a new acceptance.
 */
async function offerResume(href, saved) {
  let total = saved.total;

  if (!total) {
    try {
      total = (await instrument()).dimensions.length + 1;
    } catch {
      total = '';
    }
  }

  const panel = modal(
    '<h3>Resume where you left off?</h3>' +
      '<p class="muted">You have a scorecard in progress for ' +
      '<b style="color:var(--paper)">' +
      esc(saved.organisation || 'your organization') +
      '</b>, at step ' +
      esc(saved.step) +
      ' of ' +
      esc(total) +
      '.</p>' +
      '<div class="nav-row">' +
      '<button class="btn quiet sm" type="button" id="startOver">Start over</button>' +
      '<button class="btn gold sm" type="button" id="resumeGo">Resume</button>' +
      '</div>',
  );

  panel.querySelector('#resumeGo').addEventListener('click', () => {
    closeModal();
    window.location.href = href + '?resume=' + encodeURIComponent(saved.token);
  });

  panel.querySelector('#startOver').addEventListener('click', async (event) => {
    // The draft being abandoned is deleted, not left in the database with
    // nothing pointing at it. A failure is not the respondent's problem.
    event.currentTarget.disabled = true;
    await api.discardQuietly(saved.token);
    closeModal();
    tokenStore.clear();
    progressStore.clear();
    consentStore.clear();
    // `clearProgress(); consentGate()`: a new scorecard goes back through the
    // terms, over this page, and Cancel leaves the respondent where they were.
    openTerms(href);
  });
}

/* ---------------------------------------------------------------- admin -- */

/*
 * The Admin gate, in place, as the reference build had it.
 *
 * It asks for the same shared PIN that build used, but nothing in this file
 * knows what the PIN is: the value is held server-side and the verdict comes
 * back from /admin/pin. That build kept the secret in a constant in its own
 * script, which it could afford to do because everything behind the gate was the
 * viewer's own sessionStorage; these screens read every respondent's marks,
 * name, email address and phone number out of a shared database.
 *
 * So never put the secret in this file, and never name a constant after it —
 * admin_gate_test.php greps every published asset for both, which is why this
 * comment does not spell either out.
 *
 * The reference dialog also told the reader its gate was not a security control.
 * That was true of a constant in client JavaScript. Here the gate is the only
 * thing standing in front of real respondent data, and inviting people to
 * disregard it would be worse than saying nothing, so that line is not
 * reproduced.
 */

const ADMIN_INTRO = 'Analytics and the research dataset sit behind this gate.';

/*
 * Exit sends the browser back here with ?signed_out=1. Say so, as the
 * reference's `exitAdmin()` toasted, then take the parameter out of the address
 * bar: leaving it would re-announce it on every reload and travel if the URL
 * were shared.
 */
if (new URLSearchParams(location.search).has('signed_out')) {
  toast('Signed out of admin');

  const url = new URL(location.href);
  url.searchParams.delete('signed_out');
  history.replaceState(null, '', url.pathname + url.search + url.hash);
}

document.querySelectorAll('[data-open-admin]').forEach((link) => {
  link.addEventListener('click', (event) => {
    event.preventDefault();
    openAdmin(link.getAttribute('href'), link.dataset.adminNext || '');
  });
});

/*
 * There is no separate sign-in page: /admin/login, and any signed-out visit to
 * this app's admin, sends the browser here with ?admin=1 (and the `next` it was
 * heading for). Open the dialog, then take both out of the address bar.
 */
{
  const params = new URLSearchParams(location.search);
  if (params.has('admin')) {
    const link = document.querySelector('[data-open-admin]');
    const next = params.get('next') || (link ? link.dataset.adminNext || '' : '');

    const url = new URL(location.href);
    url.searchParams.delete('admin');
    url.searchParams.delete('next');
    history.replaceState(null, '', url.pathname + url.search + url.hash);

    openAdmin(link ? link.getAttribute('href') : location.href, next);
  }
}

async function openAdmin(href, next) {
  const panel = modal(
    '<h3>Admin</h3><p class="muted" style="margin-bottom:14px">' +
      esc(ADMIN_INTRO) +
      '</p><p class="muted">Checking…</p>',
  );

  let gate;
  try {
    gate = await api.adminGate(next);
  } catch (error) {
    // The link still works; say so rather than trapping them in a dead dialog.
    panel.innerHTML =
      '<h3>Admin</h3><p class="muted" style="margin-bottom:14px">' +
      esc(error.message) +
      '</p><div class="nav-row"><button class="btn quiet sm" data-close-modal>Cancel</button>' +
      '<a class="btn gold sm" href="' +
      esc(href) +
      '">Open sign-in</a></div>';
    bindClose(panel);
    return;
  }

  // Already signed in: go straight through, as the reference gate did when its
  // `adminOK` flag was already set.
  if (gate.authenticated) {
    window.location.href = gate.redirect;
    return;
  }

  /*
   * A PIN, as in the reference build. When no PIN is configured the dialog asks
   * for an account instead, so clearing the configured secret leaves a working sign-in
   * rather than a dead end.
   */
  const fields = gate.pin_enabled
    ? '<div><label class="f" for="adminPin">Password</label>' +
      '<input class="f" id="adminPin" type="password" autocomplete="off"></div>'
    : '<div style="margin-bottom:14px">' +
      '<label class="f" for="adminEmail">Email address</label>' +
      '<input class="f" id="adminEmail" type="email" autocomplete="username" autocapitalize="off">' +
      '</div><div>' +
      '<label class="f" for="adminPassword">Password</label>' +
      '<input class="f" id="adminPassword" type="password" autocomplete="current-password">' +
      '</div>';

  panel.innerHTML =
    '<h3>Admin</h3>' +
    '<p class="muted" style="margin-bottom:14px">' +
    esc(ADMIN_INTRO) +
    '</p>' +
    fields +
    '<div class="err" id="adminError"></div>' +
    '<div class="nav-row">' +
    '<button class="btn quiet sm" type="button" data-close-modal>Cancel</button>' +
    '<button class="btn gold sm" type="button" id="adminEnter">Enter</button>' +
    '</div>';

  bindClose(panel);

  const error = panel.querySelector('#adminError');
  const enter = panel.querySelector('#adminEnter');
  const inputs = [...panel.querySelectorAll('input.f')];
  const secret = panel.querySelector('#adminPin') || panel.querySelector('#adminPassword');

  const submit = async () => {
    error.textContent = '';
    enter.disabled = true;
    enter.textContent = 'Checking…';

    try {
      const result = gate.pin_enabled
        ? await api.adminPin(gate.csrf, panel.querySelector('#adminPin').value, next)
        : await api.adminLogin(
            gate.csrf,
            panel.querySelector('#adminEmail').value,
            panel.querySelector('#adminPassword').value,
            next,
          );

      window.location.href = result.redirect;
      return; // navigating; leave it disabled
    } catch (cause) {
      // Whatever the server said — "Wrong PIN.", locked out, throttled, expired
      // token. Never rephrased here: only the server knows which it was.
      error.textContent = cause.message;
      secret.value = '';
      secret.focus();
    }

    enter.disabled = false;
    enter.textContent = 'Enter';
  };

  enter.addEventListener('click', submit);

  inputs.forEach((field) => {
    field.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') submit();
    });
  });

  inputs[0].focus();
}
