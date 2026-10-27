/**
 * Dump the AI Executive Readiness Scorecard instrument out of the reference
 * single-file build, verbatim. Nothing is retyped: the reference's own
 * `const DIMS = [...]` is evaluated and serialised, so a transcription error
 * cannot enter the dataset.
 *
 *   node extract-instrument.mjs <output.json>
 *
 * What this instrument has that its siblings do not: every question is answered
 * TWICE, once for the respondent ("self") and once for the organization's
 * leaders ("leadership"), and each of those two ratings has its own written
 * anchors for 1 and for 5. The two short end labels (`l1`, `l5`) are shared.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import vm from 'node:vm';

const SRC = 'C:/xampp/htdocs/RAIX/AI_Executive_Readiness_Scorecard/index.html';
const OUT = process.argv[2];

if (!OUT) {
  console.error('usage: node extract-instrument.mjs <output.json>');
  process.exit(1);
}

const html = readFileSync(SRC, 'utf8');
const lines = html.split(/\r?\n/);

/*
 * The app's own <script> block, found by its banner rather than by line number,
 * so a reference that gains a line above it still extracts. It ends just before
 * the three boot calls (updateNav / initChrome / ensureSeed).
 */
const start = lines.findIndex((l) => l.includes('The AI Executive Readiness Scorecard · v1.0')) - 1;
const end = lines.findIndex((l) => l.startsWith('// ── Boot'));

if (start < 0 || end < 0) {
  console.error('Could not find the reference script block.');
  process.exit(1);
}

const js = lines.slice(start, end).join('\n');

/* A DOM stub wide enough for the declarations to evaluate. Nothing in the
   extracted region touches it at load time, but `esc()` is defined against
   document and `window.SCORECARD` is assigned. */
const element = { textContent: '', innerHTML: '' };
const sandbox = {
  document: {
    createElement: () => ({ ...element }),
    getElementById: () => null,
    querySelectorAll: () => [],
    querySelector: () => null,
    body: { classList: { toggle() {} } },
  },
  window: { matchMedia: () => ({ matches: false }) },
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
    ' GUIDE_TABS, CORR_MIN_N, METRICS, ARCHETYPES, SECTOR_PRIOR, RATER_BIAS,' +
    ' ORG_A, ORG_B, SYN_FIRST, SYN_LAST, EMAIL_RE: EMAIL_RE.source,' +
    ' BLOCKED_EMAIL_DOMAINS, SEED_VERSION, SCOPES, SCOPE_LABEL, GROUP_LABEL, SCOPE_COLOR,' +
    ' INSTRUCTIONS, COMPARISON_NOTE, DISCLAIMER};',
  context,
  { filename: 'executive-readiness-reference.js' },
);

const r = sandbox.__out;

/* Band edges 1.80 / 2.60 / 3.40 / 4.20 on a 1–5 scale become the five rungs'
   own [min, max) cut points, which is how the platform's Bander reads them. */
const edges = [null, ...r.BAND_EDGES, null];
const bands = r.RUNGS.map((rung, i) => ({
  rung_value: rung.v,
  min: edges[i],
  max: edges[i + 1],
}));

const out = {
  slug: 'ai-executive-readiness-scorecard',
  version: '1.0',
  name: 'AI Executive Readiness — The Enterprise Scorecard',
  subtitle: 'The AI Executive Readiness Scorecard',
  scale: '5-level scale',
  scoring_rule: 'paired-mean',
  // The reference's codebook, condensed: each scope is scored on its own.
  rule_text:
    'each scope is scored separately; level = band of the scope overall mean of 12 ratings; floor = lowest dimension, reported; difference = self minus leadership',
  source: 'AI_Executive_Readiness_Scorecard/index.html',
  extracted_at: new Date().toISOString(),

  scopes: r.SCOPES.map((key) => ({
    key,
    label: r.SCOPE_LABEL[key],
    group_label: r.GROUP_LABEL[key],
    colour: r.SCOPE_COLOR[key],
  })),

  text: {
    instructions: r.INSTRUCTIONS,
    comparison_note: r.COMPARISON_NOTE,
    disclaimer: r.DISCLAIMER,
  },

  rungs: r.RUNGS.map((rung) => ({
    value: rung.v,
    name: rung.name,
    description: rung.desc,
    colour_hex: rung.hex,
    colour_var: rung.c,
  })),
  bands,

  dimensions: r.DIMS.map((d, i) => ({
    position: i + 1,
    code: d.code,
    name: d.name,
    description: d.desc,
    summary_line: d.line,
    guide: { what: d.guide.what, why: d.guide.why, how: d.guide.how },
    items: d.qs.map((q, qi) => ({
      position: qi + 1,
      label: q.t,
      question: q.q,
      anchor_low_label: q.l1,
      anchor_high_label: q.l5,
      anchors: {
        self: { low: q.anchors.self.a1, high: q.anchors.self.a5 },
        leadership: { low: q.anchors.leadership.a1, high: q.anchors.leadership.a5 },
      },
    })),
  })),

  reference: {
    sectors: r.SECTORS,
    organisation_sizes: r.SIZES,
    roles: r.ROLES,
    tenures: r.TENURES,
    guide_tabs: r.GUIDE_TABS.map(([key, label]) => ({ key, label })),
    correlation_metrics: r.METRICS.map((m) => ({ key: m.k, label: m.label })),
  },

  thresholds: {
    correlation_min_n: r.CORR_MIN_N,
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
  `dimensions ${out.dimensions.length}  items ${items}  scopes ${out.scopes.length}  rungs ${out.rungs.length}` +
    `  sectors ${out.reference.sectors.length}  roles ${out.reference.roles.length}` +
    `  archetypes ${Object.keys(out.synthetic.archetypes).length}`,
);
console.log('band edges', r.BAND_EDGES.join(' / '));
