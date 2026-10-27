<?php
/**
 * Analytics — the reference's renderAnalytics() and its five panes: Overview,
 * Year-over-year, Dimensions, Correlation and Comparison.
 *
 * Every control is a link and every pane is a URL, so a filtered view can be
 * linked to and reloaded. The wording is the reference's.
 *
 * @var \Platform\Core\View $this
 * @var array<string, mixed> $f the filters (the reference's `F`)
 * @var list<array<string, mixed>> $recs the filtered records
 */

use Apps\AiExecutiveReadinessScorecard\Services\Scopes;
use Apps\AiExecutiveReadinessScorecard\Services\Stats;

$this->extend('layouts/admin');

/** An Analytics URL with some controls changed and the rest kept. */
$link = static function (array $changes) use ($f, $slug): string {
    $query = array_merge([
        'tab'       => $f['tab'],
        'year'      => $f['year'],
        'sector'    => $f['sector'],
        'band'      => $f['band'],
        'scope'     => $f['scope'],
        'dim'       => $f['dim'],
        'synthetic' => $f['synthetic'] ? '1' : '0',
    ], $changes);

    $query = array_filter($query, static fn (string $v, string $k): bool => !(
        in_array($k, ['year', 'sector', 'band', 'dim'], true) && $v === 'all'
    ) && !($k === 'scope' && $v === 'self'), ARRAY_FILTER_USE_BOTH);

    return url('admin/' . $slug . '/analytics') . '?' . http_build_query($query);
};

$chart = static fn (array $spec): string => e(json_encode($spec, JSON_UNESCAPED_UNICODE));
$codes = array_column($dimensions, 'code');

$filterQuery = http_build_query([
    'source' => 'analytics', 'year' => $f['year'], 'sector' => $f['sector'], 'band' => $f['band'],
    'scope' => $f['scope'], 'synthetic' => $f['synthetic'] ? '1' : '0',
]);
?>

<?php $this->start('content'); ?>
<div class="eyebrow">Analytics</div>
<h1 class="title">What the dataset shows</h1>
<p class="lede">Each respondent assesses both themselves and their organization&rsquo;s leaders in one questionnaire. <?=
  $f['synthetic'] ? 'Synthetic demonstration records are included and do not describe real leaders.' : 'Only real submissions are included.'
?></p>

<div class="filterbar">
  <label><span class="fl">Year</span>
    <select onchange="location.href=this.value">
      <option value="<?= e($link(['year' => 'all'])) ?>">All</option>
      <?php foreach ($options['years'] as $year): ?>
        <option value="<?= e($link(['year' => (string) $year])) ?>"<?= (string) $year === $f['year'] ? ' selected' : '' ?>><?= e($year) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label><span class="fl">Sector</span>
    <select onchange="location.href=this.value">
      <option value="<?= e($link(['sector' => 'all'])) ?>">All</option>
      <?php foreach ($options['sectors'] as $sector): ?>
        <option value="<?= e($link(['sector' => $sector])) ?>"<?= $sector === $f['sector'] ? ' selected' : '' ?>><?= e($sector) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label><span class="fl">Rating set</span>
    <select onchange="location.href=this.value">
      <?php foreach (Scopes::all() as $scope): ?>
        <option value="<?= e($link(['scope' => $scope['key']])) ?>"<?= $f['scope'] === $scope['key'] ? ' selected' : '' ?>><?= e($scope['set_label']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label><span class="fl">Level</span>
    <select onchange="location.href=this.value">
      <option value="<?= e($link(['band' => 'all'])) ?>">All</option>
      <?php foreach ($rungs as $rung): ?>
        <option value="<?= e($link(['band' => $rung['name']])) ?>"<?= $f['band'] === $rung['name'] ? ' selected' : '' ?>><?= e($rung['name']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <a class="chip <?= $f['synthetic'] ? 'on' : '' ?>" href="<?= e($link(['synthetic' => $f['synthetic'] ? '0' : '1'])) ?>"><?= $f['synthetic'] ? '&check; ' : '' ?>Include synthetic</a>
  <a class="chip" href="<?= e($link(['year' => 'all', 'sector' => 'all', 'band' => 'all'])) ?>">Clear filters</a>
  <button class="chip" type="button" data-report-pdf="<?= e(url('admin/' . $slug . '/report/data') . '?' . $filterQuery) ?>"
          data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">Download filtered PDF</button>
</div>
<p class="small muted">The rating set controls the level filter and the correlation view. Other views show both sets for the same filtered questionnaires.</p>

<div class="dash-tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $f['tab'] === $key ? 'on' : '' ?>" href="<?= e($link(['tab' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div id="anPane">
<?php if ($recs === []): ?>
  <div class="empty">No records match these filters.</div>

<?php elseif ($f['tab'] === 'stats'): ?>
  <?php $o = $overview; ?>
  <p class="muted"><?= e($o['questionnaires']) ?> questionnaires · <?= e($o['respondents']) ?> respondents · <?= e($o['organisations']) ?> organizations</p>
  <?= $this->partial('shared/_score-cards', ['scores' => $o['scores'], 'bander' => $bander, 'group' => true]) ?>
  <p class="small muted">Each questionnaire contributes once to each mean. Repeated assessments contribute separately. A mean of respondents&rsquo; self-ratings does not measure the leadership team collectively.</p>
  <div class="grid2" style="margin:24px 0">
    <div class="chart-card"><h4>Levels in each rating set</h4><div style="height:250px"><canvas id="cLevels" data-chart="<?= $chart([
        'kind' => 'plot', 'type' => 'bar', 'labels' => array_column($rungs, 'name'),
        'series' => [['scope' => 'self', 'data' => $o['level_counts']['self']], ['scope' => 'leadership', 'data' => $o['level_counts']['leadership']]],
    ]) ?>"></canvas></div></div>
    <div class="chart-card"><h4>Mean scores across four dimensions</h4><canvas id="cRadarAn" height="240" data-chart="<?= $chart([
        'kind' => 'radar', 'labels' => $codes,
        'sets' => [
            ['label' => 'Self-ratings', 'data' => array_map(static fn ($c) => $o['scores']['self']['dims'][$c], $codes)],
            ['label' => 'Ratings of leaders', 'data' => array_map(static fn ($c) => $o['scores']['leadership']['dims'][$c], $codes)],
        ],
    ]) ?>"></canvas></div>
  </div>
  <?= $this->partial('admin/_table', ['headers' => ['Level', 'Self-ratings', 'Ratings of leaders'], 'rows' => $o['level_rows']]) ?>
  <?= $this->partial('shared/_paired-bars', ['scores' => $o['scores'], 'dimensions' => $dimensions, 'bander' => $bander, 'spread' => $o['spread']]) ?>
  <hr class="rule">
  <h3 class="sub">By sector</h3>
  <?= $this->partial('admin/_table', ['headers' => ['Sector', 'Questionnaires', 'Self mean', 'Leaders mean'], 'rows' => $o['by_sector']]) ?>
  <h3 class="sub" style="margin-top:24px">Lowest-scoring dimensions</h3>
  <?= $this->partial('admin/_table', ['headers' => ['Dimension', 'Self-ratings', 'Ratings of leaders'], 'rows' => $o['floors']]) ?>
  <p class="small muted">Ties are counted under the first tied dimension in questionnaire order.</p>
  <h3 class="sub" style="margin-top:24px">Organizations</h3>
  <div id="orgLinks">
    <?php foreach ($o['names'] as $name): ?>
      <?php $orgUrl = url('admin/' . $slug . '/analytics/organisation') . '?' . http_build_query(['name' => $name, 'source' => 'analytics', 'year' => $f['year'], 'sector' => $f['sector'], 'band' => $f['band'], 'scope' => $f['scope'], 'synthetic' => $f['synthetic'] ? '1' : '0']); ?>
      <a class="btn quiet sm org-link" style="margin:4px" href="<?= e($orgUrl) ?>" data-modal-src="<?= e($orgUrl . '&fragment=1') ?>" data-modal-wide><?= e($name) ?></a>
    <?php endforeach; ?>
  </div>

<?php elseif ($f['tab'] === 'yoy'): ?>
  <p class="muted">Annual means can change because different people responded. The table below separates those means from changes reported by the same respondents.</p>
  <div class="chart-card"><div style="height:280px"><canvas id="cYoY" data-chart="<?= $chart([
      'kind' => 'plot', 'type' => 'line', 'labels' => $yoy['years'], 'scoreScale' => true,
      'series' => [['scope' => 'self', 'data' => $yoy['series']['self']], ['scope' => 'leadership', 'data' => $yoy['series']['leadership']]],
  ]) ?>"></canvas></div></div>
  <?= $this->partial('admin/_table', ['headers' => ['Year', 'Questionnaires', 'Respondents', 'Organizations', 'Self mean', 'Leaders mean'], 'rows' => $yoy['rows']]) ?>
  <h3 class="sub" style="margin-top:24px">Changes among the same respondents</h3>
  <p class="small muted">Respondents are matched by organization and email address. If someone submitted more than once in a year, this comparison uses their latest submission. A matched change still reflects their own perceptions.</p>
  <?php if ($yoy['matched'] !== []): ?>
    <?= $this->partial('admin/_table', ['headers' => ['Period', 'Matched respondents', 'Change in self mean', 'Change in leaders mean'], 'rows' => $yoy['matched']]) ?>
  <?php else: ?>
    <p class="muted">At least two years are needed for this comparison.</p>
  <?php endif; ?>

<?php elseif ($f['tab'] === 'dims'): ?>
  <div class="toolbar"><label class="small muted">Dimension <select class="f" onchange="location.href=this.value">
    <option value="<?= e($link(['dim' => 'all'])) ?>">All dimensions</option>
    <?php foreach ($dimensions as $d): ?>
      <option value="<?= e($link(['dim' => $d['code']])) ?>"<?= $f['dim'] === $d['code'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
    <?php endforeach; ?>
  </select></label></div>
  <?php foreach ($detail as $d): ?>
    <h3 class="sub"><?= e($d['code']) ?> · <?= e($d['name']) ?></h3>
    <?php foreach ($d['items'] as $item): ?>
      <div class="qrow paired-qrow"><p><b><?= e($item['number']) ?></b> <?= e($item['question']) ?></p>
        <?= $this->partial('admin/_table', ['headers' => ['Rating set', 'n', 'Mean', 'SD', '1', '2', '3', '4', '5'], 'rows' => $item['rows']]) ?>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <p class="small muted">Each distribution contains one rating per questionnaire. Repeated assessments contribute separately.</p>

<?php elseif ($f['tab'] === 'corr'): ?>
  <div class="warnbox">These are associations in self-reported data from a self-selected sample. They do not establish causation. This view uses each respondent&rsquo;s latest assessment within the filters. Respondents from the same organization may still be related, so the 95% intervals are descriptive and do not adjust for that dependence. <?= $f['synthetic'] ? 'Synthetic data is included. These patterns are for demonstration only.' : '' ?></div>
  <h3 class="sub" style="margin-top:20px"><?= $f['scope'] === 'self' ? 'Self-ratings' : 'Ratings of the organization&rsquo;s leaders' ?></h3>
  <?php foreach ($corr as $row): ?>
    <div class="chart-card" style="margin:18px 0">
      <h4>Overall score vs <?= e($row['label']) ?></h4>
      <p class="small muted"><?= e(count($row['pairs'])) ?> respondents with both values.</p>
      <?php if (count($row['pairs']) < $row['minimum']): ?>
        <p class="small muted">Coefficient withheld. n = <?= e(count($row['pairs'])) ?>, below the reporting threshold of <?= e($row['minimum']) ?>.</p>
      <?php elseif ($row['stat'] !== null): ?>
        <p>r = <?= e(Stats::fmt($row['stat']['r'])) ?> · 95% CI <?= e(Stats::fmt($row['stat']['lo'])) ?> to <?= e(Stats::fmt($row['stat']['hi'])) ?></p>
      <?php else: ?>
        <p class="small muted">The coefficient is undefined because at least one measure has no variation.</p>
      <?php endif; ?>
      <div style="height:220px"><canvas id="cCorr-<?= e($row['key']) ?>" data-chart="<?= $chart([
          'kind' => 'scatter', 'label' => $row['label'], 'points' => $row['pairs'], 'scope' => $f['scope'],
          'xTitle' => $f['scope'] === 'self' ? 'Self overall score' : 'Leaders overall score',
      ]) ?>"></canvas></div>
    </div>
  <?php endforeach; ?>

<?php else: ?>
  <p class="muted">Each difference is a respondent&rsquo;s self-rating minus their rating of the organization&rsquo;s leaders. Positive and negative differences can cancel in an average. The counts below show how often respondents rated themselves higher, the same, or lower. They do not establish who performs better.</p>
  <?= $this->partial('shared/_score-cards', ['scores' => $compare['scores'], 'bander' => $bander, 'group' => true]) ?>
  <?= $this->partial('admin/_table', [
      'headers' => ['Measure', 'n', 'Mean difference', 'SD', 'Range', 'Self higher', 'Equal', 'Leaders higher'],
      'rows'    => array_map(static fn (array $r): array => [
          $r['label'], $r['n'], Stats::delta($r['mean']), Stats::fmt($r['sd']),
          Stats::delta($r['min']) . ' to ' . Stats::delta($r['max']),
          Stats::share($r['higher'], $r['n']), Stats::share($r['equal'], $r['n']), Stats::share($r['lower'], $r['n']),
      ], $compare['rows']),
  ]) ?>
<?php endif; ?>
</div>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script src="<?= e(app_asset($slug, 'vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(app_asset($slug, 'js/analytics-charts.js')) ?>"></script>
<?php $this->stop(); ?>
