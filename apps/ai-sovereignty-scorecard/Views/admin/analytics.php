<?php
/**
 * Analytics — the shell: heading, filter bar, tab strip, then one pane.
 *
 * Built to the reference dashboard, which is the design being matched: the same
 * three controls, the same four tabs, the same wording.
 *
 * One deliberate difference. There the tabs and filters were JavaScript state
 * (`F.tab`, `F.year`), so a filtered view could not be linked to, reloaded or
 * opened in a second tab. Here every control is a link and every pane is a URL,
 * which is what a research tool needs — and it means the screen still works with
 * scripting off, apart from the charts.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$fmt = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
$pct = static fn (?float $v): string => $v === null ? '—' : (($v > 0 ? '+' : '') . number_format($v, 2));

/**
 * A dashboard URL with one control changed and the rest kept.
 *
 * `dimension` travels with the rest. It is a control like any other — the
 * Dimensions pane picks one — and leaving it out of the base query would make a
 * dimension chip build its link with no `tab`, which defaults to Overview: the
 * pane would throw you off itself every time you chose a dimension.
 */
$link = static function (array $changes) use ($filters, $tab, $includeSynthetic, $slug, $code): string {
    $query = array_merge([
        'tab'       => $tab,
        'year'      => $filters['year'],
        'sector'    => $filters['sector'],
        'band'      => $filters['band'],
        'dimension' => $code,
        'synthetic' => $includeSynthetic ? '1' : '0',
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
 * The band a score falls in, for the stat cards, the bars and the sector chart.
 *
 * The platform Bander, using the instrument's own cut points — never a
 * comparison against rung VALUES. The edges are 1.80 / 2.60 / 3.40 / 4.20, so
 * 2.9 is Developing; comparing against 1, 2, 3, 4, 5 would call it Emerging and
 * paint the whole dataset a level worse than it is.
 *
 * Returns the Bander's shape with `colour_hex` added, because the views and the
 * chart payload read that key and the rung rows use it.
 */
$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

$meanBand = $bandOf($overview['overall_of_dimensions']);
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Analytics</div>
    <h1 class="title">What the dataset shows</h1>
    <?php
    /*
     * The reference's three sentences, but chosen on whether each is TRUE rather
     * than only on the toggle. Its own test was "synthetic included?" — which,
     * on a dataset holding no synthetic records at all, announces demo data that
     * is not there. That never came up in a build which seeded some on boot; it
     * comes up here on any real deployment before the seeder is run.
     */
    ?>
    <p class="lede">
      <?php if ($includeSynthetic && $counts['synthetic'] > 0 && $counts['real'] === 0): ?>
        Every record on screen is synthetic demo data, generated for demonstration
        and flagged as such. No real organization has scored yet.
      <?php elseif ($includeSynthetic && $counts['synthetic'] > 0): ?>
        Showing real scorecards alongside flagged synthetic demo records.
      <?php else: ?>
        Self-reported scorecards from <?= e($counts['real']) ?>
        respondent<?= $counts['real'] === 1 ? '' : 's' ?>.
      <?php endif; ?>
      Each organization's level follows its overall score, the average of all
      its answers. Its floor, the weakest dimension, is reported alongside and
      labeled as such.
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
    <?= $includeSynthetic ? '&check; ' : '' ?>include synthetic
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
        ? ', or tick &ldquo;include synthetic&rdquo; to explore the demo set.'
        : '.' ?>
  </div>
<?php else: ?>

  <?= $this->partial('admin/_pane-' . $tab, [
      'fmt'              => $fmt,
      'pct'              => $pct,
      'link'             => $link,
      'bandOf'           => $bandOf,
      'meanBand'         => $meanBand,
      'overview'         => $overview,
      'levels'           => $levels,
      'dimensions'       => $dimensions,
      'aggregateFloor'   => $aggregateFloor,
      'sectors'          => $sectors,
      'systems'          => $systems,
      'reading'          => $reading,
      'correlations'     => $correlations,
      'minimumN'         => $minimumN,
      'yearSeries'       => $yearSeries,
      'rescored'         => $rescored,
      'years'            => $years,
      'code'             => $code,
      'detail'           => $detail,
      'rungs'            => $rungs,
      'includeSynthetic' => $includeSynthetic,
      'slug'             => $slug,
  ]) ?>

<?php endif; ?>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<?php /* The PDF buttons' scripts load once for every admin page, from
         admin/_shell-footer.php, since dialogs open over any of them. */ ?>
<?php
/*
 * The figures the charts draw, as data rather than as generated script. Each
 * panel prints the same numbers beside its canvas, so a chart can never show
 * something the page does not also state in words.
 *
 * One payload per pane, and only the keys that pane's canvases need: the drawing
 * script keys off what is present, so a tab with no charts loads no chart
 * library either.
 */
$chartData = [];

if ($overview['n'] > 0) {
    if ($tab === 'stats') {
        $chartData = [
            'levels'     => $levels,
            'dimensions' => $dimensions,
            // Sector bars are coloured by the band each sector's mean falls in,
            // as in the reference, so the rungs travel with the figures.
            'sectors'    => array_map(
                static fn (array $s): array => $s + ['colour' => $bandOf($s['mean'])['colour_hex'] ?? null],
                $sectors
            ),
        ];
    } elseif ($tab === 'yoy' && count($yearSeries) > 1) {
        $chartData = ['years' => $yearSeries];
    } elseif ($tab === 'dims' && $detail !== null) {
        $chartData = [
            'items' => [
                'code'  => $detail['dimension']['code'],
                'rungs' => array_map(
                    static fn (array $r): array => ['name' => $r['name'], 'colour' => $r['colour_hex']],
                    $rungs
                ),
                'rows'  => array_map(
                    static fn (array $i): array => [
                        'label'        => $detail['dimension']['code'] . ' ' . $i['position'],
                        'distribution' => $i['distribution'],
                    ],
                    $detail['items']
                ),
            ],
            'dimSectors' => array_map(
                static fn (array $s): array => $s + ['colour' => $bandOf($s['mean'])['colour_hex'] ?? null],
                $detail['by_sector']
            ),
        ];
    } elseif ($tab === 'corr') {
        // Points and all, below the reporting threshold as well as above it. How
        // thin the data is, is itself the result, and the scatter is the only
        // thing on the pane that says so.
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
  <?php
  /*
   * This app's own charts script, not the platform's.
   *
   * platform/js/dashboard-charts.js belongs to the Director Scorecard: its axes
   * are fixed at 1–4, its radar maxes at 4, and it reads `competencies` and
   * `mean_floor`. Every one of those is wrong here — a 4.60 bar would run off
   * the end of a 1–4 axis — and changing it to suit this instrument would change
   * that one's dashboard at the same time.
   */
  ?>
  <script src="<?= e(app_asset($slug, 'js/dashboard-charts.js')) ?>"></script>
<?php endif; ?>
<?php $this->stop(); ?>
