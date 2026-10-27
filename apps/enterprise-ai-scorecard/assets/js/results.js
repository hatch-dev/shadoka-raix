/**
 * The results page is server-rendered, including the two gauges — they are
 * static SVG, and drawing them in JavaScript only means the page arrives with
 * two empty holes in it.
 *
 * This adds the three things markup cannot do: the radar chart, the PDF, and
 * recording that the respondent accepted the result.
 *
 * Scores are read from a JSON script block rather than fetched, so nothing here
 * can disagree with the numbers printed beside it.
 */

import { api, carryForward, tokenStore } from './api.js';
import { toast } from './ui.js';
import { downloadPdf } from './pdf.js';

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
  // Chart.js did not load. A quiet note beats an empty box, and the same six
  // numbers are listed below it either way.
  const note = document.createElement('div');
  note.className = 'muted small';
  note.style.cssText =
    'display:flex;align-items:center;justify-content:center;min-height:200px;' +
    'border:1px dashed var(--rule);border-radius:4px;text-align:center;padding:14px';
  note.textContent = 'The chart could not be drawn. The six scores are listed below.';
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

/* ----------------------------------------------------------------- save -- */

/*
 * The two Save buttons. In the reference build they were what wrote the
 * scorecard down at all — it lived in browser storage until then. Here the
 * answers were saved to the database as they were given, so what is left for
 * these to record is the respondent accepting the result, which is a fact the
 * database did not otherwise hold.
 *
 * "Save · score another organization" then opens a new scorecard carrying the
 * context over, exactly as the reference's `saveAndNew()` did.
 */

const saveAndNewButton = document.getElementById('saveAndNew');
const saveAndStayButton = document.getElementById('saveAndStay');
const confirmedNote = document.getElementById('confirmedNote');

/**
 * Accept the result. Returns the payload, or null if it could not be saved.
 *
 * `leaving` says this press ends in a navigation. The button then stays in its
 * saving state until the page is replaced, instead of snapping back to a label
 * nobody will read.
 */
async function confirmScorecard(button, { leaving = false } = {}) {
  if (!result) {
    toast('The scorecard could not be read from this page.');
    return null;
  }

  const label = button.textContent;

  /*
   * Pin the width before swapping the label. "Saving…" is a quarter the length
   * of "Save · score another organization", and these two buttons share a flex
   * row — left to itself the button collapses, its neighbour slides across, and
   * both jump back a moment later.
   */
  button.style.minWidth = button.offsetWidth + 'px';
  button.disabled = true;
  button.textContent = 'Saving…';

  const restore = () => {
    button.disabled = false;
    button.textContent = label;
    button.style.minWidth = '';
  };

  try {
    const confirmed = await api.confirm(result.token);

    // Repainting the page we are about to leave only adds a flicker: the note
    // belongs to the results page, and the next thing shown is the assessment.
    if (confirmedNote && !leaving) {
      confirmedNote.textContent = 'You accepted this scorecard on ' + confirmed.confirmed_on + '.';
    }

    if (!leaving) restore();

    return confirmed;
  } catch (error) {
    // A failure always hands the button back, navigation or not — there is now
    // nowhere to go, and a button stuck on "Saving…" would say nothing true.
    restore();
    toast(error.message);
    return null;
  }
}

if (saveAndStayButton) {
  saveAndStayButton.addEventListener('click', async () => {
    const confirmed = await confirmScorecard(saveAndStayButton);
    if (!confirmed) return;

    // Deliberately not "Saved to this browser", which is what the reference said
    // and would be false here: it is saved to the server, and it was already
    // there before the button was pressed.
    toast(confirmed.already_confirmed ? 'Already saved' : 'Saved');
  });
}

if (saveAndNewButton) {
  saveAndNewButton.addEventListener('click', async () => {
    const confirmed = await confirmScorecard(saveAndNewButton, { leaving: true });
    if (!confirmed) return;

    // Sector, size and year travel to the next scorecard's profile step; the
    // identity does not, because the point is that it is a different
    // organization and usually a different person.
    carryForward.set(confirmed.carry_forward);

    // This browser's remembered token points at the scorecard being left behind.
    // The new one will record its own.
    tokenStore.clear();

    window.location.href = saveAndNewButton.dataset.assess;
  });
}
