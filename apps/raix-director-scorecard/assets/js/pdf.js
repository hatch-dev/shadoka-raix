/**
 * The director's PDF, generated with jsPDF.
 *
 * A direct port of the single-file build's exportPDF(): same A4 page, same
 * millimetre positions, same colours, same wording, same filename. It replaces
 * the browser-print route that stood in for it, because "Download PDF" should
 * hand over the document the instrument was designed around rather than a
 * screenshot of the web page.
 *
 * Everything it draws comes from the result payload the server already built,
 * so the PDF cannot disagree with the page it was downloaded from.
 */

import { toast } from './ui.js';

/* The palette, as the reference passed it to jsPDF. */
const INK = [6, 13, 24];
const PAPER = [232, 240, 250];
const STONE = [160, 180, 202];
const MUTED = [110, 110, 110];
const TEXT = [40, 40, 40];
const TRACK = [235, 238, 243];
const CALLOUT = [253, 247, 232];
const GOLD_TEXT = [217, 164, 65];
const FOOTNOTE = [130, 130, 130];
const RULE_LABEL = [120, 120, 120];

function newDocument() {
  const JsPDF = window.jspdf && window.jspdf.jsPDF;

  if (!JsPDF) {
    toast('The PDF library did not load. Try reloading the page.');
    return null;
  }

  return new JsPDF({ unit: 'mm', format: 'a4' });
}

/** The dark band across the top. Returns the y to carry on from. */
function header(doc, title, subtitle) {
  const width = doc.internal.pageSize.getWidth();

  doc.setFillColor(...INK);
  doc.rect(0, 0, width, 26, 'F');

  doc.setTextColor(...PAPER);
  doc.setFont('times', 'normal');
  doc.setFontSize(18);
  doc.text('RAI—X', 14, 12);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...STONE);
  doc.text(title, 14, 19);

  doc.setFontSize(8);
  doc.text(subtitle, width - 14, 19, { align: 'right' });

  return 36;
}

/**
 * One bar per competency: name, track, fill, score, rung label.
 *
 * The competency that set the level is drawn in gold and bold — on paper that
 * is the only cue left, with no "Floor" tag and no colour-coded row.
 */
function bars(doc, y, competencies) {
  const width = doc.internal.pageSize.getWidth();
  const x0 = 14;
  const labelWidth = 62;
  const barX = x0 + labelWidth;
  const barWidth = width - barX - 14 - 22;

  doc.setFontSize(9);

  for (const competency of competencies) {
    const isFloor = competency.is_floor;

    doc.setTextColor(...(isFloor ? GOLD_TEXT : TEXT));
    doc.setFont('helvetica', isFloor ? 'bold' : 'normal');
    doc.text(competency.name, x0, y + 3.5);

    doc.setFillColor(...TRACK);
    doc.rect(barX, y, barWidth, 4.5, 'F');

    if (competency.score !== null && competency.rung) {
      const hex = String(competency.rung.colour).replace('#', '');
      doc.setFillColor(
        parseInt(hex.slice(0, 2), 16),
        parseInt(hex.slice(2, 4), 16),
        parseInt(hex.slice(4, 6), 16),
      );
      doc.rect(barX, y, (barWidth * (competency.score - 1)) / 3, 4.5, 'F');
    }

    doc.setTextColor(...TEXT);
    doc.setFont('helvetica', 'normal');
    doc.text(
      competency.score === null ? '—' : competency.score.toFixed(2),
      width - 14,
      y + 3.5,
      { align: 'right' },
    );

    doc.setFontSize(7);
    doc.setTextColor(...RULE_LABEL);
    doc.text(competency.rung ? competency.rung.name : 'UNSCORED', width - 14 - 10, y + 3.5, {
      align: 'right',
    });
    doc.setFontSize(9);

    y += 9;
  }

  return y;
}

/**
 * Build and download the scorecard.
 *
 * @param {object} result the payload rendered on the results page
 */
export function downloadPdf(result) {
  const doc = newDocument();
  if (!doc) return;

  const width = doc.internal.pageSize.getWidth();
  const fmt = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(2));

  let y = header(doc, 'Director Scorecard', result.submitted_on + ' · ' + result.year);

  /* Who and where. */
  doc.setTextColor(...INK);
  doc.setFont('times', 'normal');
  doc.setFontSize(22);
  doc.text((result.director || 'Director') + ' · ' + (result.board || 'Anonymous'), 14, y);
  y += 8;

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...MUTED);
  doc.text(
    [result.seat_role, result.tenure, result.sector, result.company_size].filter(Boolean).join(' · '),
    14,
    y,
  );
  y += 12;

  /* The level, and what set it. */
  doc.setFont('times', 'normal');
  doc.setFontSize(48);
  doc.setTextColor(...INK);
  doc.text(result.level ? String(result.level.value) : '—', 14, y + 10);

  doc.setFontSize(12);
  doc.text(result.level ? 'Level · ' + result.level.name : 'Unscored', 34, y + 2);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  doc.setTextColor(...MUTED);
  doc.text(
    'Set by the weakest competency. Unweighted average ' + fmt(result.mean) + '.',
    34,
    y + 8,
  );
  y += 22;

  /* The floor callout. */
  const floor = result.competencies.find((c) => c.is_floor);

  if (floor) {
    doc.setFillColor(...CALLOUT);
    doc.rect(14, y, width - 28, 14, 'F');
    doc.setTextColor(60, 60, 60);
    doc.setFontSize(9);
    doc.text(
      doc.splitTextToSize(
        'Floor: ' + floor.name + ' at ' + fmt(result.floor) +
        '. The question to press: ' + floor.board_question,
        width - 34,
      ),
      17,
      y + 5,
    );
    y += 20;
  }

  y = bars(doc, y, result.competencies) + 6;

  /* The written read, taken from the page so the two cannot differ. */
  const analysis = document.querySelector('.analysis');
  const text = analysis ? analysis.textContent.replace(/\s+/g, ' ').trim() : '';

  if (text) {
    doc.setFontSize(10);
    doc.setTextColor(...TEXT);
    const lines = doc.splitTextToSize(text, width - 28);
    doc.text(lines, 14, y);
    y += lines.length * 4.6 + 6;
  }

  /* The standing caveat, at the foot of the page. */
  doc.setFontSize(8);
  doc.setTextColor(...FOOTNOTE);
  doc.text(result.pdf_footnote, 14, doc.internal.pageSize.getHeight() - 12, {
    maxWidth: width - 28,
  });

  const name = ((result.director || 'director') + '_' + (result.board || 'Anonymous'))
    .replace(/[^\w]+/g, '_');

  doc.save('RAI-X_Director_' + name + '.pdf');
}
