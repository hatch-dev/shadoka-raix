<?php
/**
 * One board, as the dialog the dashboard opens over itself.
 *
 * The reference's `openOrg()` modal, section for section: who the board is and
 * what level its latest year sits at, the seven competencies as bars, the
 * movement between its first scored year and its latest, and the two years on
 * one radar.
 *
 * The figures are the board AGGREGATED, then banded — the mean of its
 * directors per competency, with the floor rule applied to those means. That is
 * a different number from the mean of the directors' own floors, and it is the
 * board-level question: where is this board weakest.
 *
 * Served as a fragment, so there is no layout here and no <script src>. The one
 * inline script draws the radar; it runs because the dialog re-executes the
 * scripts in what it injected, and it does nothing if Chart.js is not on the
 * page behind it — every figure it draws is already in the bars above it.
 *
 * @var \Platform\Core\View $this
 * @var array<string,mixed> $board
 * @var list<array<string,mixed>> $byYear  oldest year first
 * @var \Platform\Assessment\Bander $bander
 */
$fmt = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);

$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

$org       = $board['organisation'];
$directors = $board['directors'];

$first  = $byYear === [] ? null : $byYear[0];
$latest = $byYear === [] ? null : $byYear[count($byYear) - 1];
$level  = $latest === null ? null : $bandOf($latest['floor']);

$href = url('admin/' . $slug . '/analytics/board/' . (int) $org['id']
    . ($includeSynthetic ? '?synthetic=1' : ''));
?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div>
    <h3>
      <a href="<?= e($href) ?>" style="color:inherit;text-decoration:none"
         title="Open the full board page">
        <?= e($org['name']) ?>
      </a>
      <?php if ((int) $org['is_synthetic'] === 1): ?><span class="tag syn">Synthetic</span><?php endif; ?>
    </h3>
    <p class="muted small">
      <?= e(implode(', ', array_filter([$org['sector'], $org['size_band']]))) ?><?= $org['size_band'] ? ' employees' : '' ?>.
      <?= e(count($directors)) ?> director scorecard<?= count($directors) === 1 ? '' : 's' ?>
      across <?= e(count($byYear)) ?> year<?= count($byYear) === 1 ? '' : 's' ?>.
    </p>
  </div>

  <div style="text-align:right">
    <div style="font-family:var(--num);font-size:40px;font-weight:700;letter-spacing:-.03em;line-height:1;
                color:<?= e($level['colour_hex'] ?? 'var(--stone)') ?>">
      <?= e($level['value'] ?? '—') ?>
    </div>
    <div class="small muted" style="margin-top:4px">
      <?= $level ? e($level['name']) . ', ' : '' ?>floor <?= e($fmt($latest['floor'] ?? null)) ?>
    </div>
  </div>
</div>

<?php if ($latest === null): ?>
  <div class="empty" style="margin-top:18px"><b>No scorecards</b>Nobody on this board has completed one.</div>
<?php else: ?>

  <?php
  /*
   * Which year the bars are. The reference left this unsaid, and its own PDF
   * of the same board aggregated every year — so the two disagreed about the
   * floor with nothing on either to explain why.
   */
  ?>
  <div class="cap" style="margin:16px 0 0">
    Board means, <?= e($latest['year']) ?><?= count($byYear) > 1 ? ' — its latest scored year' : '' ?>.
  </div>

  <div style="margin:10px 0 0">
    <?= $this->partial('admin/_bars', [
        'competencies' => $latest['competencies'],
        'bandOf'       => $bandOf,
        'fmt'          => $fmt,
    ]) ?>
  </div>

  <?php if (count($byYear) > 1): ?>
    <h4 style="font-family:var(--serif);font-weight:600;font-size:19px;margin:20px 0 8px">
      Movement, <?= e($first['year']) ?> → <?= e($latest['year']) ?>
    </h4>
    <?php
    // The first year's means, by code, so the two years can be put side by side
    // even where a competency went unscored in one of them.
    $before = [];
    foreach ($first['competencies'] as $competency) {
        $before[$competency['code']] = $competency['mean'];
    }
    ?>
    <table class="data">
      <thead>
        <tr><th>Competency</th><th class="num"><?= e($first['year']) ?></th>
            <th class="num"><?= e($latest['year']) ?></th><th class="num">Δ</th></tr>
      </thead>
      <tbody>
        <?php foreach ($latest['competencies'] as $competency): ?>
          <?php
          $was   = $before[$competency['code']] ?? null;
          $now   = $competency['mean'];
          $delta = ($was === null || $now === null) ? null : round($now - $was, 2);
          ?>
          <tr>
            <td><?= e($competency['name']) ?></td>
            <td class="num"><?= e($fmt($was)) ?></td>
            <td class="num"><?= e($fmt($now)) ?></td>
            <td class="num">
              <span class="delta <?= $delta > 0 ? 'up' : ($delta < 0 ? 'down' : '') ?>">
                <?= $delta === null ? '—' : e(($delta > 0 ? '+' : '') . number_format($delta, 2)) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="height:260px;margin-top:18px"><canvas id="orgRadar"></canvas></div>

  <script type="application/json" id="orgRadarData"><?= json_encode([
      // The dialog opens over two screens, and only one of them has already
      // loaded Chart.js. Rather than make the radar a property of which page
      // you came from, it carries the library's own URL and fetches it when it
      // is not there — cached by then in every case that matters.
      'chart'  => app_asset($slug, 'vendor/chart.umd.min.js'),
      'labels' => array_column($latest['competencies'], 'code'),
      'series' => array_values(array_map(
          static fn (array $profile): array => [
              'year'   => $profile['year'],
              'values' => array_column($profile['competencies'], 'mean'),
          ],
          // The reference drew the first year and the latest, not every year
          // between: two lines is a comparison, five is a thicket.
          count($byYear) > 1 ? [$first, $latest] : [$latest]
      )),
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<?php
/*
 * Download PDF and Close, as the reference's dialog had. Its PDF was drawn by
 * jsPDF in the browser and this one still is — from figures the server
 * computed, so the document cannot state a number these bars do not.
 *
 * The full board page, which the reference had no equivalent of, is the board
 * name at the top of this dialog. It carries the coverage table: how many of
 * these directors reach Practiced on each competency.
 */
?>
<div class="nav-row">
  <a class="btn quiet sm" href="<?= e($href) ?>"
     data-board-pdf="<?= e(url('admin/' . $slug . '/analytics/board/' . (int) $org['id'] . '/report-data')
         . ($includeSynthetic ? '?synthetic=1' : '')) ?>"
     data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">Download PDF</a>
  <button class="btn sm" data-close-modal>Close</button>
</div>

<script>
(function () {
  var payload = document.getElementById('orgRadarData');
  var canvas = document.getElementById('orgRadar');
  if (!payload || !canvas) return;

  var data = JSON.parse(payload.textContent);
  var OLD = '#8A9BB0';
  var NOW = '#E8933F';

  if (window.Chart) {
    draw();
  } else {
    var library = document.createElement('script');
    library.src = data.chart;
    library.onload = draw;
    // A radar that cannot be drawn leaves no empty box behind: every figure in
    // it is already in the bars and the movement table above.
    library.onerror = function () { canvas.parentNode.remove(); };
    document.head.appendChild(library);
  }

  function draw() {
  if (!window.Chart || !document.body.contains(canvas)) return;

  new window.Chart(canvas, {
    type: 'radar',
    data: {
      labels: data.labels,
      datasets: data.series.map(function (series, index) {
        var latest = index === data.series.length - 1;
        return {
          label: String(series.year),
          data: series.values,
          borderColor: latest ? NOW : OLD,
          backgroundColor: latest ? 'rgba(232,147,63,.12)' : 'rgba(138,155,176,.08)',
          borderWidth: latest ? 1.6 : 1.2,
          pointRadius: latest ? 3 : 2,
          borderDash: latest ? undefined : [4, 3],
        };
      }),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: '#A0B4CA', boxWidth: 12 } } },
      scales: {
        r: {
          min: 0,
          max: 4,
          ticks: { stepSize: 1, color: '#6F84A0', backdropColor: 'transparent', font: { size: 10 } },
          grid: { color: 'rgba(61,130,196,.18)' },
          angleLines: { color: 'rgba(61,130,196,.18)' },
          pointLabels: { color: '#A0B4CA', font: { family: 'IBM Plex Mono', size: 11 } },
        },
      },
    },
  });
  }
}());
</script>
