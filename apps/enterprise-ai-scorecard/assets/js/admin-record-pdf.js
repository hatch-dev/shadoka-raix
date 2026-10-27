/**
 * "PDF" on an admin record: the respondent's own scorecard, downloaded by an
 * administrator.
 *
 * The reference's `openRecord()` dialog carried this button and called the same
 * `exportPDF()` the respondent's results page used, because that build had every
 * record in browser memory. Here the figures come from
 * /research-data/{id}/scorecard-data, which returns the identical payload
 * SubmissionService::result() renders the respondent's page from — so the copy
 * an admin downloads is the document that respondent received, not a second
 * rendering of it that could drift.
 *
 * A module, unlike the other two admin scripts, because it reuses the public
 * `pdf.js` rather than owning a second copy of the gauge, the count ring, the
 * radar and the six bars. Those are one document's design; two implementations
 * of it would be two documents.
 *
 * The click is delegated because the button usually lives inside the record
 * DIALOG, whose markup is injected — a listener bound at load would never see
 * it. jsPDF is ~350KB and is fetched only when the button is pressed.
 */

import { downloadPdf } from './pdf.js';
import { toast } from './ui.js';

let loading = null;

/** Load jsPDF once, on first use. */
function library(source) {
  if (window.jspdf && window.jspdf.jsPDF) return Promise.resolve();
  if (loading) return loading;

  loading = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = source;
    script.onload = resolve;
    script.onerror = () => reject(new Error('The PDF library did not load.'));
    document.head.appendChild(script);
  });

  return loading;
}

document.addEventListener('click', async (event) => {
  if (event.defaultPrevented || event.button !== 0) return;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

  const trigger = event.target.closest?.('[data-record-pdf]');
  if (!trigger) return;

  event.preventDefault();

  const idle = trigger.textContent;
  trigger.textContent = 'Building…';
  trigger.classList.add('is-busy');
  trigger.disabled = true;

  try {
    await library(trigger.dataset.jspdf);

    const response = await fetch(trigger.dataset.recordPdf, {
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json().catch(() => null);

    if (!response.ok || !payload || payload.success === false) {
      throw new Error(payload?.message || 'The scorecard could not be loaded.');
    }

    downloadPdf(payload.data);
  } catch (error) {
    toast(error.message);
  } finally {
    trigger.textContent = idle;
    trigger.classList.remove('is-busy');
    trigger.disabled = false;
  }
});
