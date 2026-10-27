<?php
/**
 * Analytics — the page's renderAnalytics() and its six panes: Index, Skills,
 * Practices, Segments, AI use and Data quality.
 *
 * Every control is a link and every pane is a URL, so a filtered view can be
 * linked to and reloaded. The wording is the page's, sentence for sentence.
 *
 * @var \Platform\Core\View $this
 * @var array<string, mixed> $f the filters (the page's `F`)
 * @var list<array<string, mixed>> $recs the filtered records
 * @var \Apps\HumanEdgeScorecard\Services\Stats $stats
 * @var \Apps\HumanEdgeScorecard\Services\Analytics $analytics
 */

use Apps\HumanEdgeScorecard\Services\Analytics;
use Apps\HumanEdgeScorecard\Services\Html;
use Apps\HumanEdgeScorecard\Services\Stats;

$this->extend('layouts/admin');

/** An Analytics URL with some controls changed and the rest kept. */
$link = static function (array $changes) use ($f, $slug): string {
    $query = array_merge([
        'tab'       => $f['tab'],
        'sector'    => $f['sector'],
        'synthetic' => $f['synthetic'] ? '1' : '0',
        'dim'       => $f['dim'],
        'seg'       => $f['seg'],
    ], $changes);

    return url('admin/' . $slug . '/analytics') . '?' . http_build_query(array_filter(
        $query,
        static fn (string $v, string $k): bool => !($k === 'sector' && $v === 'all'),
        ARRAY_FILTER_USE_BOTH
    ));
};

$first = $rungs[0]['name'];
$last  = $rungs[count($rungs) - 1]['name'];
$real  = (int) $counts['real'];
$f2    = [Stats::class, 'f2'];
$pct   = [Stats::class, 'pct'];
?>

<?php $this->start('content'); ?>
<div class="eyebrow">Analytics</div>
<h2 class="title">What the dataset shows</h2>
<p class="lede"><?=
  $f['synthetic']
    ? ($real > 0 ? 'Showing real scorecards alongside flagged synthetic demo records.' : 'Every record on screen is synthetic demo data, generated for demonstration and flagged as such. No real organization has scored yet.')
    : e('Self-reported scorecards from ' . $real . ' respondent' . ($real === 1 ? '' : 's') . '.')
?> A respondent's level is the band their score falls in, from <?= e($first) ?> to <?= e($last) ?>.</p>

<div class="filterbar">
  <span class="fl">Sector</span>
  <select onchange="location.href=this.value" aria-label="Sector">
    <option value="<?= e($link(['sector' => 'all'])) ?>">All</option>
    <?php foreach ($sectors as $sector): ?>
      <option value="<?= e($link(['sector' => $sector])) ?>"<?= $f['sector'] === $sector ? ' selected' : '' ?>><?= e($sector) ?></option>
    <?php endforeach; ?>
  </select>
  <a class="chip<?= $f['synthetic'] ? ' on' : '' ?>" href="<?= e($link(['synthetic' => $f['synthetic'] ? '0' : '1'])) ?>"><?= $f['synthetic'] ? '✓ ' : '' ?>include synthetic</a>
  <a class="chip" href="<?= e($link(['sector' => 'all'])) ?>">clear</a>
</div>

<div class="dash-tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $f['tab'] === $key ? 'on' : '' ?>" href="<?= e($link(['tab' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div id="anPane">
<?php if ($recs === []): ?>
  <div class="empty"><b>No records match</b>Widen the filters, or tick &ldquo;include synthetic&rdquo; to explore the demo set.</div>

<?php elseif ($f['tab'] === 'index'): ?>
  <?php $o = $index['overall']; ?>
  <div class="grid3" style="margin-bottom:22px">
    <div class="stat"><div class="v"><?= e(count($recs)) ?></div><div class="k">Respondents in the filtered set</div></div>
    <div class="stat"><div class="v"><?= e($f2($o['median'])) ?></div><div class="k">Median overall score (interquartile range <?= e($f2($o['q1'])) ?> to <?= e($f2($o['q3'])) ?>)</div></div>
    <div class="stat"><div class="v"><?= e($f2($o['mean'])) ?></div><div class="k">Mean overall score (95% CI <?= e($f2($o['lo'])) ?> to <?= e($f2($o['hi'])) ?>)</div></div>
  </div>
  <h3 class="sub">The index</h3>
  <p class="muted" style="margin-bottom:12px">The share of respondents at each level, overall and on each skill. A respondent&rsquo;s level on a skill is the band that skill&rsquo;s score falls in. The overall level uses the average of all eighteen answers.</p>
  <?= Html::levelKey($stats) ?>
  <div class="tscroll"><table class="an-table"><thead><tr><th>Skill</th><th class="r">n</th><?php foreach ($rungs as $r): ?><th class="r"><?= e($r['name']) ?></th><?php endforeach; ?><th class="r">Median</th><th class="r">IQR</th><th class="r">Mean (95% CI)</th><th style="min-width:150px">Distribution</th></tr></thead><tbody>
  <?php foreach ($index['rows'] as $ri => $row): $sh = $row['shares']; $d = $row['d']; ?>
    <tr<?= $ri === 0 ? ' class="tot"' : '' ?>><td><b><?= e($row['name']) ?></b><div class="small muted"><?= e($row['sub']) ?></div></td><td class="r"><?= e($sh['n']) ?></td><?php foreach ($sh['shares'] as $p): ?><td class="r"><?= e($pct($p)) ?></td><?php endforeach; ?><td class="r"><?= e($f2($d['median'])) ?></td><td class="r"><?= e($f2($d['q1'])) ?>–<?= e($f2($d['q3'])) ?></td><td class="r"><?= Stats::meanCiHtml($d) ?></td><td><?= Html::shareBar($stats, $sh) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($f['tab'] === 'skill'): ?>
  <?php $d = $skill['dim']; $ds = $skill['d']; $sh = $skill['sh']; ?>
  <div class="chips" style="margin-bottom:20px">
    <?php foreach ($dims as $x): ?>
      <a class="chip<?= $x['code'] === $d['code'] ? ' on' : '' ?>" href="<?= e($link(['dim' => $x['code']])) ?>"><?= e($x['name']) ?></a>
    <?php endforeach; ?>
  </div>
  <h3 class="sub"><?= e($d['name']) ?> <span class="muted" style="font-weight:400">· <?= e($d['sub']) ?></span></h3>
  <div class="grid3" style="margin:12px 0 22px">
    <div class="stat"><div class="v"><?= e($f2($ds['median'])) ?></div><div class="k">Median (interquartile range <?= e($f2($ds['q1'])) ?> to <?= e($f2($ds['q3'])) ?>), n = <?= e($ds['n']) ?></div></div>
    <div class="stat"><div class="v"><?= e($f2($ds['mean'])) ?></div><div class="k">Mean (95% CI <?= e($f2($ds['lo'])) ?> to <?= e($f2($ds['hi'])) ?>)</div></div>
    <div class="stat"><div class="v"><?= e($pct($sh['n'] ? ($sh['counts'][0] + $sh['counts'][1]) / $sh['n'] : null)) ?></div><div class="k">At <?= e($rungs[0]['name']) ?> or <?= e($rungs[1]['name']) ?></div></div>
  </div>
  <?= Html::levelKey($stats) ?><?= Html::shareBar($stats, $sh) ?>
  <h3 class="sub" style="margin-top:26px">Distribution of scores</h3>
  <p class="muted" style="margin-bottom:10px">Each respondent&rsquo;s <?= e(mb_strtolower($d['name'])) ?> score is the average of three answers, so it can take thirteen values between 1 and 5. Bars are colored by level.</p>
  <div class="hist">
    <?php foreach ($skill['hist'] as $h): $b = $stats->band($h['x']); ?>
      <div class="hcol"><div class="hn"><?= $h['n'] ? e($h['n']) : '' ?></div><div class="hbar" style="height:<?= e(Stats::jsRound($h['n'] / $skill['top'] * 140)) ?>px;background:<?= e($b['hex'] ?? 'var(--stone)') ?>"></div><div class="hx"><?= e(Analytics::histLabel($h['x'])) ?></div></div>
    <?php endforeach; ?>
  </div>
  <h3 class="sub" style="margin-top:26px">The three practices, lowest mean first</h3>
  <div class="tscroll"><table class="an-table"><thead><tr><th>Practice</th><th class="r">n</th><th class="r">Mean (95% CI)</th><th class="r">Median</th><?php foreach ([1, 2, 3, 4, 5] as $k): ?><th class="r"><?= $k ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($skill['items'] as $it): ?>
    <tr><td><b><?= e($it['q']['t']) ?></b><div class="small muted"><?= e($it['q']['q']) ?></div></td><td class="r"><?= e($it['n']) ?></td><td class="r"><?= Stats::meanCiHtml($it['st']) ?></td><td class="r"><?= e($f2($it['st']['median'])) ?></td><?php foreach ($it['dist'] as $c): ?><td class="r"><?= e($it['n'] ? $pct($c / $it['n']) : '—') ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p class="small muted" style="margin-top:8px">Columns 1 to 5 give the share of respondents choosing each answer.</p>

<?php elseif ($f['tab'] === 'practices'): ?>
  <p class="muted" style="margin-bottom:12px">All eighteen practices, ranked by mean with the lowest first. Medians are shown, but on a five-point answer they tie too often to rank by. &ldquo;Next step&rdquo; is the share of respondents for whom the practice is one of the four suggestions on their results page.</p>
  <div class="tscroll"><table class="an-table"><thead><tr><th class="r">#</th><th>Practice</th><th>Skill</th><th class="r">n</th><th class="r">Mean (95% CI)</th><th class="r">Median</th><th class="r">Answered 1 or 2</th><th class="r">Answered 5</th><th class="r">Next step</th></tr></thead><tbody>
  <?php foreach ($practices as $k => $r): ?>
    <tr><td class="r"><?= e($k + 1) ?></td><td><b><?= e($r['q']['t']) ?></b></td><td><?= e($r['d']['name']) ?></td><td class="r"><?= e($r['st']['n']) ?></td><td class="r"><?= Stats::meanCiHtml($r['st']) ?></td><td class="r"><?= e($f2($r['st']['median'])) ?></td><td class="r"><?= e($pct($r['low'])) ?></td><td class="r"><?= e($pct($r['top'])) ?></td><td class="r"><?= e($pct($r['next'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($f['tab'] === 'segments'): ?>
  <div class="chips" style="margin-bottom:16px">
    <?php foreach ($analytics->segments() as $key => [$label]): ?>
      <a class="chip<?= $key === $segments['key'] ? ' on' : '' ?>" href="<?= e($link(['seg' => $key])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="margin-bottom:12px">Medians by <?= e(mb_strtolower($segments['label'])) ?>. Groups with fewer than <?= e($analytics->segMinN()) ?> respondents are not shown, because a median from so few people says little. The overall score also has its mean with a 95% confidence interval.</p>
  <div class="tscroll"><table class="an-table"><thead><tr><th><?= e($segments['label']) ?></th><th class="r">n</th><th class="r">Overall median</th><th class="r">Overall mean (95% CI)</th><?php foreach ($dims as $d): ?><th class="r"><?= e($d['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($segments['rows'] as $row): ?>
    <?php if ($row['suppressed']): ?>
      <tr class="sup"><td><?= e($row['name']) ?></td><td class="r"><?= e($row['n']) ?></td><td colspan="<?= e(2 + count($dims)) ?>">Fewer than <?= e($analytics->segMinN()) ?> respondents. Not shown.</td></tr>
    <?php else: ?>
      <tr><td><?= e($row['name']) ?></td><td class="r"><?= e($row['n']) ?></td><td class="r"><b><?= e($f2($row['o']['median'])) ?></b></td><td class="r"><?= Stats::meanCiHtml($row['o']) ?></td><?php foreach ($dims as $d): ?><td class="r"><?= e($f2($row['medians'][$d['code']])) ?></td><?php endforeach; ?></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($f['tab'] === 'ai'): ?>
  <?php
  $metrics = $analytics->metrics();
  $minN    = $analytics->corrMinN();
  $cell    = static function (array $c): string {
      if ($c['withheld']) {
          return '<td class="r muted">n = ' . e($c['n']) . ', withheld</td>';
      }
      $st = $c['st'];
      if ($st === null) {
          return '<td class="r muted">—</td>';
      }

      return '<td class="r"' . ($st['crosses'] ? ' style="color:var(--stone)"' : '') . '>' . ($st['r'] > 0 ? '+' : '') . e(Stats::toFixed($st['r'], 2))
          . ' <span class="muted">(' . e(Stats::toFixed($st['lo'], 2)) . ' to ' . e(Stats::toFixed($st['hi'], 2)) . ')</span>, n = ' . e($st['n']) . '</td>';
  };
  $names = $ai['names'];
  ?>
  <div id="corrTop">
    <?= $this->partial('admin/_pane-corr', ['rows' => $ai['corr'], 'minN' => $minN]) ?>
  </div>
  <h3 class="sub" style="margin-top:8px">Each skill</h3>
  <p class="muted" style="margin-bottom:10px">Pearson&rsquo;s r between each skill score and each figure, with a 95% confidence interval and n. Coefficients in gray have intervals that cross zero. Coefficients are withheld below n = <?= e($minN) ?>.</p>
  <div class="tscroll"><table class="an-table"><thead><tr><th>Skill</th><?php foreach ($metrics as $m): ?><th class="r"><?= e($m['label']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($ai['skills'] as $row): ?>
    <tr><td><b><?= e($row['d']['name']) ?></b></td><?php foreach ($row['cells'] as $c) { echo $cell($c); } ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <h3 class="sub" style="margin-top:26px">Each practice</h3>
  <p class="muted" style="margin-bottom:10px">The same correlation for each of the eighteen answers. The highlighted rows, <?= e(implode(', ', array_slice($names, 0, -1))) ?> and <?= e($names[count($names) - 1]) ?>, are the practices that bear most directly on reliance on AI. With this many comparisons, about one interval in twenty would exclude zero by chance even if there were no association.</p>
  <div class="tscroll"><table class="an-table"><thead><tr><th>Practice</th><th>Skill</th><?php foreach ($metrics as $m): ?><th class="r"><?= e($m['label']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($ai['practices'] as $row): ?>
    <tr<?= $row['hl'] ? ' class="hl"' : '' ?>><td><b><?= e($row['q']['t']) ?></b></td><td><?= e($row['d']['name']) ?></td><?php foreach ($row['cells'] as $c) { echo $cell($c); } ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<?php else: ?>
  <?php $q = $quality; ?>
  <h3 class="sub">Do the six skills measure six different things?</h3>
  <p class="muted" style="margin-bottom:10px">Pearson&rsquo;s r between skill scores across respondents. Some correlation is expected. If every pair correlates strongly, the scorecard may be picking up a general tendency to rate oneself highly more than six distinct skills. Mean of the fifteen pairs: <b><?= e($f2($q['offMean'])) ?></b>.</p>
  <div class="tscroll"><table class="an-table matrix"><thead><tr><th></th><?php foreach ($dims as $d): ?><th class="r"><?= e($d['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($dims as $i => $d): ?>
    <tr><td><b><?= e($d['name']) ?></b></td><?php foreach ($q['matrix'][$i] as $j => $v): ?><td class="r" style="background:<?= e($j === $i ? 'transparent' : Analytics::shade($v)) ?>"><?= $j === $i ? '—' : e($f2($v)) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <h3 class="sub" style="margin-top:26px">Do each skill&rsquo;s three practices move together?</h3>
  <p class="muted" style="margin-bottom:10px">Cronbach&rsquo;s alpha for each skill&rsquo;s three practices, from respondents who answered all three. Alpha runs lower on three items than on longer scales. A low value means the three practices do not tend to rise and fall together, so the skill score combines different things.</p>
  <div class="tscroll"><table class="an-table"><thead><tr><th>Skill</th><th class="r">n</th><th class="r">Alpha</th></tr></thead><tbody>
  <?php foreach ($q['alphas'] as $x): ?>
    <tr><td><b><?= e($x['d']['name']) ?></b></td><td class="r"><?= e($x['n']) ?></td><td class="r"><?= e($f2($x['a'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <h3 class="sub" style="margin-top:26px">Response patterns</h3>
  <p class="muted" style="margin-bottom:12px">Self-assessments tend to run high. These figures show how far that is happening here, from the <?= e($q['full']) ?> respondents who answered all eighteen questions.</p>
  <div class="grid3">
    <div class="stat"><div class="v"><?= e($pct($q['full'] ? $q['same'] / $q['full'] : null)) ?></div><div class="k">Gave the same answer to all eighteen questions (<?= e($q['same']) ?>)</div></div>
    <div class="stat"><div class="v"><?= e($pct($q['full'] ? $q['high'] / $q['full'] : null)) ?></div><div class="k">Answered 4 or 5 to every question (<?= e($q['high']) ?>)</div></div>
    <div class="stat"><div class="v"><?= e($pct($q['answers'] ? $q['fives'] / $q['answers'] : null)) ?></div><div class="k">Of all answers were 5</div></div>
  </div>
<?php endif; ?>
</div>
<?php $this->stop(); ?>
