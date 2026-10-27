/**
 * Dump the Human Edge Scorecard instrument out of the supplied page, verbatim.
 * Nothing is retyped: the page's own `const DIMS = [...]` (and every list the
 * console needs) is evaluated and serialised, so a transcription error cannot
 * enter the dataset.
 *
 *   node apps/human-edge-scorecard/database/seeds/data/extract-instrument.mjs \
 *        apps/human-edge-scorecard/database/seeds/data/instrument-v1.json
 */
import { readFileSync, writeFileSync } from 'node:fs';
import vm from 'node:vm';

const SRC = 'C:/xampp/htdocs/RAIX/Human_Edge_Scorecard/index.html';
const OUT = process.argv[2];

const html = readFileSync(SRC, 'utf8');
const lines = html.split(/\r?\n/);

/* The app's own <script> block: line 821 up to the boot calls (line 2722). */
const js = lines.slice(820, 2721).join('\n');

const element = { textContent: '', innerHTML: '' };
const sandbox = {
  document: {
    createElement: () => ({ ...element }),
    getElementById: () => null,
    querySelectorAll: () => [],
    querySelector: () => null,
    body: { classList: { toggle() {}, add() {} } },
  },
  window: { matchMedia: () => ({ matches: false }) },
  location: { protocol: 'https:' },
  sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
  fetch: () => Promise.reject(new Error('no network')),
  console,
  setTimeout,
  clearTimeout,
  IntersectionObserver: class {},
  Chart: undefined,
};
sandbox.globalThis = sandbox;

const context = vm.createContext(sandbox);
vm.runInContext(
  js +
    '\n;globalThis.__out = {DIMS, RUNGS, BAND_EDGES, SECTORS, SIZES, ROLES, TENURES,' +
    ' GUIDE_TABS, CORR_MIN_N, SEG_MIN, METRICS, AI_BANDS, AI_ITEMS, ARCHETYPES, SECTOR_PRIOR, RATER_BIAS,' +
    ' ORG_A, ORG_B, SYN_FIRST, SYN_LAST, NOTICE_VERSION, EMAIL_RE: EMAIL_RE.source, BLOCKED_EMAIL_DOMAINS};',
  context,
  { filename: 'human-edge-page.js' },
);

const r = sandbox.__out;

/* Band edges 1.80 / 2.60 / 3.40 / 4.20 become the five rungs' own [min, max)
   cut points, which is how the platform's Bander reads them. */
const edges = [null, ...r.BAND_EDGES, null];
const bands = r.RUNGS.map((rung, i) => ({ rung_value: rung.v, min: edges[i], max: edges[i + 1] }));

const out = {
  slug: 'human-edge-scorecard',
  version: '1.0',
  name: 'The Human Edge Scorecard',
  subtitle: 'The Human Edge',
  scale: 'five-level practice scale',
  scoring_rule: 'mean',
  // The page's own `rule` string, as its JSON export states it.
  rule_text: 'level = band of the overall average of all answers',
  source: 'Human_Edge_Scorecard/index.html',
  notice_version: r.NOTICE_VERSION,
  extracted_at: new Date().toISOString(),

  rungs: r.RUNGS.map((rung) => ({
    value: rung.v,
    name: rung.name,
    label: rung.label,
    description: rung.desc,
    colour_hex: rung.hex,
    colour_var: rung.c,
  })),
  bands,

  dimensions: r.DIMS.map((d, i) => ({
    position: i + 1,
    code: d.code,
    name: d.name,
    subtitle: d.sub,
    description: d.desc,
    pressing_question: d.ask,
    summary_line: d.line,
    failure_line: d.fail,
    guide: { what: d.guide.what, why: d.guide.why, how: d.guide.how },
    items: d.qs.map((q, qi) => ({
      position: qi + 1,
      label: q.t,
      question: q.q,
      anchor_low_label: q.l1,
      anchor_low_text: q.a1,
      anchor_high_label: q.l5,
      anchor_high_text: q.a5,
      next_step: q.next,
    })),
  })),

  reference: {
    sectors: r.SECTORS,
    organisation_sizes: r.SIZES,
    roles: r.ROLES,
    tenures: r.TENURES,
    guide_tabs: r.GUIDE_TABS.map(([key, label]) => ({ key, label })),
    correlation_metrics: r.METRICS.map((m) => ({ key: m.k, label: m.label })),
    ai_bands: r.AI_BANDS,
    ai_items: r.AI_ITEMS,
  },

  thresholds: {
    correlation_min_n: r.CORR_MIN_N,
    segment_min_n: r.SEG_MIN,
  },

  identity: {
    email_pattern: r.EMAIL_RE,
    blocked_email_domains: r.BLOCKED_EMAIL_DOMAINS,
  },

  synthetic: {
    archetypes: Object.fromEntries(
      Object.entries(r.ARCHETYPES).map(([k, v]) => [
        k,
        { weight: v.w, targets: v.t, emerging: Boolean(v.emerging), note: v.note },
      ]),
    ),
    sector_prior: r.SECTOR_PRIOR,
    role_bias: r.RATER_BIAS,
    org_prefix: r.ORG_A,
    org_suffix: r.ORG_B,
    first_names: r.SYN_FIRST,
    last_names: r.SYN_LAST,
  },
};

writeFileSync(OUT, JSON.stringify(out, null, 2) + '\n', 'utf8');

const items = out.dimensions.reduce((n, d) => n + d.items.length, 0);
console.log(
  `dimensions ${out.dimensions.length}  items ${items}  rungs ${out.rungs.length}` +
    `  sectors ${out.reference.sectors.length}  roles ${out.reference.roles.length}` +
    `  archetypes ${Object.keys(out.synthetic.archetypes).length}`,
);
console.log('band edges', r.BAND_EDGES.join(' / '));
