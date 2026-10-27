/**
 * The results page is server-rendered. This adds what markup cannot do: the
 * radar, the PDF, and "Score another organization", which opens the consent
 * gate in place.
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

/*
 * The reference's drawRadar(), options and all: one dataset per scope, gold for
 * the respondent and blue for the leaders, on a 0–5 axis, with a legend because
 * there are two. Its font names are kept as written even though neither face is
 * loaded on the page — the chart falls back exactly as the reference's did.
 */
if (result && canvas && window.Chart) {
  const colors = ['rgba(232,147,63,1)', 'rgba(90,159,218,1)', 'rgba(160,180,202,1)'];
  const codes = result.dimensions.map((d) => d.code);

  new window.Chart(canvas, {
    type: 'radar',
    data: {
      labels: codes,
      datasets: result.scopes.map((scope, i) => ({
        label: scope.label,
        data: codes.map((code) => result.scores[scope.key].dims[code] ?? null),
        borderColor: colors[i % 3],
        backgroundColor: colors[i % 3].replace(',1)', ',.12)'),
        pointBackgroundColor: colors[i % 3],
        borderWidth: 1.5,
        pointRadius: 3,
      })),
    },
    options: {
      responsive: true,
      scales: {
        r: {
          min: 0,
          max: 5,
          ticks: {
            stepSize: 1,
            color: '#6F84A0',
            backdropColor: 'transparent',
            font: { family: 'Spline Sans Mono', size: 10 },
          },
          grid: { color: 'rgba(61,130,196,.18)' },
          angleLines: { color: 'rgba(61,130,196,.18)' },
          pointLabels: { color: '#A0B4CA', font: { family: 'Spline Sans Mono', size: 11 } },
        },
      },
      plugins: {
        legend: {
          display: result.scopes.length > 1,
          labels: { color: '#A0B4CA', font: { family: 'Instrument Sans', size: 12 } },
        },
      },
    },
  });
} else if (canvas && !window.Chart) {
  // The reference's chartFallback(), word for word.
  const note = document.createElement('p');
  note.className = 'muted small';
  note.textContent = 'The chart is unavailable. The tables show the same scores.';
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
 * The reference's showResults() cleared the scorecard in progress once the
 * record was saved, so nothing in the browser still pointed at a finished one:
 * pressing "Start your scorecard" afterwards opened the terms for a new one.
 * The remembered token is dropped here for the same reason. The result stays
 * reachable at its own URL, and "Review answers" passes the token explicitly.
 */
tokenStore.clear();
progressStore.clear();

const scoreAnotherButton = document.getElementById('scoreAnother');

if (scoreAnotherButton) {
  scoreAnotherButton.addEventListener('click', async () => {
    const href = scoreAnotherButton.dataset.assess;

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
          '<a class="btn gold sm" href="' +
          esc(href) +
          '">Continue anyway</a></div>',
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
