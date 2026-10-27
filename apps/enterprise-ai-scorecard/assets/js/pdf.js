/**
 * The respondent's PDF, generated with jsPDF.
 *
 * A port of the reference build's exportPDF(): same A4 page, same millimetre
 * positions, same drawn gauge, count ring and radar, same filename shape. It is
 * the document the instrument was designed around rather than a screenshot of
 * the web page.
 *
 * Everything it draws comes from the result payload the server already built, so
 * the PDF cannot disagree with the page it was downloaded from. In particular
 * the band edges and the level names are read off the payload — the reference
 * had BAND_EDGES and RUNGS as module constants, which is fine in a single file
 * and wrong the moment an instrument version can move a cut point.
 */

import { toast } from './ui.js';

/* The palette, as the reference passed it to jsPDF. */
const INK = [6, 13, 24];
const PAPER = [232, 240, 250];
const STONE = [160, 180, 202];
const MUTED = [110, 110, 110];
const TEXT = [40, 40, 40];
const TRACK = [235, 238, 243];
const FOOTNOTE = [130, 130, 130];
const RULE_LABEL = [120, 120, 120];
const GOLD_TEXT = [217, 164, 65];

/* The 1–5 scale, drawn over 270 degrees starting at the lower left. */
const GAUGE_START = -135;
const GAUGE_SWEEP = 270;

function newDocument() {
  const JsPDF = window.jspdf && window.jspdf.jsPDF;

  if (!JsPDF) {
    toast('The PDF library did not load. Try reloading the page.');
    return null;
  }

  return new JsPDF({ unit: 'mm', format: 'a4' });
}

function hexRgb(hex) {
  const value = String(hex || '#6F84A0').replace('#', '');
  return [
    parseInt(value.slice(0, 2), 16),
    parseInt(value.slice(2, 4), 16),
    parseInt(value.slice(4, 6), 16),
  ];
}

/** jsPDF's graphics state is optional in some builds; never fail over opacity. */
function setOpacity(doc, alpha) {
  try {
    doc.setGState(new doc.GState({ opacity: alpha, 'stroke-opacity': alpha }));
  } catch {
    /* Older jsPDF: the arc simply draws at full strength. */
  }
}

/** An arc, as a short polyline. jsPDF has no arc primitive. */
function arc(doc, cx, cy, r, a0, a1, rgb, weight, alpha) {
  const steps = Math.max(6, Math.ceil((a1 - a0) / 3));
  const points = [];

  for (let i = 0; i <= steps; i++) {
    const angle = ((a0 + ((a1 - a0) * i) / steps - 90) * Math.PI) / 180;
    points.push([cx + r * Math.cos(angle), cy + r * Math.sin(angle)]);
  }

  doc.setDrawColor(...rgb);
  doc.setLineWidth(weight);
  doc.setLineCap('round');
  setOpacity(doc, alpha);

  for (let i = 1; i < points.length; i++) {
    doc.line(points[i - 1][0], points[i - 1][1], points[i][0], points[i][1]);
  }

  setOpacity(doc, 1);
}

/**
 * The gauge: one arc per level, edges where the instrument bands, a marker at
 * the score. The level the score falls in is drawn at full strength and the rest
 * at a third, which is how the reference showed which band you are in.
 */
function gauge(doc, cx, cy, score, rungs, edges) {
  const r = 17;
  const weight = 4;
  const stops = [1, ...edges, 5];
  const current = rungs.find(
    (rung) =>
      (rung.min === null || score >= rung.min) && (rung.max === null || score < rung.max),
  );

  rungs.forEach((rung, i) => {
    const a0 = GAUGE_START + ((stops[i] - 1) / 4) * GAUGE_SWEEP;
    const a1 = GAUGE_START + ((stops[i + 1] - 1) / 4) * GAUGE_SWEEP;
    const active = current && current.value === rung.value;

    arc(doc, cx, cy, r, a0, a1, hexRgb(rung.colour), weight, active ? 1 : 0.35);

    const mid = (((a0 + a1) / 2 - 90) * Math.PI) / 180;
    const lx = cx + (r + weight / 2 + 3.5) * Math.cos(mid);
    const ly = cy + (r + weight / 2 + 3.5) * Math.sin(mid);
    const align = lx < cx - 2 ? 'right' : lx > cx + 2 ? 'left' : 'center';

    doc.setFont('helvetica', active ? 'bold' : 'normal');
    doc.setFontSize(6.5);
    doc.setTextColor(...(active ? hexRgb(rung.colour) : RULE_LABEL));
    doc.text(rung.name, lx, ly + 1, { align });
  });

  if (score !== null && score !== undefined) {
    const angle =
      ((GAUGE_START + ((Math.min(5, Math.max(1, score)) - 1) / 4) * GAUGE_SWEEP - 90) * Math.PI) /
      180;
    doc.setFillColor(255, 255, 255);
    doc.setDrawColor(...INK);
    doc.setLineWidth(0.5);
    doc.circle(cx + r * Math.cos(angle), cy + r * Math.sin(angle), 1.8, 'FD');
  }

  doc.setFont('times', 'normal');
  doc.setFontSize(16);
  doc.setTextColor(...INK);
  doc.text(score === null || score === undefined ? '—' : score.toFixed(1), cx, cy + 1.5, {
    align: 'center',
  });

  if (current) {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7);
    doc.setTextColor(...hexRgb(current.colour));
    doc.text(current.name, cx, cy + 6, { align: 'center' });
  }
}

/** How many of the six dimensions were scored, as a segmented ring. */
function countRing(doc, cx, cy, n, total) {
  const r = 17;
  const weight = 4;
  const gap = 8;

  for (let i = 0; i < total; i++) {
    const a0 = -90 + i * (360 / total) + gap / 2;
    const a1 = -90 + (i + 1) * (360 / total) - gap / 2;
    arc(doc, cx, cy, r, a0, a1, i < n ? [61, 130, 196] : [214, 220, 228], weight, 1);
  }

  doc.setFont('times', 'normal');
  doc.setFontSize(16);
  doc.setTextColor(...INK);
  doc.text(n + '/' + total, cx, cy + 2, { align: 'center' });
}

/** The six dimensions as a radar, on a 0–5 web. */
function radar(doc, cx, cy, R, dimensions) {
  const n = dimensions.length;
  const angle = (i) => ((-90 + (i * 360) / n) * Math.PI) / 180;

  doc.setLineWidth(0.25);
  doc.setDrawColor(200, 208, 220);

  for (let ring = 1; ring <= 5; ring++) {
    const rr = (R * ring) / 5;
    for (let i = 0; i < n; i++) {
      const a = angle(i);
      const b = angle((i + 1) % n);
      doc.line(cx + rr * Math.cos(a), cy + rr * Math.sin(a), cx + rr * Math.cos(b), cy + rr * Math.sin(b));
    }
  }

  for (let i = 0; i < n; i++) {
    const a = angle(i);
    doc.line(cx, cy, cx + R * Math.cos(a), cy + R * Math.sin(a));
  }

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(6);
  doc.setTextColor(150, 150, 150);
  for (let ring = 1; ring <= 5; ring++) {
    doc.text(String(ring), cx + 1.2, cy - (R * ring) / 5 + 0.8);
  }

  doc.setFontSize(7.5);
  doc.setTextColor(60, 60, 60);
  dimensions.forEach((dimension, i) => {
    const a = angle(i);
    const lx = cx + (R + 5) * Math.cos(a);
    const ly = cy + (R + 5) * Math.sin(a);
    doc.text(dimension.code, lx, ly + 1, {
      align: Math.abs(Math.cos(a)) < 0.2 ? 'center' : Math.cos(a) < 0 ? 'right' : 'left',
    });
  });

  const points = dimensions
    .map((dimension, i) => {
      if (dimension.score === null || dimension.score === undefined) return null;
      const a = angle(i);
      return [cx + R * (dimension.score / 5) * Math.cos(a), cy + R * (dimension.score / 5) * Math.sin(a)];
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
    setOpacity(doc, 0.18);
    doc.lines(segments, points[0][0], points[0][1], [1, 1], 'F', true);
    setOpacity(doc, 1);
    doc.lines(segments, points[0][0], points[0][1], [1, 1], 'S', true);
    points.forEach((p) => doc.circle(p[0], p[1], 0.9, 'F'));
  }
}

/**
 * One bar per dimension: name, track, fill, score, level label.
 *
 * The weakest one is drawn in gold and bold and tagged "floor" — on paper that
 * is the only cue left, with no coloured row and no tag element. It is labelled
 * rather than just highlighted, because a bold row with no word beside it would
 * read as "this is your level", which is the one thing it is not.
 */
function bars(doc, y, dimensions) {
  const width = doc.internal.pageSize.getWidth();
  const x0 = 14;
  const labelWidth = 62;
  const barX = x0 + labelWidth;
  const barWidth = width - barX - 14 - 22;

  doc.setFontSize(9);

  for (const dimension of dimensions) {
    const isFloor = dimension.is_floor;

    doc.setTextColor(...(isFloor ? GOLD_TEXT : TEXT));
    doc.setFont('helvetica', isFloor ? 'bold' : 'normal');
    doc.text(dimension.name + (isFloor ? '  (floor)' : ''), x0, y + 3.5);

    doc.setFillColor(...TRACK);
    doc.rect(barX, y, barWidth, 4.5, 'F');

    if (dimension.score !== null && dimension.rung) {
      doc.setFillColor(...hexRgb(dimension.rung.colour));
      // Five points, so the track runs from 1 to 5 and the fill is (v-1)/4.
      // Dividing by 3 here — the four-level instrument's divisor — would draw
      // every bar a third too long and overflow the track above 4.
      doc.rect(barX, y, (barWidth * (dimension.score - 1)) / 4, 4.5, 'F');
    }

    doc.setTextColor(...TEXT);
    doc.setFont('helvetica', 'normal');
    doc.text(dimension.score === null ? '—' : dimension.score.toFixed(2), width - 14, y + 3.5, {
      align: 'right',
    });

    doc.setFontSize(7);
    doc.setTextColor(...RULE_LABEL);
    doc.text(dimension.rung ? dimension.rung.name : 'UNSCORED', width - 14 - 10, y + 3.5, {
      align: 'right',
    });
    doc.setFontSize(9);

    y += 9;
  }

  return y;
}

/** The dark band across the top. Returns the y to carry on from. */
function header(doc, title, subtitle) {
  const width = doc.internal.pageSize.getWidth();

  doc.setFillColor(...INK);
  doc.rect(0, 0, width, 26, 'F');

  doc.setTextColor(...PAPER);
  doc.setFont('times', 'normal');
  doc.setFontSize(15);
  doc.text('AI Readiness — The Enterprise Scorecard', 14, 12);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...STONE);
  doc.text(title, 14, 19);

  doc.setFontSize(8);
  doc.text(subtitle, width - 14, 19, { align: 'right' });

  return 36;
}

/**
 * Build and download the scorecard.
 *
 * One page: the hero, the radar, the gauges and the six dimension bars. It
 * carried the written read as well, on a second page, until that section came
 * off the results page -- a document that says more than the page it was
 * downloaded from is its own kind of wrong, and the leftover page was a header
 * over a single paragraph.
 *
 * @param {object} result the payload the results page was rendered from
 */
export function downloadPdf(result) {
  const doc = newDocument();
  if (!doc) return;

  const width = doc.internal.pageSize.getWidth();
  const height = doc.internal.pageSize.getHeight();
  const fmt = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(2));

  const rungs = (result.rungs || []).map((rung) => ({
    value: rung.value,
    name: rung.name,
    colour: rung.colour,
    min: rung.min,
    max: rung.max,
  }));
  const edges = result.band_edges || [];

  const unscored = result.dimensions.filter((d) => d.score === null);
  const level = result.level;
  const levelRgb = level ? hexRgb(level.colour) : [110, 110, 110];

  let y = header(doc, 'Results', result.submitted_on + ' · ' + result.year);

  /* Who. */
  doc.setTextColor(...INK);
  doc.setFont('times', 'normal');
  doc.setFontSize(20);
  doc.text(result.organisation + ' · ' + result.respondent, 14, y);
  y += 7;

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...MUTED);
  doc.text([result.role, result.tenure, result.sector, result.size].filter(Boolean).join(' · '), 14, y);
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
  const headline = doc.splitTextToSize(
    'By your own assessment, your organization’s AI readiness level is ' +
      (level ? level.name : 'unscored') +
      '.',
    width - 28,
  );
  doc.text(headline, 14, y);
  y += headline.length * 7 + 4;

  /* The score, left; the radar, right. */
  const top = y;

  doc.setFont('times', 'normal');
  doc.setFontSize(44);
  doc.setTextColor(...levelRgb);
  doc.text(result.mean === null ? '—' : result.mean.toFixed(1), 14, top + 16);

  if (level) {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(...levelRgb);
    doc.text(level.name, 14, top + 24);
  }

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8.5);
  doc.setTextColor(...MUTED);
  const caption = doc.splitTextToSize(
    'Your overall score out of 5, from the average of all your answers.',
    80,
  );
  doc.text(caption, 14, top + 30);

  let captionBottom = top + 30 + caption.length * 4;

  if (unscored.length) {
    doc.setFontSize(8);
    doc.setTextColor(150, 150, 150);
    const note = doc.splitTextToSize(
      unscored.map((d) => d.name).join(', ') + ' unscored and left out of the average.',
      80,
    );
    doc.text(note, 14, captionBottom + 2);
    captionBottom += note.length * 4 + 2;
  }

  radar(doc, width - 14 - 34, top + 30, 26, result.dimensions);
  y = top + 66;

  /* The gauge and the count ring, with their captions. */
  const captionFont = () => {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    doc.setTextColor(90, 90, 90);
  };

  gauge(doc, 60, y + 22, result.mean, rungs, edges);
  captionFont();
  doc.text('Where your score sits on the five levels', 60, y + 50, { align: 'center' });

  countRing(doc, width - 60, y + 22, result.scored_count, result.total_count);
  captionFont();
  doc.text('Dimensions scored', width - 60, y + 50, { align: 'center' });
  y += 60;

  /* The six dimensions. */
  doc.setDrawColor(220, 224, 230);
  doc.setLineWidth(0.3);
  doc.line(14, y, width - 14, y);
  y += 7;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text('Six dimensions', 14, y);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  doc.setTextColor(120, 120, 120);
  doc.text('Each score is the average of that dimension’s five answers, out of 5.', width - 14, y, {
    align: 'right',
  });
  y += 7;

  bars(doc, y, result.dimensions);

  /* The standing caveat, at the foot of every page. */
  const pages = doc.internal.getNumberOfPages();
  const footnote = doc.splitTextToSize(result.pdf_footnote, width - 28);

  for (let page = 1; page <= pages; page++) {
    doc.setPage(page);
    doc.setFontSize(7.5);
    doc.setTextColor(...FOOTNOTE);
    doc.text(footnote, 14, height - 8 - (footnote.length - 1) * 3.4);
  }

  const name = (result.organisation + '_' + result.respondent).replace(/[^\w]+/g, '_');

  doc.save('Enterprise_Scorecard_' + name + '.pdf');
}
