<?php
/**
 * Analytics → Dimensions.
 *
 * The reference's pane: the four code chips, the dimension header, three stats,
 * the Emerging warning, the item breakdown, then the two charts.
 *
 * @var \Platform\Core\View $this
 * @var array|null $detail
 */
if ($detail === null) {
    echo '<div class="empty"><b>No dimension selected</b>'
       . 'The published instrument has no dimensions to show.</div>';
    return;
}

$dimension = $detail['dimension'];
$aggregate = $detail['aggregate'] ?? ['mean' => null, 'min' => null, 'max' => null, 'n' => 0];
$band      = $bandOf($aggregate['mean']);
$share     = $detail['emerging_share'];
?>
<div class="toolbar">
  <?php foreach ($dimensions as $candidate): ?>
    <a class="chip<?= $candidate['code'] === $code ? ' on' : '' ?>"
       href="<?= e($link(['dimension' => $candidate['code']])) ?>"><?= e($candidate['code']) ?></a>
  <?php endforeach; ?>
</div>

<div class="dim-head">
  <div class="code"><?= e($dimension['code']) ?></div>
  <div>
    <h2><?= e($dimension['name']) ?></h2>
    <p><?= e($dimension['description']) ?></p>
  </div>
</div>

<div class="grid grid-3" style="margin:0 0 22px">
  <div class="stat" style="--c:<?= e($band['colour_hex'] ?? 'var(--stone)') ?>">
    <div class="v" style="color:var(--c)"><?= e($fmt($aggregate['mean'])) ?></div>
    <div class="k">
      Mean<?= $band ? ', ' . e($band['name']) : '' ?> (n = <?= e($aggregate['n']) ?>)
    </div>
  </div>

  <div class="stat">
    <div class="v">
      <?= $aggregate['n'] > 1
          ? e($fmt($aggregate['min']) . '–' . $fmt($aggregate['max']))
          : '—' ?>
    </div>
    <div class="k">Range across records</div>
  </div>

  <div class="stat">
    <div class="v"><?= e(round($share * 100)) ?>%</div>
    <div class="k">Of marks at 2, Emerging</div>
  </div>
</div>

<?php if ($share > $detail['warning_above']): ?>
  <div class="warnbox" style="margin-bottom:20px">
    <b>Real but not held in place.</b> More than a third of marks on this dimension
    sit at Emerging, present in places and carried by individuals rather than
    by the organization. The organization needs to make these practices part of its
    routine work.
  </div>
<?php endif; ?>

<h2 class="sub">Item breakdown</h2>

<div style="margin-bottom:8px">
  <?php foreach ($detail['items'] as $item): ?>
    <?php $itemBand = $bandOf($item['mean']); ?>
    <div class="qrow" style="--c:<?= e($itemBand['colour_hex'] ?? 'var(--stone)') ?>">
      <div>
        <span class="small muted" style="font-weight:600">
          <?= e($dimension['code']) ?> <?= e($item['position']) ?>
        </span>
        <?= e($item['question']) ?>
      </div>
      <div class="bar-track">
        <?php /* 1 to 5, so a full bar is four points wide. */ ?>
        <i style="width:<?= $item['mean'] === null ? 0 : e(round((($item['mean'] - 1) / 4) * 100, 2)) ?>%"></i>
      </div>
      <div class="mono small" style="text-align:right"><?= e($fmt($item['mean'])) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($detail['weakest'] !== null): ?>
  <p class="small muted">
    Weakest item:
    <b style="color:var(--paper)"><?= e($dimension['code']) ?> <?= e($detail['weakest']['position']) ?></b>
    at <?= e($fmt($detail['weakest']['mean'])) ?>,
    <?= e(strtolower((string) $detail['weakest']['label'])) ?>.
    It has the lowest average score among the responses shown.
  </p>
<?php endif; ?>

<hr class="rule">

<div class="grid grid-2">
  <div class="chart-card">
    <h4>Mark distribution</h4>
    <div class="cap">How the four items land across the ladder.</div>
    <div style="height:260px"><canvas id="cDist"></canvas></div>
  </div>

  <div class="chart-card">
    <h4>This dimension by sector</h4>
    <div class="cap">Mean, filtered set.</div>
    <?php if ($detail['by_sector'] === []): ?>
      <p class="muted small">No sector was recorded on any matching scorecard.</p>
    <?php else: ?>
      <div style="height:260px"><canvas id="cDimSec"></canvas></div>
    <?php endif; ?>
  </div>
</div>
