<?php
/**
 * The full research report, as a print-first page.
 *
 * The console's PDF button draws the reference's own jsPDF document from
 * /report/data instead. This page is what that button falls back to with
 * scripting off, and it is the fuller document of the two — sectors, year over
 * year, the item breakdown and the correlations, none of which the reference's
 * PDF carried.
 *
 * Deliberately NOT a list of respondents. The CSV and the JSON carry the names,
 * email addresses and phone numbers; a printed report is the document most likely
 * to be forwarded, and there is nothing it needs them for.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$fmt = static fn (?float $v): string => $v === null ? '—' : number_format((float) $v, 2);
$pct = static fn (?float $v): string => $v === null ? '—' : (($v > 0 ? '+' : '') . number_format((float) $v, 2));

$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">The AI Sovereignty Scorecard</div>
    <h1 class="title">Research report</h1>
    <p class="lede">
      <?= e($overview['n']) ?> scorecard<?= $overview['n'] === 1 ? '' : 's' ?> &middot;
      <?= e($overview['organisations']) ?> organization<?= $overview['organisations'] === 1 ? '' : 's' ?> &middot;
      mean overall score <?= e($fmt($overview['mean_average'])) ?> &middot;
      mean floor <?= e($fmt($overview['mean_floor'])) ?>.
      Instrument v<?= e($instrument['version']) ?>, generated <?= e(date('F j, Y')) ?>.
    </p>
  </div>
  <div class="toolbar" style="margin:0">
    <button class="btn quiet sm" type="button" onclick="window.print()">Print</button>
  </div>
</div>

<div class="notice">
  <b>How to read every number here.</b>
  An organization&rsquo;s level is the band of its overall average across all sixteen
  answers. Its floor is its weakest dimension, reported alongside; the floor does
  not set the level.
  <?= $includeSynthetic
      ? 'This report INCLUDES synthetic demo records, which are flagged in the dataset and carry an archetype.'
      : 'Synthetic demo records are excluded.' ?>
</div>

<h2 class="sub">Level distribution</h2>
<div class="stack">
  <?php foreach ($levels as $level): ?>
    <?php if ($level['n'] > 0): ?>
      <i style="width:<?= e($level['percent']) ?>%;background:<?= e($level['colour']) ?>"
         title="<?= e($level['name']) ?>: <?= e($level['n']) ?>"></i>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<table class="data" style="margin-top:14px">
  <thead>
    <tr><th>Level</th><th>Means</th><th class="num">Organizations</th><th class="num">Share</th></tr>
  </thead>
  <tbody>
    <?php foreach ($levels as $level): ?>
      <tr>
        <td><span style="color:<?= e($level['colour']) ?>;font-weight:600">
          <?= e($level['value']) ?> <?= e($level['name']) ?></span></td>
        <td class="muted small"><?= e($level['description']) ?></td>
        <td class="num"><?= e($level['n']) ?></td>
        <td class="num"><?= e($level['percent']) ?>%</td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<hr class="rule">

<h2 class="sub">Dimension means</h2>
<?= $this->partial('admin/_bars', [
    'dimensions' => $dimensions,
    'floorCode'  => $aggregateFloor['code'],
    'bandOf'     => $bandOf,
    'fmt'        => $fmt,
]) ?>

<table class="data" style="margin-top:18px">
  <thead>
    <tr>
      <th>Dimension</th><th class="num">n</th><th class="num">Mean</th>
      <th class="num">Min</th><th class="num">Max</th><th class="num">SD</th>
      <th class="num">Weakest on</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($dimensions as $dimension): ?>
      <tr>
        <td><span class="muted"><?= e($dimension['code']) ?></span> <?= e($dimension['name']) ?></td>
        <td class="num"><?= e($dimension['n']) ?></td>
        <td class="num"><?= e($fmt($dimension['mean'])) ?></td>
        <td class="num"><?= e($fmt($dimension['min'])) ?></td>
        <td class="num"><?= e($fmt($dimension['max'])) ?></td>
        <td class="num"><?= e($fmt($dimension['sd'])) ?></td>
        <td class="num"><?= e($dimension['floor_count']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p class="hint">
  &ldquo;Weakest on&rdquo; counts the scorecards where this dimension was the lowest
  of the four, which is a different figure from its mean.
</p>

<hr class="rule">

<h2 class="sub">By sector</h2>
<?php if ($sectors === []): ?>
  <p class="muted small">No sector was recorded on any matching scorecard.</p>
<?php else: ?>
  <table class="data">
    <thead><tr><th>Sector</th><th class="num">n</th><th class="num">Mean overall score</th><th>Level</th></tr></thead>
    <tbody>
      <?php foreach ($sectors as $sector): ?>
        <?php $band = $bandOf($sector['mean']); ?>
        <tr>
          <td><?= e($sector['sector']) ?></td>
          <td class="num"><?= e($sector['n']) ?></td>
          <td class="num" style="color:<?= e($band['colour_hex'] ?? 'inherit') ?>"><?= e($fmt($sector['mean'])) ?></td>
          <td class="muted small"><?= e($band['name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint">Sectors with a single record are noisy and best treated as anecdote.</p>
<?php endif; ?>

<hr class="rule">

<h2 class="sub">Year over year</h2>
<table class="data">
  <thead>
    <tr><th>Year</th><th class="num">Scorecards</th><th class="num">Mean overall</th>
        <th class="num">Mean floor</th><th class="num">Gap</th></tr>
  </thead>
  <tbody>
    <?php foreach ($yearSeries as $year): ?>
      <?php
      $gap = ($year['mean_average'] !== null && $year['mean_floor'] !== null)
          ? round($year['mean_average'] - $year['mean_floor'], 2)
          : null;
      ?>
      <tr>
        <td><?= e($year['year']) ?></td>
        <td class="num"><?= e($year['n']) ?></td>
        <td class="num"><?= e($fmt($year['mean_average'])) ?></td>
        <td class="num"><?= e($fmt($year['mean_floor'])) ?></td>
        <td class="num"><?= e($pct($gap)) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if ($rescored !== []): ?>
  <h2 class="sub" style="margin-top:22px">Organizations that re-scored</h2>
  <table class="data">
    <thead>
      <tr><th>Organization</th><th>Sector</th><th class="num">First</th><th class="num">Latest</th>
          <th class="num">Change</th><th>Weakest</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rescored as $organisation): ?>
        <?php
        $first = $organisation['years'][$organisation['first_year']] ?? null;
        $last  = $organisation['years'][$organisation['last_year']] ?? null;
        ?>
        <tr>
          <td><?= e($organisation['name']) ?></td>
          <td class="muted small"><?= e($organisation['sector'] ?? '') ?></td>
          <td class="num"><?= e($fmt($first['mean'] ?? null)) ?></td>
          <td class="num"><?= e($fmt($last['mean'] ?? null)) ?></td>
          <td class="num"><?= e($pct($organisation['change'])) ?></td>
          <td class="muted small">
            <?= $organisation['floor_from'] === $organisation['floor_to']
                ? 'unchanged, ' . e((string) $organisation['floor_to'])
                : e((string) ($organisation['floor_from'] ?? '—')) . ' to '
                  . e((string) ($organisation['floor_to'] ?? '—')) ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p class="muted small">No organization has scored in more than one year yet.</p>
<?php endif; ?>

<hr class="rule">

<h2 class="sub">Question breakdown</h2>
<?php foreach ($dimensions as $dimension): ?>
  <?php $detail = $items[$dimension['code']] ?? null; ?>
  <?php if ($detail === null) { continue; } ?>

  <h3 class="sub" style="margin-top:20px;font-size:19px">
    <span class="muted" style="font-weight:400"><?= e($dimension['code']) ?></span>
    <?= e($dimension['name']) ?>
  </h3>

  <table class="data">
    <thead>
      <tr><th>Question</th><th class="num">n</th><th class="num">Mean</th>
          <?php foreach ($bander->rungs() as $rung): ?>
            <th class="num" title="<?= e($rung['name']) ?>"><?= e($rung['value']) ?></th>
          <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($detail['items'] as $item): ?>
        <tr>
          <td>
            <span class="muted small"><?= e($dimension['code']) ?> <?= e($item['position']) ?></span>
            <?= e($item['label']) ?>
          </td>
          <td class="num"><?= e($item['n']) ?></td>
          <td class="num"><?= e($fmt($item['mean'])) ?></td>
          <?php foreach ($item['distribution'] as $count): ?>
            <td class="num"><?= e($count) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($detail['emerging_share'] > $detail['warning_above']): ?>
    <p class="hint">
      <?= e(round($detail['emerging_share'] * 100)) ?>% of marks on this dimension sit
      at Emerging &mdash; present in places, carried by individuals.
    </p>
  <?php endif; ?>
<?php endforeach; ?>

<hr class="rule">

<h2 class="sub">Reported figures and their associations</h2>
<p class="muted">
  <?= e($systems['answered']) ?> of <?= e($systems['total']) ?> respondents could give
  a count of the AI systems on an inventory they have seen.
  Median <?= $systems['median'] === null
      ? '—'
      : e(rtrim(rtrim(number_format($systems['median'], 1), '0'), '.')) ?>.
  A blank means no such list has been compiled, which is not the same as no systems.
</p>

<table class="data" style="margin-top:14px">
  <thead>
    <tr><th>Figure</th><th class="num">n</th><th class="num">r</th><th>95% CI</th><th>Reported?</th></tr>
  </thead>
  <tbody>
    <?php foreach ($correlations as $correlation): ?>
      <tr>
        <td><?= e($correlation['label']) ?></td>
        <td class="num"><?= e($correlation['n']) ?></td>
        <td class="num">
          <?= $correlation['reportable'] && $correlation['stat']
              ? e(($correlation['stat']['r'] > 0 ? '+' : '') . number_format($correlation['stat']['r'], 2))
              : '—' ?>
        </td>
        <td class="muted small">
          <?php if ($correlation['reportable'] && $correlation['stat']): ?>
            <?= e(number_format($correlation['stat']['lo'], 2)) ?>
            to <?= e(number_format($correlation['stat']['hi'], 2)) ?>
            <?= $correlation['stat']['crosses'] ? ' (crosses zero)' : '' ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="small">
          <?= $correlation['reportable']
              ? 'yes'
              : 'withheld, below n = ' . e($correlation['minimum']) ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<hr class="rule">

<h2 class="sub">Read of the dataset</h2>
<div class="analysis"><?= e($reading) ?></div>

<hr class="rule">

<h2 class="sub">Caveats</h2>
<ul class="muted" style="padding-left:20px;line-height:1.7">
  <?php foreach ($caveats as $caveat): ?>
    <li><?= e($caveat) ?></li>
  <?php endforeach; ?>
</ul>

<p class="hint" style="margin-top:22px">
  <?= e(config('app.copyright')) ?>
</p>
<?php $this->stop(); ?>
