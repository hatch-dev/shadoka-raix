<?php
/**
 * paneCorr(): the overall score against each self-reported figure — the
 * warning, a card per figure with r and its interval (withheld below the
 * minimum n), and the scatter. The scatter is drawn by assets/js/admin-charts.js
 * from the spec on the canvas, with the page's chart options.
 *
 * @var list<array<string, mixed>> $rows Analytics::correlations()
 * @var int $minN
 */

use Apps\HumanEdgeScorecard\Services\Stats;

$any = (bool) array_filter($rows, static fn (array $r): bool => count($r['pairs']) >= $minN);
?>
<div class="warnbox" style="margin-bottom:22px"><b>Read this first.</b> These are associations between a self-reported score and a self-reported figure about the respondent's own work, in a self-selected sample. They cannot establish whether these activities strengthen the practices measured here, or whether people with stronger practices simply undertake more of them. Coefficients are withheld below n=<?= e($minN) ?>; the scatter is shown anyway so you can see how thin the data is.</div>
<?php foreach ($rows as $row): $st = $row['stat']; $n = count($row['pairs']); ?>
  <div class="chart-card" style="margin-bottom:18px"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap">
    <div><h4>Overall score vs <?= e($row['m']['label']) ?></h4><div class="cap"><?= e($n) ?> respondent<?= $n === 1 ? '' : 's' ?> reported this figure.</div></div>
    <div style="text-align:right">
      <?php if ($row['ok'] && $st !== null): ?>
        <div style="font-family:var(--num);font-size:28px;font-weight:700;letter-spacing:-.02em;color:<?= abs($st['r']) >= .3 ? 'var(--gold)' : 'var(--stone)' ?>">r = <?= $st['r'] > 0 ? '+' : '' ?><?= e(Stats::toFixed($st['r'], 2)) ?></div>
        <div class="small muted">95% CI <?= e(Stats::toFixed($st['lo'], 2)) ?> to <?= e(Stats::toFixed($st['hi'], 2)) ?>, n = <?= e($st['n']) ?></div>
        <?php if ($st['crosses']): ?><div class="small" style="color:var(--r1);font-weight:500">Interval crosses zero: not distinguishable from no association</div><?php endif; ?>
      <?php else: ?>
        <div class="small" style="color:var(--r1);max-width:280px;font-weight:500">Coefficient withheld. n = <?= e($n) ?>, below the reporting minimum of <?= e($minN) ?>.</div>
      <?php endif; ?>
    </div></div>
    <div style="height:240px;margin-top:12px"><canvas id="cCorr-<?= e($row['m']['k']) ?>" data-chart="<?= e(json_encode([
        'kind'   => 'scatter',
        'points' => $row['pairs'],
        'fit'    => $row['fit'],
        'yTitle' => $row['m']['label'],
    ], JSON_UNESCAPED_UNICODE)) ?>"></canvas></div></div>
<?php endforeach; ?>
<?php if (!$any): ?><p class="small muted">Once thirty or more respondents report a figure, coefficients and confidence intervals appear here. Until then, only the scatter is shown.</p><?php endif; ?>
