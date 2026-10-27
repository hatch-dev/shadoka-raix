<?php
/**
 * One organisation, as the dialog the dashboard opens over itself — the
 * reference's `openOrg()`.
 *
 * The body is shared with the full page (admin/organisation.php), so the dialog
 * and the page cannot disagree.
 *
 * What makes this view worth having, and what the reference's could not show:
 * the min–max range on each bar is the spread BETWEEN this organisation's own
 * executives. A wide range on one dimension means its leadership does not agree
 * about what the organisation actually does, which is a finding in itself.
 *
 * @var \Platform\Core\View $this
 * @var array $org
 * @var \Platform\Assessment\Bander $bander
 * @var bool $includeSynthetic
 * @var string $slug
 */
$fmt = static fn (?float $v): string => $v === null ? '—' : number_format((float) $v, 2);

$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

$latest = $org['latest'];
$first  = $org['first'];
$years  = $org['years'];
$level  = $bandOf($latest['mean']);
$movement = count($years) > 1;
?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div>
    <h3><?= e($org['organisation']['name']) ?></h3>
    <p class="muted small">
      <?= e(implode(', ', array_filter([$org['sector'], $org['size'] ? $org['size'] . ' employees' : null]))) ?>.
      <?= e(count($org['submissions'])) ?> scorecard<?= count($org['submissions']) === 1 ? '' : 's' ?>
      across <?= e(count($years)) ?> year<?= count($years) === 1 ? '' : 's' ?>.
      <?php if ($org['synthetic']): ?><span class="tag syn">Synthetic</span><?php endif; ?>
    </p>
  </div>
  <?php /* The LEVEL (1 to 5), as the reference's openOrg() prints `ls.level.v` —
           not the overall score to one decimal, which read as a third figure. */ ?>
  <div style="text-align:right;flex-shrink:0">
    <div style="font-family:var(--num);font-size:40px;font-weight:700;letter-spacing:-.03em;
                line-height:1;color:<?= e($level['colour_hex'] ?? 'var(--stone)') ?>">
      <?= e($level['value'] ?? '—') ?>
    </div>
    <div class="small muted" style="margin-top:4px">
      <?= e($level['name'] ?? '') ?>, floor <?= e($fmt($latest['floor'])) ?>
    </div>
  </div>
</div>

<p class="muted small" style="margin-top:10px">
  <?php /* Which year these bars are, said out loud. The PDF covers every year. */ ?>
  Showing <?= e($latest['year']) ?><?= $movement ? ', its most recent year' : '' ?>,
  averaged across <?= e($latest['n']) ?> respondent<?= $latest['n'] === 1 ? '' : 's' ?>.
</p>

<div style="margin:18px 0">
  <?= $this->partial('admin/_bars', [
      'dimensions' => $latest['dimensions'],
      'floorCode'  => $latest['floor_code'],
      'bandOf'     => $bandOf,
      'fmt'        => $fmt,
  ]) ?>
</div>

<?php if ($latest['n'] > 1): ?>
  <p class="small muted">
    The pale band on each bar is the range between this organization&rsquo;s own
    respondents. A wide band means its executives do not agree about what the
    organization does on that dimension.
  </p>
<?php endif; ?>

<?php if ($movement): ?>
  <h4 style="font-family:var(--serif);font-weight:600;font-size:19px;margin:20px 0 8px">
    Movement, <?= e($years[0]) ?> &rarr; <?= e($years[count($years) - 1]) ?>
  </h4>
  <?php /* The reference's table: body-face figures, and &Delta; for the change. */ ?>
  <table class="data sans-num">
    <thead>
      <tr>
        <th>Dimension</th>
        <th class="num"><?= e($years[0]) ?></th>
        <th class="num"><?= e($years[count($years) - 1]) ?></th>
        <th class="num">&Delta;</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $firstByCode = [];
      foreach ($first['dimensions'] as $dimension) {
          $firstByCode[$dimension['code']] = $dimension['mean'];
      }
      ?>
      <?php foreach ($latest['dimensions'] as $dimension): ?>
        <?php
        $was    = $firstByCode[$dimension['code']] ?? null;
        $now    = $dimension['mean'];
        $change = ($was !== null && $now !== null) ? round($now - $was, 2) : null;
        ?>
        <tr>
          <td><?= e($dimension['name']) ?></td>
          <td class="num"><?= e($fmt($was)) ?></td>
          <td class="num"><?= e($fmt($now)) ?></td>
          <td class="num">
            <span class="delta <?= $change === null ? '' : ($change > 0 ? 'up' : ($change < 0 ? 'down' : '')) ?>">
              <?= $change === null ? '—' : e(($change > 0 ? '+' : '') . number_format($change, 2)) ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php
/*
 * The reference's orgRadar: the first year dashed grey under the latest in
 * gold when the organisation has scored in more than one year, the latest
 * alone otherwise. Same options as openOrg() drew it.
 *
 * The script is inline because this body arrives as a dialog fragment, whose
 * scripts the dialog runs; it loads Chart.js itself when the page behind it
 * (the standalone organisation page, or a pane with no charts) has not.
 */
$firstByCode = [];
foreach ($first['dimensions'] as $dimension) {
    $firstByCode[$dimension['code']] = $dimension['mean'];
}

$radar = [
    'labels'   => array_map(static fn (array $d): string => (string) $d['code'], $latest['dimensions']),
    'datasets' => $movement
        ? [
            [
                'label' => (string) $years[0],
                'data'  => array_map(static fn (array $d) => $firstByCode[$d['code']] ?? null, $latest['dimensions']),
                'first' => true,
            ],
            [
                'label' => (string) $years[count($years) - 1],
                'data'  => array_map(static fn (array $d) => $d['mean'], $latest['dimensions']),
                'first' => false,
            ],
        ]
        : [
            [
                'label' => (string) ($years[0] ?? ''),
                'data'  => array_map(static fn (array $d) => $d['mean'], $latest['dimensions']),
                'first' => false,
            ],
        ],
];
?>
<div style="height:260px;margin-top:18px">
  <canvas data-org-radar="<?= e(json_encode($radar, JSON_UNESCAPED_UNICODE)) ?>"
          data-chartjs="<?= e(app_asset($slug, 'vendor/chart.umd.min.js')) ?>"></canvas>
</div>
<script>
  (function () {
    var canvases = document.querySelectorAll('canvas[data-org-radar]');
    var canvas = canvases[canvases.length - 1];
    if (!canvas || canvas.dataset.drawn) return;

    function draw() {
      if (!window.Chart || canvas.dataset.drawn) return;
      canvas.dataset.drawn = '1';
      var spec = JSON.parse(canvas.getAttribute('data-org-radar'));
      new window.Chart(canvas, {
        type: 'radar',
        data: {
          labels: spec.labels,
          datasets: spec.datasets.map(function (set) {
            return set.first
              ? { label: set.label, data: set.data, borderColor: '#8A9BB0', backgroundColor: 'rgba(138,155,176,.08)', borderWidth: 1.2, pointRadius: 2, borderDash: [4, 3] }
              : { label: set.label, data: set.data, borderColor: '#E8933F', backgroundColor: 'rgba(232,147,63,.12)', borderWidth: 1.6, pointRadius: 3 };
          }),
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            r: {
              min: 0,
              max: 5,
              ticks: { stepSize: 1, color: '#6F84A0', backdropColor: 'transparent', font: { size: 10 } },
              grid: { color: 'rgba(61,130,196,.18)' },
              angleLines: { color: 'rgba(61,130,196,.18)' },
              pointLabels: { color: '#A0B4CA', font: { family: 'Spline Sans Mono', size: 11 } },
            },
          },
          plugins: { legend: { labels: { color: '#A0B4CA', boxWidth: 12 } } },
        },
      });
    }

    if (window.Chart) {
      draw();
    } else {
      var script = document.createElement('script');
      script.src = canvas.getAttribute('data-chartjs');
      script.onload = draw;
      document.head.appendChild(script);
    }
  })();
</script>

<div class="nav-row">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php
    /*
     * The PDF covers every year this organisation has scored in, which is not the
     * set the bars above show. admin-pdf.js prints the span on the document, so
     * the two cannot be mistaken for each other.
     */
    ?>
    <button class="btn quiet sm" type="button"
            data-org-pdf="<?= e(url('admin/' . $slug . '/analytics/organisation/' . $org['organisation']['id']
                . '/report-data') . '?synthetic=' . ($includeSynthetic ? '1' : '0')) ?>"
            data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">Download PDF</button>
  </div>
  <button class="btn sm" type="button" data-close-modal>Close</button>
</div>
