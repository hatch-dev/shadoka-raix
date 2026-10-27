/**
 * Every PDF this instrument produces, drawn with jsPDF.
 *
 * A port of the reference build's PDF toolkit — pdfDoc, pdfHeader, pdfText,
 * pdfFooter, pdfRadar, pdfPairedBars, pdfTwoScores, pdfTable — and the two
 * documents it composes from them:
 *
 *  - downloadPdf(result): exportPDF(rec), one respondent's two profiles and
 *    every answer side by side. Used by the results page and by the admin
 *    record dialog, which fetch the same payload.
 *  - downloadGroupPdf(report): writeGroupPDF(recs, …), the research report and
 *    the organization report. The reference computed its figures in the browser
 *    from the records it held; here the server computes them (Stats, over the
 *    same records) and this file only draws.
 *
 * Same A4 page, same millimetre positions, same colours, same wording, same
 * filenames.
 */

import { toast } from './ui.js';

const PRODUCT = 'AI Executive Readiness — The Enterprise Scorecard';
const SCOPE_COLOR = { self: '#E8933F', leadership: '#5A9FDA' };
const SCOPES = ['self', 'leadership'];

/* ------------------------------------------------------------- helpers -- */

const fmt = (v) => (v === null || v === undefined || Number.isNaN(v) ? '—' : Number(v).toFixed(2));

const deltaText = (v) =>
  v === null || v === undefined ? '—' : Math.abs(v) < 0.005 ? '0.00' : (v > 0 ? '+' : '') + fmt(v);

function hexRgb(h) {
  const clean = String(h || '#6F84A0').replace('#', '');
  return [0, 2, 4].map((i) => parseInt(clean.slice(i, i + 2), 16));
}

function fmtDate(iso) {
  return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

/** The rung a score falls in, from the payload's own cut points ([min, max)). */
function bandOf(rungs, score) {
  if (score === null || score === undefined || Number.isNaN(score)) return null;

  for (const rung of rungs) {
    const above = rung.min === null || rung.min === undefined || score >= rung.min;
    const below = rung.max === null || rung.max === undefined || score < rung.max;
    if (above && below) return rung;
  }

  return rungs[rungs.length - 1] || null;
}

/* ------------------------------------------------------------- toolkit -- */

/*
 * pdfDoc(): the standard fonts cannot draw curly quotes or arrows, so every
 * string passing through doc.text is straightened first — the reference's own
 * wrapper, which is why the PDFs say "organization's" with a straight quote.
 */
function pdfDoc() {
  const J = window.jspdf && window.jspdf.jsPDF;

  if (!J) {
    toast('PDF library not loaded. Please check your connection and try again.');
    return null;
  }

  const doc = new J({ unit: 'mm', format: 'a4' });
  const originalText = doc.text.bind(doc);
  const clean = (s) =>
    String(s).replace(/[’‘]/g, "'").replace(/[“”]/g, '"').replace(/→/g, '>').replace(/←/g, '<');

  doc.text = (text, ...args) => originalText(Array.isArray(text) ? text.map(clean) : clean(text), ...args);

  return doc;
}

function pdfHeader(doc, title, sub = '') {
  doc.setFillColor(6, 13, 24);
  doc.rect(0, 0, 210, 26, 'F');
  doc.setTextColor(232, 240, 250);
  doc.setFont('times', 'normal');
  doc.setFontSize(15);
  doc.text(PRODUCT, 14, 12);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(160, 180, 202);
  doc.text(title, 14, 19);
  doc.setFontSize(8);
  doc.text(sub, 196, 19, { align: 'right' });
  return 36;
}

function pdfText(doc, text, x, y, width = 182, size = 9, color = [70, 70, 70], family = 'helvetica') {
  doc.setFont(family, 'normal');
  doc.setFontSize(size);
  doc.setTextColor(...color);
  const lines = doc.splitTextToSize(String(text), width);
  doc.text(lines, x, y);
  return y + lines.length * size * 0.4;
}

function pdfFooter(doc, footnote, copyright) {
  const total = doc.getNumberOfPages();

  for (let i = 1; i <= total; i++) {
    doc.setPage(i);
    doc.setDrawColor(220, 224, 230);
    doc.line(14, 280, 196, 280);
    pdfText(doc, footnote, 14, 284, 182, 7.5, [130, 130, 130]);
    doc.setFontSize(7.5);
    doc.text(copyright, 14, 292);
    doc.text(i + ' / ' + total, 196, 292, { align: 'right' });
  }
}

function pdfRadar(doc, cx, cy, R, sets, codes) {
  const N = codes.length;
  const angle = (i) => ((-90 + (i * 360) / N) * Math.PI) / 180;

  doc.setLineWidth(0.25);
  doc.setDrawColor(200, 208, 220);

  for (let k = 1; k <= 5; k++) {
    for (let i = 0; i < N; i++) {
      const a = angle(i);
      const b = angle((i + 1) % N);
      const r = (R * k) / 5;
      doc.line(cx + r * Math.cos(a), cy + r * Math.sin(a), cx + r * Math.cos(b), cy + r * Math.sin(b));
    }
  }

  for (let i = 0; i < N; i++) {
    const a = angle(i);
    doc.line(cx, cy, cx + R * Math.cos(a), cy + R * Math.sin(a));
  }

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(6);
  doc.setTextColor(150, 150, 150);
  for (let k = 1; k <= 5; k++) doc.text(String(k), cx + 1.2, cy - (R * k) / 5 + 0.8);

  doc.setFontSize(7.5);
  doc.setTextColor(60, 60, 60);
  codes.forEach((code, i) => {
    const a = angle(i);
    doc.text(code, cx + (R + 5) * Math.cos(a), cy + (R + 5) * Math.sin(a) + 1, {
      align: Math.abs(Math.cos(a)) < 0.2 ? 'center' : Math.cos(a) < 0 ? 'right' : 'left',
    });
  });

  SCOPES.forEach((s) => {
    const pts = codes.map((code, i) => {
      const a = angle(i);
      const v = sets[s].dims[code];
      return [cx + ((R * v) / 5) * Math.cos(a), cy + ((R * v) / 5) * Math.sin(a)];
    });
    const segs = pts.slice(1).map((p, i) => [p[0] - pts[i][0], p[1] - pts[i][1]]);

    doc.setDrawColor(...hexRgb(SCOPE_COLOR[s]));
    doc.setFillColor(...hexRgb(SCOPE_COLOR[s]));
    doc.setLineWidth(0.6);
    doc.setGState(new doc.GState({ opacity: 0.18, 'stroke-opacity': 0.18 }));
    doc.lines(segs, ...pts[0], [1, 1], 'F', true);
    doc.setGState(new doc.GState({ opacity: 1, 'stroke-opacity': 1 }));
    doc.lines(segs, ...pts[0], [1, 1], 'S', true);
    pts.forEach((p) => doc.circle(...p, 0.9, 'F'));
  });
}

function pdfPairedBars(doc, y, scores, dimensions, rungs) {
  dimensions.forEach((d) => {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    doc.setTextColor(20, 30, 45);
    doc.text(d.code + '  ' + d.name, 14, y);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7.5);
    doc.text('Difference ' + deltaText(scores.self.dims[d.code] - scores.leadership.dims[d.code]), 196, y, {
      align: 'right',
    });
    y += 5;

    SCOPES.forEach((s) => {
      const value = scores[s].dims[d.code];
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(7.5);
      doc.setTextColor(90, 90, 90);
      doc.text(s === 'self' ? 'Self' : 'Leaders', 14, y);
      doc.setFillColor(235, 238, 243);
      doc.rect(34, y - 3.5, 102, 4.5, 'F');
      doc.setFillColor(...hexRgb(SCOPE_COLOR[s]));
      doc.rect(34, y - 3.5, (102 * (value - 1)) / 4, 4.5, 'F');
      doc.text(fmt(value), 148, y, { align: 'right' });
      doc.text((bandOf(rungs, value) || { name: '' }).name, 154, y);
      y += 5;
    });

    y += 3;
  });

  return y;
}

function pdfTwoScores(doc, y, scores, rungs, group = false) {
  SCOPES.forEach((s, i) => {
    const x = 14 + i * 94;
    const val = scores[s];
    const level = bandOf(rungs, val.mean) || { name: '', colour: '#6F84A0' };

    doc.setDrawColor(218, 225, 235);
    doc.setFillColor(248, 249, 252);
    doc.roundedRect(x, y, 88, 43, 1, 1, 'FD');
    pdfText(
      doc,
      group
        ? s === 'self'
          ? 'Mean of respondents’ self-ratings'
          : 'Mean of respondents’ ratings of leaders'
        : s === 'self'
          ? 'Your executive readiness'
          : 'Your assessment of your organization’s leaders',
      x + 4,
      y + 6,
      80,
      8,
    );
    doc.setFont('times', 'normal');
    doc.setTextColor(...hexRgb(level.colour));
    doc.setFontSize(44);
    doc.text(fmt(val.mean), x + 4, y + 27);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor(110, 110, 110);
    doc.text('/ 5', x + 40, y + 27);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(...hexRgb(level.colour));
    doc.text(level.name, x + 4, y + 36);
  });

  return y + 50;
}

function pdfTable(doc, y, headers, rows, widths) {
  const xs = [14];
  for (let i = 1; i < widths.length; i++) xs.push(xs[i - 1] + widths[i - 1]);

  function head() {
    doc.setFillColor(230, 237, 245);
    doc.rect(14, y - 4, 182, 8, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7.5);
    doc.setTextColor(35, 45, 60);
    headers.forEach((h, i) => doc.text(h, xs[i] + 2, y));
    y += 8;
  }

  head();

  for (const row of rows) {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7.5);
    const cells = row.map((v, i) => doc.splitTextToSize(String(v ?? ''), widths[i] - 4));
    const height = Math.max(7, ...cells.map((a) => a.length * 3.3 + 3));

    if (y + height > 273) {
      doc.addPage();
      y = pdfHeader(doc, 'Research results continued');
      head();
    }

    doc.setFont('helvetica', 'normal');
    doc.setTextColor(65, 65, 65);
    cells.forEach((c, i) => doc.text(c, xs[i] + 2, y));
    y += height;
    doc.setDrawColor(228, 232, 238);
    doc.line(14, y - 3, 196, y - 3);
  }

  return y;
}

/* ------------------------------------------------ the respondent's PDF -- */

/**
 * exportPDF(rec): one questionnaire.
 *
 * @param {object} result the payload SubmissionService::result() builds
 */
export function downloadPdf(result) {
  const doc = pdfDoc();
  if (!doc) return;

  const rungs = result.rungs;
  const dimensions = result.dimensions;
  const codes = dimensions.map((d) => d.code);
  const scores = result.scores;

  let y = pdfHeader(doc, 'Individual and leadership results', fmtDate(result.timestamp) + ' · ' + result.year);

  y = pdfText(doc, result.organisation + ' · ' + (result.respondent || 'Respondent'), 14, y, 182, 20, [6, 13, 24], 'times') + 2;
  y = pdfText(doc, [result.role, result.tenure, result.sector, result.size].filter(Boolean).join(' · '), 14, y, 182, 9, [110, 110, 110]) + 1;
  y = pdfText(doc, [result.email, result.phone].filter(Boolean).join(' · '), 14, y, 182, 9, [140, 140, 140]) + 4;

  if (result.synthetic) y = pdfText(doc, 'SYNTHETIC DEMONSTRATION RECORD', 14, y, 182, 8, [175, 55, 45]) + 2;

  y = pdfTwoScores(doc, y, scores, rungs);
  const top = y;
  pdfRadar(doc, 54, top + 31, 25, scores, codes);
  pdfText(doc, 'Both profiles reflect your answers. Each overall score averages 12 ratings. Each dimension averages three.', 103, top + 6, 90, 9);
  pdfText(doc, 'Gold: You\nBlue: Your organization’s leaders', 103, top + 29, 90, 8);
  pdfText(doc, 'Overall difference: ' + deltaText(result.difference.mean), 103, top + 45, 90, 10);
  y = top + 72;

  y = pdfPairedBars(doc, y, scores, dimensions, rungs);
  pdfText(doc, 'Differences are self minus leadership. They describe perceptions, not demonstrated performance. Levels use unrounded scores.', 14, y + 1, 182, 7.5);

  doc.addPage();
  y = pdfHeader(doc, 'Your answers side by side', 'Assessment year ' + result.year);
  y = pdfText(doc, 'You rated both yourself and the organization’s leaders. A positive difference means you rated yourself higher. Equal scores can both be low or both be high.', 14, y, 182, 8) + 5;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(7.5);
  doc.setTextColor(40, 40, 40);
  doc.text('Question', 14, y);
  doc.text('You', 145, y, { align: 'center' });
  doc.text('Leaders', 167, y, { align: 'center' });
  doc.text('Difference', 190, y, { align: 'right' });
  y += 5;

  for (const d of dimensions) {
    doc.setFillColor(235, 240, 247);
    doc.rect(14, y - 3, 182, 6, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(25, 40, 65);
    doc.setFontSize(8);
    doc.text(d.code + ' · ' + d.name, 16, y + 1);
    y += 8;

    for (let i = 0; i < d.items.length; i++) {
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
      const lines = doc.splitTextToSize(d.code + ' ' + (i + 1) + '  ' + d.items[i].question, 118);
      const h = Math.max(10, lines.length * 3.35 + 3);
      doc.setTextColor(45, 45, 45);
      doc.text(lines, 14, y);
      doc.setFont('helvetica', 'bold');
      doc.text(String(result.responses.self[d.code][i]), 145, y, { align: 'center' });
      doc.text(String(result.responses.leadership[d.code][i]), 167, y, { align: 'center' });
      doc.text(deltaText(result.difference.items[d.code][i]), 190, y, { align: 'right' });
      y += h;
      doc.setDrawColor(225, 230, 235);
      doc.setLineWidth(0.15);
      doc.line(14, y - 4, 196, y - 4);
    }
  }

  pdfFooter(doc, result.pdf_footnote, result.copyright);
  doc.save(
    'AI_Executive_Readiness_' +
      (result.organisation + '_' + (result.respondent || 'respondent')).replace(/[^\w]+/g, '_') +
      '.pdf',
  );
}

/* ------------------------------------------------------ the group PDFs -- */

/**
 * writeGroupPDF(recs, title, description, name): the research report and the
 * organization report, from figures the server computed over the same records.
 *
 * @param {object} report the payload ReportData::build() returns
 */
export function downloadGroupPdf(report) {
  const doc = pdfDoc();
  if (!doc) return;

  const rungs = report.rungs;
  const dimensions = report.dimensions;

  let y = pdfHeader(doc, report.title, fmtDate(new Date().toISOString()));
  y = pdfText(doc, report.description, 14, y, 182, 8) + 3;
  y = pdfText(
    doc,
    `${report.counts.questionnaires} questionnaires from ${report.counts.respondents} respondents across ${report.counts.organisations} organizations. Each questionnaire contributes once to each mean. Repeated assessments contribute separately.`,
    14,
    y,
    182,
    9,
  ) + 4;

  y = pdfTwoScores(doc, y, report.scores, rungs, true);
  y = pdfPairedBars(doc, y, report.scores, dimensions, rungs);
  y = pdfText(doc, 'Self-ratings describe the respondents. Ratings of leaders describe those respondents’ perceptions of the leadership team. Both are self-reported and unverified.', 14, y + 2, 182, 8);

  doc.addPage();
  y = pdfHeader(doc, 'Dimension spread and comparison');
  y = pdfTable(doc, y, ['Dimension / rating set', 'n', 'Mean', 'Minimum', 'Maximum', 'SD'], report.spread_rows, [67, 15, 25, 25, 25, 25]);
  y += 5;
  y = pdfText(doc, 'Differences are self minus leadership. Counts show the direction of each respondent’s difference.', 14, y, 182, 8) + 4;
  y = pdfTable(doc, y, ['Measure', 'Mean diff.', 'Self higher', 'Equal', 'Leaders higher'], report.comparison_rows, [76, 26, 26, 24, 30]);

  doc.addPage();
  y = pdfHeader(doc, 'Levels and questionnaire records');
  y = pdfTable(doc, y, ['Level', 'Self-ratings', 'Ratings of leaders'], report.level_rows, [70, 56, 56]);
  y += 7;
  pdfTable(doc, y, ['Organization / respondent', 'Year', 'Self', 'Leaders', 'Difference'], report.record_rows, [102, 20, 20, 20, 20]);

  pdfFooter(doc, report.pdf_footnote, report.copyright);
  doc.save(report.filename);
}
