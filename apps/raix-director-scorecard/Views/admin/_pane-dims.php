<?php
/**
 * Analytics → Competencies.
 *
 * The reference's pane: one competency at a time, chosen from the chip row —
 * what it is, what the board should be able to ask, how the set scores it, the
 * five statements behind that score, and the two charts that say how the marks
 * are distributed and who is weak on it.
 *
 * One competency, not a table of seven. The seven together are the Overview's
 * bars and radar; the question here is the one those cannot answer, which is
 * what a mean of 3.00 is made of. It can be five 3s, or a 1 and four 3.5s.
 *
 * The chips are links, not `F.dim` in a closure, so a competency can be linked
 * to and reloaded — and they carry the filter bar with them, which the first
 * port of this pane did not: its chips dropped `tab` and threw you back to the
 * Overview every time you picked one.
 *
 * @var \Platform\Core\View $this
 */
$selected = $items === null ? null : $items['competency'];

/*
 * The aggregate for the selected competency, out of the same competencies()
 * the Overview bars are drawn from. Recomputing it here from the item means
 * would give a second, differently-rounded answer to the same question.
 */
$aggregate = null;
foreach ($competencies as $competency) {
    if ($selected !== null && $competency['code'] === $selected['code']) {
        $aggregate = $competency;
        break;
    }
}

$awareShare = $items === null ? null : $items['aware_share'];
?>
<div class="toolbar">
  <?php foreach ($competencies as $competency): ?>
    <a class="chip<?= $selected !== null && $selected['code'] === $competency['code'] ? ' on' : '' ?>"
       href="<?= e($link(['competency' => $competency['code']])) ?>"
       title="<?= e($competency['name']) ?>"><?= e($competency['code']) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($selected === null): ?>
  <div class="empty"><b>No competency to show</b>
    The published instrument has no competencies on it.</div>
<?php else: ?>

  <div class="dim-head" style="margin-bottom:26px">
    <div class="code"><?= e($selected['code']) ?></div>
    <div>
      <h2><?= e($selected['name']) ?></h2>
      <p><?= e($selected['description']) ?></p>
      <div class="board-q">Board asks: “<?= e($selected['board_question']) ?>”</div>
    </div>
  </div>

  <?php $band = $aggregate === null ? null : $bandOf($aggregate['mean']); ?>
  <div class="grid grid-3" style="margin-bottom:22px">
    <div class="stat" style="--c:<?= e($band['colour_hex'] ?? 'var(--stone)') ?>">
      <div class="v" style="color:var(--c)"><?= e($fmt($aggregate['mean'] ?? null)) ?></div>
      <div class="k">
        Mean<?= $band ? ', ' . e(strtolower((string) $band['name'])) : '' ?>
        (n = <?= e($aggregate['n'] ?? 0) ?>)
      </div>
    </div>

    <div class="stat">
      <div class="v">
        <?= ($aggregate['n'] ?? 0) > 1
            ? e($fmt($aggregate['min']) . '–' . $fmt($aggregate['max']))
            : '—' ?>
      </div>
      <div class="k">Range across records</div>
    </div>

    <div class="stat">
      <div class="v"><?= $awareShare === null ? '—' : e((int) round($awareShare * 100)) . '%' ?></div>
      <div class="k">Of marks at Aware</div>
    </div>
  </div>

  <?php if ($awareShare !== null && $awareShare > 0.35): ?>
    <div class="warnbox" style="margin-bottom:22px">
      <b>Understanding without action.</b>
      <?= e((int) round($awareShare * 100)) ?>% of marks on this competency sit at
      Aware — more than a third: directors who understand its value and have not
      acted on it.
    </div>
  <?php endif; ?>

  <h3 class="sub">Item breakdown</h3>
  <div style="margin-bottom:8px">
    <?php foreach ($items['items'] as $item): ?>
      <?php $itemBand = $bandOf($item['mean']); ?>
      <div class="qrow" style="--c:<?= e($itemBand['colour_hex'] ?? 'var(--stone)') ?>">
        <div>
          <span class="small muted" style="font-weight:600"><?= e($selected['code']) ?> <?= e($item['position']) ?></span>
          <?= e($item['statement']) ?>
          <div class="small muted" style="margin-top:3px">
            <?= e($item['n']) ?> mark<?= $item['n'] === 1 ? '' : 's' ?>,
            <?= e($item['with_evidence']) ?> with evidence
          </div>
        </div>
        <div class="mini"><i style="width:<?= e($item['mean'] === null ? 0 : round((($item['mean'] - 1) / 3) * 100, 1)) ?>%"></i></div>
        <div class="mono small" style="text-align:right"><?= e($fmt($item['mean'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($items['weakest'] !== null): ?>
    <p class="small muted">
      Weakest item:
      <b style="color:var(--paper)"><?= e($selected['code']) ?> <?= e($items['weakest']['position']) ?></b>
      at <?= e($fmt($items['weakest']['mean'])) ?>. It is the act directors in this
      set have done least.
    </p>
  <?php endif; ?>

  <hr class="rule">

  <div class="grid grid-2">
    <div class="chart-card">
      <h4>Mark distribution</h4>
      <div class="cap">How the five items land across the ladder.</div>
      <div style="height:260px"><canvas id="cDist"></canvas></div>
      <?php
      /*
       * The same counts in words. A stacked bar is read by eye and a rung with
       * four marks against a rung with forty is a sliver either way.
       */
      ?>
      <div class="legend">
        <?php foreach ($rungs as $index => $rung): ?>
          <?php $total = array_sum(array_map(
              static fn (array $i): int => $i['distribution'][$index] ?? 0,
              $items['items']
          )); ?>
          <span style="--c:<?= e($rung['colour_hex']) ?>"><?= e($rung['name']) ?>: <?= e($total) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="chart-card">
      <h4>This competency by sector</h4>
      <div class="cap">Mean, filtered set. A sector with one record is anecdote.</div>
      <?php if ($dimSectors === []): ?>
        <p class="muted small">No sector was recorded on any matching scorecard.</p>
      <?php else: ?>
        <div style="height:260px"><canvas id="cDimSec"></canvas></div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>
