/**
 * The admin PDF buttons: a respondent's own scorecard, the research report, and
 * one organization's report.
 *
 * All three documents are drawn by pdf.js — the same toolkit the respondent's
 * results page uses — from figures the server computed:
 *
 *   [data-record-pdf] → /research-data/{id}/scorecard-data  → downloadPdf()
 *   [data-report-pdf] → /report/data?…                     → downloadGroupPdf()
 *   [data-org-pdf]    → /organisation/report-data?…        → downloadGroupPdf()
 *
 * The click is delegated because the buttons usually live inside dialogs whose
 * markup is injected. jsPDF is ~350KB and is fetched only when a button is
 * pressed; its URL rides on the button as data-jspdf.
 *
 * Loaded once for every admin page, from _shell-footer.php. Never include it per
 * page as well: two listeners would save every PDF twice.
 */

import { downloadGroupPdf, downloadPdf } from './pdf.js';
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
    script.onerror = () => {
      loading = null;
      reject(new Error('PDF library not loaded. Please check your connection and try again.'));
    };
    document.head.appendChild(script);
  });

  return loading;
}

const KINDS = [
  ['recordPdf', downloadPdf],
  ['reportPdf', downloadGroupPdf],
  ['orgPdf', downloadGroupPdf],
];

document.addEventListener('click', async (event) => {
  if (event.defaultPrevented || event.button !== 0) return;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

  const trigger = event.target.closest?.('[data-record-pdf],[data-report-pdf],[data-org-pdf]');
  if (!trigger) return;

  const kind = KINDS.find(([key]) => trigger.dataset[key] !== undefined);
  if (!kind) return;

  event.preventDefault();

  const [key, draw] = kind;
  const idle = trigger.textContent;
  trigger.textContent = 'Building…';
  trigger.disabled = true;

  try {
    await library(trigger.dataset.jspdf);

    const response = await fetch(trigger.dataset[key], { headers: { Accept: 'application/json' } });
    const payload = await response.json().catch(() => null);

    if (!response.ok || !payload || payload.success === false) {
      // The reference's own words when there was nothing to put in a report.
      throw new Error(payload?.message || 'Nothing to export');
    }

    draw(payload.data);
  } catch (error) {
    toast(error.message);
  } finally {
    trigger.textContent = idle;
    trigger.disabled = false;
  }
});
