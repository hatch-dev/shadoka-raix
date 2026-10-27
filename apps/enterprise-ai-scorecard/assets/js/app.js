/**
 * Boot for every public page: chrome, the "How scoring works" dialog, and the
 * Admin gate.
 *
 * The dialog's content comes from the instrument endpoint rather than being
 * written into the markup, so the five levels and their descriptions are stated
 * once — in the database — and this page cannot drift from the questionnaire.
 */

import { api, consentStore, tokenStore } from './api.js';
import { initChrome, modal, esc, closeModal, numberWord, toast } from './ui.js';
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
  const panel = modal('<h3>How scoring works</h3><p class="muted">Loading…</p>');

  let data;
  try {
    data = await instrument();
  } catch (error) {
    panel.innerHTML =
      '<h3>How scoring works</h3><p class="muted">' +
      esc(error.message) +
      '</p><div class="nav-row"><span></span><button class="btn sm" data-close-modal>Close</button></div>';
    bindClose(panel);
    return;
  }

  const questions = data.dimensions.reduce((n, d) => n + d.items.length, 0);
  const perDimension = data.dimensions.length ? questions / data.dimensions.length : 0;

  // Highest level first: the ladder reads downward from what good looks like.
  const rungs = data.rungs
    .slice()
    .reverse()
    .map(
      (rung) =>
        '<div class="rung-row" style="--c:' +
        esc(rung.colour) +
        '"><div class="rung-name">Level ' +
        esc(rung.value) +
        ', ' +
        esc(rung.name) +
        '</div><div class="rung-desc">' +
        esc(rung.description) +
        '</div></div>',
    )
    .join('');

  /*
   * The reference's dialog, and only what it contains: the heading, one
   * paragraph, the five levels highest-first, and Close.
   *
   * Its counts are derived from the instrument rather than written into the
   * sentence — "Thirty", "five", "six", "five levels" all come from the payload
   * — so the same words render for v1.0 and the prose cannot outlive a version
   * that changes them.
   */
  panel.innerHTML =
    '<h3>How scoring works</h3>' +
    '<p class="muted" style="margin-bottom:16px">' +
    esc(numberWord(questions, true)) +
    ' questions, ' +
    esc(numberWord(perDimension)) +
    ' per dimension, about what your organization actually does. Each is answered on a ' +
    'five-point scale. Each dimension receives its own score, the average of its ' +
    esc(numberWord(perDimension)) +
    ' answers out of 5, and the ' +
    esc(numberWord(data.dimensions.length)) +
    ' scores are reported side by side with an overall score, the average of all ' +
    esc(numberWord(questions)) +
    ' answers. Every score is placed on one of ' +
    esc(numberWord(data.rungs.length)) +
    ' levels.</p>' +
    rungs +
    '<div class="nav-row"><span></span><button class="btn sm" data-close-modal>Close</button></div>';

  bindClose(panel);
}

function bindClose(panel) {
  panel.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', closeModal);
  });
}

/* ------------------------------------------------- starting a scorecard -- */

/*
 * "Score your organization", in the nav and in the hero.
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
   * A scorecard already in this browser takes precedence, as it does in the
   * reference — there `startAssessment()` offers to resume BEFORE it offers the
   * terms, because someone mid-way through has already accepted them.
   *
   * /assess is what owns that decision: it reopens the scorecard, sends a
   * finished one to its result, and falls back to the gate if the token turns
   * out to be stale. So this hands over rather than asking a second time.
   */
  if (tokenStore.get()) {
    window.location.href = href;
    return;
  }

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
      window.location.href = href;
    },
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
