<?php
/**
 * Analytics — the shell: heading, filter bar, tab strip, then one pane.
 *
 * Built to the single-file build's dashboard, which is the design being
 * matched: the same three controls, the same four tabs, the same wording.
 *
 * One deliberate difference. There the tabs and filters were JavaScript state
 * (`F.tab`, `F.year`), so a filtered view could not be linked to, reloaded, or
 * opened in a second tab. Here every control is a link and every pane is a URL,
 * which is what a research tool needs — and it means the screen still works
 * with scripting off, apart from the charts.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$fmt = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
$pct = static fn (?float $v): string => $v === null ? '—' : (($v > 0 ? '+' : '') . number_format($v, 2));

/**
 * A dashboard URL with one control changed and the rest kept.
 *
 * `competency` travels with the rest. It is a control like any other — the
 * Competencies pane picks one — and leaving it out of the base query meant a
 * competency chip built its link with no `tab`, which defaults to Overview: the
 * pane threw you off itself every time you chose a competency.
 */
$link = static function (array $changes) use ($filters, $tab, $includeSynthetic, $slug, $competency): string {
    $query = array_merge([
        'tab'        => $tab,
        'year'       => $filters['year'],
        'sector'     => $filters['sector'],
        'band'       => $filters['band'],
        'competency' => $competency,
        'synthetic'  => $includeSynthetic ? '1' : '0',
    ], $changes);

    // 'all' is the default for the three filters; leaving it out keeps the URL
    // readable, and the controller reads a missing value the same way.
    $query = array_filter(
        $query,
        static fn (string $v, string $k): bool => $v !== ''
            && !(in_array($k, ['year', 'sector', 'band'], true) && $v === 'all'),
        ARRAY_FILTER_USE_BOTH
    );

    return url('admin/' . $slug . '/analytics') . '?' . http_build_query($query);
};

/*
 * The band a score falls in, for the stat card's colour and wording, the
 * competency bars and the sector chart.
 *
 * The platform Bander, not a comparison against rung VALUES: the cut points are
 * 1.75 / 2.75 / 3.75, so 1.80 is Aware. Comparing against 1, 2, 3, 4 called it
 * Unaware and painted it red.
 *
 * Returns the Bander's shape with `colour_hex` added, because the views and the
 * chart payload read that key and the rung rows use it.
 */
$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

$floorBand = $bandOf($overview['mean_floor']);
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Analytics</div>
    <h1 class="title">What the dataset shows</h1>
    <?php
    /*
     * The reference's three sentences, verbatim, but chosen on whether each is
     * TRUE rather than only on the toggle. Its own test was "synthetic
     * included?" — which, on a dataset holding no synthetic records at all,
     * announces demo data that is not there. That never came up in a build
     * which always seeded some; it comes up here on any real deployment before
     * the seeder is run.
     *
     * The apostrophe below is the reference's own straight one. That build is
     * inconsistent about it — 11 straight to 3 curly — and this is one of the
     * straight ones; the "Set by each director’s weakest competency" caption
     * further down is one of the curly ones, and is curly here too.
     */
    ?>
    <p class="lede">
      <?php if ($includeSynthetic && $counts['synthetic'] > 0 && $counts['real'] === 0): ?>
        Every record on screen is synthetic demo data, generated for demonstration
        and flagged as such. No real director has scored yet.
      <?php elseif ($includeSynthetic && $counts['synthetic'] > 0): ?>
        Showing real scorecards alongside flagged synthetic demo records.
      <?php else: ?>
        Self-reported scorecards from <?= e($counts['real']) ?>
        director<?= $counts['real'] === 1 ? '' : 's' ?>.
      <?php endif; ?>
      Every view respects the floor rule: a director's level is their weakest
      competency.
    </p>
  </div>
</div>

<?php /* Year · Sector · Level · include synthetic · clear — as in the reference. */ ?>
<div class="filterbar">
  <span class="fl">Year</span>
  <select onchange="location.href=this.value">
    <option value="<?= e($link(['year' => 'all'])) ?>"<?= $filters['year'] === 'all' ? ' selected' : '' ?>>All</option>
    <?php foreach ($filterOptions['years'] as $year): ?>
      <option value="<?= e($link(['year' => (string) $year])) ?>"
              <?= $filters['year'] === (string) $year ? ' selected' : '' ?>><?= e($year) ?></option>
    <?php endforeach; ?>
  </select>

  <span class="fl">Sector</span>
  <select onchange="location.href=this.value">
    <option value="<?= e($link(['sector' => 'all'])) ?>"<?= $filters['sector'] === 'all' ? ' selected' : '' ?>>All</option>
    <?php foreach ($filterOptions['sectors'] as $sector): ?>
      <option value="<?= e($link(['sector' => $sector])) ?>"
              <?= $filters['sector'] === $sector ? ' selected' : '' ?>><?= e($sector) ?></option>
    <?php endforeach; ?>
  </select>

  <span class="fl">Level</span>
  <select onchange="location.href=this.value">
    <option value="<?= e($link(['band' => 'all'])) ?>"<?= $filters['band'] === 'all' ? ' selected' : '' ?>>All</option>
    <?php foreach ($rungs as $rung): ?>
      <option value="<?= e($link(['band' => (string) $rung['value']])) ?>"
              <?= $filters['band'] === (string) $rung['value'] ? ' selected' : '' ?>>
        Level <?= e($rung['value']) ?>, <?= e(strtolower((string) $rung['name'])) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <a class="chip<?= $includeSynthetic ? ' on' : '' ?>"
     href="<?= e($link(['synthetic' => $includeSynthetic ? '0' : '1'])) ?>">
    <?= $includeSynthetic ? '✓ ' : '' ?>include synthetic
  </a>
  <a class="chip" href="<?= e($link(['year' => 'all', 'sector' => 'all', 'band' => 'all'])) ?>">clear</a>
</div>

<div class="dash-tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>" href="<?= e($link(['tab' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($overview['n'] === 0): ?>
  <div class="empty">
    <b>No records match</b>
    Widen the filters<?= $counts['synthetic'] > 0 && !$includeSynthetic
        ? ', or tick “include synthetic” to explore the demo set.'
        : '.' ?>
    <?php if ($counts['in_progress'] > 0): ?>
      <br><?= e($counts['in_progress']) ?> scorecard<?= $counts['in_progress'] === 1 ? ' is' : 's are' ?> in progress.
    <?php endif; ?>
  </div>
<?php else: ?>

  <?= $this->partial('admin/_pane-' . $tab, [
      'fmt'              => $fmt,
      'pct'              => $pct,
      'link'             => $link,
      'floorBand'        => $floorBand,
      'bandOf'           => $bandOf,
      'systems'          => $systems,
      'insight'          => $insight,
      'overview'         => $overview,
      'levels'           => $levels,
      'competencies'     => $competencies,
      'sectors'          => $sectors,
      'correlations'     => $correlations,
      'corrInsight'      => $corrInsight,
      'years'            => $years,
      'boardYears'       => $boardYears,
      'competency'       => $competency,
      'dimSectors'       => $dimSectors,
      'items'            => $items,
      'rungs'            => $rungs,
      'includeSynthetic' => $includeSynthetic,
      'slug'             => $slug,
  ]) ?>

<?php endif; ?>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<?php /* The board dialog opens over this screen, and its PDF button is drawn
         here. jsPDF itself is fetched only when that button is pressed. */ ?>
<script src="<?= e(app_asset($slug, 'js/report-pdf.js')) ?>"></script>
<?php
/*
 * The figures the charts draw, as data rather than as generated script. Each
 * panel prints the same numbers beside its canvas, so a chart can never show
 * something the page does not also state in words.
 *
 * One payload per pane, and only the keys that pane's canvases need: the
 * drawing script keys off what is present, so a tab with no charts loads no
 * chart library either.
 */
$chartData = [];

if ($overview['n'] > 0) {
    if ($tab === 'stats') {
        $chartData = [
            'levels'       => $levels,
            'competencies' => $competencies,
            // Sector bars are coloured by the band each sector's mean floor falls
            // in, as in the reference, so the rungs travel with the figures.
            'sectors'      => array_map(
                static fn (array $s): array => $s + ['colour' => $bandOf($s['mean_floor'])['colour_hex'] ?? null],
                $sectors
            ),
        ];
    } elseif ($tab === 'yoy' && count($years) > 1) {
        $chartData = ['years' => $years];
    } elseif ($tab === 'dims' && $items !== null) {
        $chartData = [
            'items' => [
                'code'  => $items['competency']['code'],
                'rungs' => array_map(
                    static fn (array $r): array => ['name' => $r['name'], 'colour' => $r['colour_hex']],
                    $rungs
                ),
                'rows'  => array_map(
                    static fn (array $i): array => [
                        'label'        => $items['competency']['code'] . ' ' . $i['position'],
                        'distribution' => $i['distribution'],
                    ],
                    $items['items']
                ),
            ],
            'dimSectors' => array_map(
                static fn (array $s): array => $s + ['colour' => $bandOf($s['mean'])['colour_hex'] ?? null],
                $dimSectors
            ),
        ];
    } elseif ($tab === 'corr') {
        // Points and all, below the reporting threshold as well as above it.
        // How thin the data is, is itself the result, and the scatter is the
        // only thing on the pane that says so.
        $chartData = ['correlations' => $correlations];
    }
}
?>
<?php if ($chartData !== []): ?>
  <script src="<?= e(app_asset($slug, 'vendor/chart.umd.min.js')) ?>"></script>
  <script type="application/json" id="chartData"><?= json_encode(
      $chartData,
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?></script>
  <script src="<?= e(asset('platform/js/dashboard-charts.js')) ?>"></script>
<?php endif; ?>
<?php $this->stop(); ?>
