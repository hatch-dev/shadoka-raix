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

/* --------------------------------------------- the public build's figures -- */

function hexRgb(hex) {
  const h = String(hex || '#6e6e6e').replace('#', '');
  return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
}

function opacity(doc, alpha) {
  try {
    doc.setGState(new doc.GState({ opacity: alpha, 'stroke-opacity': alpha }));
  } catch {
    /* Older viewers draw it opaque, which is still legible. */
  }
}

/** An arc drawn as short segments; jsPDF has no arc primitive. */
function arc(doc, cx, cy, r, a0, a1, rgb, width, alpha) {
  const n = Math.max(6, Math.ceil((a1 - a0) / 3));
  const points = [];

  for (let i = 0; i <= n; i++) {
    const a = ((a0 + ((a1 - a0) * i) / n - 90) * Math.PI) / 180;
    points.push([cx + r * Math.cos(a), cy + r * Math.sin(a)]);
  }

  doc.setDrawColor(...rgb);
  doc.setLineWidth(width);
  doc.setLineCap('round');
  opacity(doc, alpha);
  for (let i = 1; i < points.length; i++) {
    doc.line(points[i - 1][0], points[i - 1][1], points[i][0], points[i][1]);
  }
  opacity(doc, 1);
}

/**
 * The floor on the 1..N scale over 270 degrees, one arc per level. The arcs
 * meet at each rung's own lower cut point, as on the results page.
 */
function gauge(doc, cx, cy, score, rungs, level) {
  const n = rungs.length;
  const r = 17;
  const w = 4;
  const start = -135;
  const sweep = 270;
  const edges = [1, ...rungs.slice(1).map((rung) => Number(rung.min)), n];
  const frac = (v) => (Math.min(n, Math.max(1, v)) - 1) / (n - 1);
  const active = (rung) => level && Number(level.value) === Number(rung.value);

  rungs.forEach((rung, i) => {
    const a0 = start + frac(edges[i]) * sweep;
    const a1 = start + frac(edges[i + 1]) * sweep;
    arc(doc, cx, cy, r, a0, a1, hexRgb(rung.colour), w, active(rung) ? 1 : 0.35);

    const mid = (((a0 + a1) / 2 - 90) * Math.PI) / 180;
    const lx = cx + (r + w / 2 + 3.5) * Math.cos(mid);
    const ly = cy + (r + w / 2 + 3.5) * Math.sin(mid);
    const anchor = lx < cx - 2 ? 'right' : lx > cx + 2 ? 'left' : 'center';

    doc.setFont('helvetica', active(rung) ? 'bold' : 'normal');
    doc.setFontSize(6.5);
    doc.setTextColor(...(active(rung) ? hexRgb(rung.colour) : [120, 120, 120]));
    doc.text(rung.name, lx, ly + 1, { align: anchor });
  });

  if (score !== null && score !== undefined) {
    const a = ((start + frac(score) * sweep - 90) * Math.PI) / 180;
    doc.setFillColor(255, 255, 255);
    doc.setDrawColor(6, 13, 24);
    doc.setLineWidth(0.5);
    doc.circle(cx + r * Math.cos(a), cy + r * Math.sin(a), 1.8, 'FD');
  }

  doc.setFont('times', 'normal');
  doc.setFontSize(16);
  doc.setTextColor(6, 13, 24);
  doc.text(score === null || score === undefined ? '—' : Number(score).toFixed(1), cx, cy + 1.5, { align: 'center' });

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(7);
  if (level) {
    doc.setTextColor(...hexRgb(level.colour));
    doc.text(level.name, cx, cy + 6, { align: 'center' });
  }
}

/** How many competencies were scored, as a segmented ring. */
function countRing(doc, cx, cy, scored, total) {
  const r = 17;
  const w = 4;
  const gap = 8;

  for (let i = 0; i < total; i++) {
    const a0 = -90 + i * (360 / total) + gap / 2;
    const a1 = -90 + (i + 1) * (360 / total) - gap / 2;
    arc(doc, cx, cy, r, a0, a1, i < scored ? [61, 130, 196] : [214, 220, 228], w, 1);
  }

  doc.setFont('times', 'normal');
  doc.setFontSize(16);
  doc.setTextColor(6, 13, 24);
  doc.text(scored + '/' + total, cx, cy + 2, { align: 'center' });
}

/** The competency radar, on the 1..M rung scale. */
function radar(doc, cx, cy, R, competencies, rungCount) {
  const n = competencies.length;
  const m = rungCount;
  const ang = (i) => ((-90 + (i * 360) / n) * Math.PI) / 180;

  doc.setLineWidth(0.25);
  doc.setDrawColor(200, 208, 220);
  for (let k = 1; k <= m; k++) {
    const rr = (R * k) / m;
    for (let i = 0; i < n; i++) {
      const a = ang(i);
      const b = ang((i + 1) % n);
      doc.line(cx + rr * Math.cos(a), cy + rr * Math.sin(a), cx + rr * Math.cos(b), cy + rr * Math.sin(b));
    }
  }
  for (let i = 0; i < n; i++) {
    const a = ang(i);
    doc.line(cx, cy, cx + R * Math.cos(a), cy + R * Math.sin(a));
  }

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(6);
  doc.setTextColor(150, 150, 150);
  for (let k = 1; k <= m; k++) doc.text(String(k), cx + 1.2, cy - (R * k) / m + 0.8);

  doc.setFontSize(7.5);
  doc.setTextColor(60, 60, 60);
  competencies.forEach((competency, i) => {
    const a = ang(i);
    const lx = cx + (R + 5) * Math.cos(a);
    const ly = cy + (R + 5) * Math.sin(a);
    const align = Math.abs(Math.cos(a)) < 0.2 ? 'center' : Math.cos(a) < 0 ? 'right' : 'left';
    doc.text(competency.code, lx, ly + 1, { align });
  });

  const points = competencies
    .map((competency, i) => {
      if (competency.score === null) return null;
      const a = ang(i);
      const v = competency.score / m;
      return [cx + R * v * Math.cos(a), cy + R * v * Math.sin(a)];
    })
    .filter(Boolean);

  if (points.length > 2) {
    const segments = [];
    for (let i = 1; i < points.length; i++) {
      segments.push([points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1]]);
    }

    doc.setDrawColor(232, 147, 63);
    doc.setFillColor(232, 147, 63);
    doc.setLineWidth(0.6);
    opacity(doc, 0.18);
    doc.lines(segments, points[0][0], points[0][1], [1, 1], 'F', true);
    opacity(doc, 1);
    doc.lines(segments, points[0][0], points[0][1], [1, 1], 'S', true);
    points.forEach((p) => doc.circle(p[0], p[1], 0.9, 'F'));
  }
}

/** The standing caveat along the foot of every page. */
function footer(doc, text) {
  const width = doc.internal.pageSize.getWidth();
  const height = doc.internal.pageSize.getHeight();

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7.5);
  doc.setTextColor(...FOOTNOTE);
  const lines = doc.splitTextToSize(text, width - 28);
  doc.text(lines, 14, height - 8 - (lines.length - 1) * 3.4);
}

/** "Sep 25, 2027", from a Y-m-d date, read at local noon so no zone moves the day. */
function writtenDate(ymd) {
  const date = new Date(String(ymd).slice(0, 10) + 'T12:00:00');
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * Build and download the scorecard: the public build's two-page PDF.
 *
 * Page 1 is the results screen drawn for paper — the headline, the floor
 * score, the radar, the two gauges and the seven bars. Page 2 is the reading
 * and the re-scoring dates. Positions, sizes, colours and wording follow the
 * reference's exportPDF(); every figure comes from the payload the page was
 * rendered from, so the PDF cannot disagree with it.
 *
 * @param {object} result the payload rendered on the results page
 */
export function downloadPdf(result) {
  const doc = newDocument();
  if (!doc) return;

  const width = doc.internal.pageSize.getWidth();
  const stamp = result.submitted_on + ' · ' + result.year;
  const level = result.level;
  const levelRgb = level ? hexRgb(level.colour) : [110, 110, 110];
  const weak = result.competencies.find((c) => c.is_floor) || null;
  const unscored = result.competencies.filter((c) => c.score === null);
  const one = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(1));

  let y = header(doc, 'Director Scorecard · Results', stamp);

  /* Who, where, and how to reach them. */
  doc.setTextColor(...INK);
  doc.setFont('times', 'normal');
  doc.setFontSize(20);
  doc.text((result.director || 'Director') + ' · ' + result.board, 14, y);
  y += 7;

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...MUTED);
  doc.text([result.seat_role, result.tenure, result.sector, result.company_size].filter(Boolean).join(' · '), 14, y);
  y += 4.5;

  const contact = [result.email, result.phone].filter(Boolean).join(' · ');
  if (contact) {
    doc.setTextColor(140, 140, 140);
    doc.text(contact, 14, y);
    y += 4.5;
  }
  y += 6;

  /* The headline. */
  doc.setFont('times', 'normal');
  doc.setFontSize(15);
  doc.setTextColor(...INK);
  const head = doc.splitTextToSize(
    'As a director of this company, your Responsible AI level is ' + (level ? level.name : 'unscored') + '.',
    width - 28,
  );
  doc.text(head, 14, y);
  y += head.length * 7 + 4;

  /* The floor score, what it means, and the radar beside it. */
  const top = y;
  doc.setFont('times', 'normal');
  doc.setFontSize(44);
  doc.setTextColor(...levelRgb);
  doc.text(one(result.floor), 14, top + 16);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  if (level) {
    doc.setTextColor(...levelRgb);
    doc.text(level.name, 14, top + 24);
  }

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8.5);
  doc.setTextColor(...MUTED);
  const caption = doc.splitTextToSize(
    'Your level, set by your weakest competency' + (weak ? ', ' + weak.name : '') +
    '. Unweighted average ' + one(result.mean) + '.',
    80,
  );
  doc.text(caption, 14, top + 30);
  let captionEnd = top + 30 + caption.length * 4;

  if (weak) {
    doc.setFontSize(8);
    doc.setTextColor(120, 120, 120);
    const note = doc.splitTextToSize(
      'This is the competency to spend the next quarter on, however strong the rest of the row looks.',
      80,
    );
    doc.text(note, 14, captionEnd + 2);
    captionEnd += note.length * 3.8 + 2;
  }

  if (unscored.length) {
    doc.setFontSize(8);
    doc.setTextColor(150, 150, 150);
    const lines = doc.splitTextToSize(
      unscored.map((c) => c.name).join(', ') + ' unscored. An unscored competency cannot set your floor.',
      80,
    );
    doc.text(lines, 14, captionEnd + 2);
  }

  radar(doc, width - 14 - 34, top + 30, 26, result.competencies, result.rungs.length);

  /* The two gauges. */
  y = top + 66;
  const captionFont = () => {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    doc.setTextColor(90, 90, 90);
  };

  gauge(doc, 60, y + 22, result.floor, result.rungs, level);
  captionFont();
  doc.text('Where your weakest competency sits on the four levels', 60, y + 50, { align: 'center' });

  countRing(doc, width - 60, y + 22, result.scored_count, result.total_count);
  captionFont();
  doc.text('Competencies scored', width - 60, y + 50, { align: 'center' });
  y += 60;

  /* The seven bars. */
  doc.setDrawColor(220, 224, 230);
  doc.setLineWidth(0.3);
  doc.line(14, y, width - 14, y);
  y += 7;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text('Seven competencies', 14, y);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  doc.setTextColor(120, 120, 120);
  doc.text(
    'Each score is the average of that competency’s five statements, out of 4. The weakest sets your level.',
    width - 14,
    y,
    { align: 'right' },
  );
  y += 7;

  bars(doc, y, result.competencies);
  footer(doc, result.pdf_footnote);

  /* Page 2: the reading and the re-scoring dates, as shown on screen. */
  doc.addPage();
  y = header(doc, 'Director Scorecard · Reading and next steps', stamp);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text('Read this before the average', 14, y);
  y += 6;

  // Taken from the page, so the two cannot differ.
  const analysis = document.querySelector('.analysis');
  const reading = analysis ? analysis.textContent.replace(/\s+/g, ' ').trim() : '';

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9.5);
  doc.setTextColor(...TEXT);
  const body = doc.splitTextToSize(
    reading || 'The written summary was not available when this PDF was produced.',
    width - 28,
  );
  doc.text(body, 14, y);
  y += body.length * 4.4 + 10;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text('When to score yourself again', 14, y);
  y += 6;

  const cadence = result.cadence;
  const rows = [
    ['Full instrument', writtenDate(cadence.full.due), cadence.full.note],
    ['Sooner', cadence.sooner.due ? writtenDate(cadence.sooner.due) : 'None due', cadence.sooner.note],
    // The page's incident note runs on; the reference's PDF keeps the first sentence.
    ['On incident', 'Immediately', 'Re-score after any material AI incident at the company.'],
  ];

  rows.forEach(([key, when, note]) => {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    doc.setTextColor(60, 60, 60);
    doc.text(key, 14, y);

    doc.setFont('times', 'normal');
    doc.setFontSize(11);
    doc.setTextColor(...INK);
    doc.text(when, 60, y);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    doc.setTextColor(...MUTED);
    const lines = doc.splitTextToSize(note, width - 14 - 110);
    doc.text(lines, 110, y);
    y += Math.max(1, lines.length) * 4.2 + 4;
  });

  footer(doc, result.pdf_footnote);

  const name = ((result.director || 'director') + '_' + result.board).replace(/[^\w]+/g, '_');
  doc.save('RAI-X_Director_' + name + '.pdf');
}
