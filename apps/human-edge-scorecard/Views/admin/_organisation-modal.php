<?php
/**
 * One organization — the page's openOrg() dialog: sector, size and how many
 * scorecards; the level of the average; one bar per skill with the range
 * between its respondents; the radar of the organization means. Download PDF
 * (exportOrgPDF) and Close.
 *
 * @var array<string, mixed> $org Analytics::organisation()
 * @var \Apps\HumanEdgeScorecard\Services\Stats $stats
 * @var list<array<string, mixed>> $dims
 * @var string $slug
 */

use Apps\HumanEdgeScorecard\Services\Html;

$ls    = $org['summary']['level'];
$n     = count($org['recs']);
$radar = [
    'kind'   => 'radar',
    'labels' => array_column($dims, 'code'),
    'data'   => array_map(static fn (array $d) => $org['dims'][$d['code']] ?? null, $dims),
];
$pdf = url('admin/' . $slug . '/analytics/organisation/report-data') . '?' . http_build_query(['name' => $org['name']]);
?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px"><div><h3><?= e($org['name']) ?></h3><p class="muted small"><?= e($org['sector']) ?>, <?= e($org['size']) ?> employees. <?= e($n) ?> scorecard<?= $n === 1 ? '' : 's' ?>.<?= $org['synthetic'] ? ' <span class="tag syn">Synthetic</span>' : '' ?></p></div>
  <div style="text-align:right"><div style="font-family:var(--num);font-size:40px;font-weight:700;letter-spacing:-.03em;line-height:1;color:<?= e($ls['c'] ?? 'var(--stone)') ?>"><?= e($ls['v'] ?? '—') ?></div><div class="small muted" style="margin-top:4px"><?= e($ls['name'] ?? '') ?></div></div></div>
<div style="margin:18px 0"><?= Html::dimRows($stats, $org['dims'], $org['agg']) ?></div>
<div style="height:260px;margin-top:18px"><canvas id="orgRadar" data-chart="<?= e(json_encode($radar)) ?>"></canvas></div>
<div class="nav-row">
  <button class="btn quiet sm" type="button" data-org-pdf="<?= e($pdf) ?>" data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">Download PDF</button>
  <a class="btn sm" data-close-modal href="<?= e(url('admin/' . $slug . '/analytics')) ?>">Close</a>
</div>
