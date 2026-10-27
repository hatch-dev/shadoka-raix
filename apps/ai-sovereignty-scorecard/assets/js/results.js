/**
 * The results page is server-rendered, including the two gauges — they are
 * static SVG, and drawing them in JavaScript only means the page arrives with
 * two empty holes in it.
 *
 * This adds the three things markup cannot do: the radar chart, the PDF, and
 * "Score another organization", which opens the consent gate in place.
 *
 * Scores are read from a JSON script block rather than fetched, so nothing here
 * can disagree with the numbers printed beside it.
 */

import { api, consentStore, progressStore, tokenStore } from './api.js';
import { esc, modal, toast } from './ui.js';
import { downloadPdf } from './pdf.js';
import { openConsentDialog } from './consent.js';

const dataElement = document.getElementById('resultData');
const canvas = document.getElementById('radar');

let result = null;
try {
  result = dataElement ? JSON.parse(dataElement.textContent) : null;
} catch {
  result = null;
}

/* ---------------------------------------------------------------- radar -- */

if (result && canvas && window.Chart) {
  new window.Chart(canvas, {
    type: 'radar',
    data: {
      labels: result.dimensions.map((d) => d.code),
      datasets: [
        {
          label: 'Score',
          data: result.dimensions.map((d) => d.score),
          borderColor: 'rgba(232,147,63,1)',
          backgroundColor: 'rgba(232,147,63,.12)',
          pointBackgroundColor: 'rgba(232,147,63,1)',
          borderWidth: 1.5,
          pointRadius: 3,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        r: {
          /*
           * 0 to 5. The reference drew this axis 0–5 on the results page and
           * 0–4 on the analytics radar, so the same score sat at a different
           * radius on two screens of one instrument. Five points, five on the
           * axis, everywhere.
           */
          min: 0,
          max: 5,
          ticks: {
            stepSize: 1,
            color: '#6F84A0',
            backdropColor: 'transparent',
            font: { family: 'IBM Plex Mono', size: 10 },
          },
          grid: { color: 'rgba(61,130,196,.18)' },
          angleLines: { color: 'rgba(61,130,196,.18)' },
          pointLabels: { color: '#A0B4CA', font: { family: 'IBM Plex Mono', size: 11 } },
        },
      },
      plugins: { legend: { display: false } },
    },
  });
} else if (canvas && !window.Chart) {
  // Chart.js did not load. A quiet note beats an empty box, and the same four
  // numbers are listed below it either way.
  const note = document.createElement('div');
  note.className = 'muted small';
  note.style.cssText =
    'display:flex;align-items:center;justify-content:center;min-height:200px;' +
    'border:1px dashed var(--rule);border-radius:4px;text-align:center;padding:14px';
  note.textContent = 'The chart could not be drawn. The four scores are listed below.';
  canvas.replaceWith(note);
}

/* ------------------------------------------------------------------ pdf -- */

const pdfButton = document.getElementById('downloadPdf');

if (pdfButton) {
  pdfButton.addEventListener('click', () => {
    if (!result) {
      toast('The scorecard could not be read from this page.');
      return;
    }

    try {
      downloadPdf(result);
    } catch (error) {
      toast('The PDF could not be generated.');
      // eslint-disable-next-line no-console
      console.error(error);
    }
  });
}

/* -------------------------------------------------- score another one -- */

/*
 * The reference's `showResults()` wrote the record and then cleared the
 * scorecard in progress, so nothing in the browser still pointed at a finished
 * one: pressing "Score your organization" afterwards opened the terms for a new
 * scorecard. The remembered token is dropped here for the same reason. The
 * result stays reachable at its own URL, which carries the token, and "Review
 * answers" passes it explicitly.
 */
tokenStore.clear();
progressStore.clear();

/*
 * `scoreAnotherOrganization()`: nothing to save first — every answer was written
 * as it was given — so this is the consent gate, opened over this page with
 * Cancel staying here, and a blank scorecard once it is accepted. Nothing is
 * carried over; the reference's `consentAccept()` starts from `blank()`.
 */
const scoreAnotherButton = document.getElementById('scoreAnother');

if (scoreAnotherButton) {
  scoreAnotherButton.addEventListener('click', async () => {
    const href = scoreAnotherButton.dataset.assess;
    modal('<h3>Before you begin</h3><p class="muted">Loading…</p>');

    let data;
    try {
      data = await api.instrument();
    } catch (error) {
      // Without the terms nothing can be accepted here. /assess asks again, and
      // the server refuses a scorecard that arrives without acceptance anyway.
      modal(
        '<h3>Before you begin</h3><p class="muted" style="margin-bottom:14px">' +
        esc(error.message) +
        '</p><div class="nav-row"><button class="btn quiet sm" type="button" data-close-modal>Cancel</button>' +
        '<a class="btn gold sm" href="' + esc(href) + '">Continue anyway</a></div>',
      );
      return;
    }

    openConsentDialog(data.consent, {
      cancelHref: null,
      onAccept: () => {
        consentStore.accept();
        progressStore.clear();
        window.location.href = href;
      },
    });
  });
}
