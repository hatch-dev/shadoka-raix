<?php
/**
 * The research report.
 *
 * Its own document, not an admin screen: no sidebar, no dark theme, and a
 * layout chosen for A4. Print it and you have the PDF.
 *
 * @var \Platform\Core\View $this
 */
$fmt  = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
$pct  = static fn (?float $v): string => $v === null ? '—' : (($v > 0 ? '+' : '') . number_format($v, 2));
$date = static fn (?string $d): string => $d === null
    ? '—'
    : date((string) config('app.date_format'), strtotime($d));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="icon" href="<?= e(asset('platform/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('platform/css/report.css')) ?>">
</head>
<body>

<div class="toolbar">
  <a href="<?= e(url('admin/' . $slug . '/research-data')) ?>">← Research data</a>
  <?php if (!$includeSynthetic && $counts['synthetic'] > 0): ?>
    <a href="<?= e(url('admin/' . $slug . '/report?synthetic=1')) ?>">Include synthetic</a>
  <?php elseif ($includeSynthetic): ?>
    <a href="<?= e(url('admin/' . $slug . '/report')) ?>">Real records only</a>
  <?php endif; ?>
  <button type="button" onclick="window.print()">Save as PDF</button>
</div>

<div class="sheet">

  <div class="masthead">
    <div class="eyebrow">Responsible AI Index · RAI-X</div>
    <h1><?= e($instrument['name']) ?></h1>
    <div class="meta">
      <span>Instrument <b>v<?= e($instrument['version']) ?></b></span>
      <span>Generated <b><?= e(date((string) config('app.date_format'))) ?></b></span>
      <span>Scorecards <b><?= e($overview['n']) ?></b></span>
      <span>Boards <b><?= e($overview['boards']) ?></b></span>
      <?php if ($overview['first_at'] !== null): ?>
        <span>Collected <b><?= e($date($overview['first_at'])) ?> – <?= e($date($overview['last_at'])) ?></b></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($includeSynthetic && $counts['synthetic'] > 0): ?>
    <div class="scope-warning">
      <b>This report includes synthetic demo records.</b>
      <?= e($counts['synthetic']) ?> of the scorecards below were generated for
      demonstration and are marked <span class="tag">synthetic</span> in the
      appendix. They are not observations of real directors and must not be cited
      as findings. Correlations exclude them regardless.
    </div>
  <?php endif; ?>

  <?php if ($overview['n'] === 0): ?>
    <p class="muted">No completed scorecards. There is nothing to report yet.</p>
  <?php else: ?>

    <h2>Summary</h2>

    <div class="figures avoid-break">
      <div class="figure"><div class="v"><?= e($overview['n']) ?></div><div class="k">Scorecards</div></div>
      <div class="figure"><div class="v"><?= e($fmt($overview['mean_floor'])) ?></div><div class="k">Mean floor</div></div>
      <div class="figure"><div class="v"><?= e($fmt($overview['mean_average'])) ?></div><div class="k">Mean average</div></div>
      <div class="figure"><div class="v"><?= e($pct($overview['gap'])) ?></div><div class="k">Average above floor</div></div>
    </div>

    <p class="note">
      A director's level is the band of their <b>weakest</b> competency, not their
      average. The gap between the two — <?= e($pct($overview['gap'])) ?> across this
      set — is how much stronger directors are elsewhere than on the competency
      that actually caps them. It is what a rising average conceals.
    </p>

    <h3>Level distribution</h3>
    <table class="avoid-break">
      <thead><tr><th>Level</th><th>Means</th><th class="num">Directors</th><th class="num">Share</th><th style="width:150px"></th></tr></thead>
      <tbody>
        <?php foreach ($levels as $level): ?>
          <tr>
            <td><b><?= e($level['value']) ?></b> · <?= e($level['name']) ?></td>
            <td class="muted small"><?= e(rtrim((string) $level['description'], '.')) ?></td>
            <td class="num"><?= e($level['n']) ?></td>
            <td class="num"><?= e($level['percent']) ?>%</td>
            <td><div class="bar" style="--c:<?= e($level['colour']) ?>"><i style="width:<?= e($level['percent']) ?>%"></i></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h2>Competencies</h2>
    <table class="avoid-break">
      <thead>
        <tr><th>Competency</th><th class="num">n</th><th class="num">Mean</th>
            <th style="width:120px"></th><th class="num">Range</th><th class="num">SD</th>
            <th class="num">Sets the floor</th></tr>
      </thead>
      <tbody>
        <?php foreach ($competencies as $competency): ?>
          <tr>
            <td><span class="code"><?= e($competency['code']) ?></span><?= e($competency['name']) ?></td>
            <td class="num"><?= e($competency['n']) ?></td>
            <td class="num"><b><?= e($fmt($competency['mean'])) ?></b></td>
            <td>
              <?php if ($competency['mean'] !== null): ?>
                <div class="bar"><i style="width:<?= e(round((($competency['mean'] - 1) / 3) * 100, 1)) ?>%"></i></div>
              <?php endif; ?>
            </td>
            <td class="num muted"><?= $competency['n'] > 1 ? e($fmt($competency['min']) . '–' . $fmt($competency['max'])) : '—' ?></td>
            <td class="num muted"><?= e($fmt($competency['sd'])) ?></td>
            <td class="num"><?= e($competency['floor_count']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small muted">
      “Sets the floor” counts the directors whose level was capped by that
      competency. It is the most actionable column in the table.
    </p>

    <?php if (count($years) > 1): ?>
      <h2>Year over year</h2>
      <table class="avoid-break">
        <thead><tr><th>Year</th><th class="num">Directors</th><th class="num">Mean floor</th>
                   <th class="num">Mean average</th><th class="num">Gap</th><th>Movement</th></tr></thead>
        <tbody>
          <?php foreach ($years as $year): ?>
            <tr>
              <td><b><?= e($year['year']) ?></b></td>
              <td class="num"><?= e($year['n']) ?></td>
              <td class="num"><?= e($fmt($year['mean_floor'])) ?></td>
              <td class="num muted"><?= e($fmt($year['mean_average'])) ?></td>
              <td class="num"><?= e($pct($year['gap'])) ?></td>
              <td class="small">
                <?php if ($year['floor_change'] === null): ?>
                  <span class="muted">baseline</span>
                <?php elseif (abs($year['floor_change']) <= 0.05 && $year['gap_change'] > 0.05): ?>
                  Average rising, floor static
                <?php elseif ($year['floor_change'] > 0.05 && $year['gap_change'] < -0.05): ?>
                  Floor rising, gap closing
                <?php else: ?>
                  <span class="muted">floor <?= e($pct($year['floor_change'])) ?>, gap <?= e($pct($year['gap_change'])) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($sectors !== []): ?>
      <h2>By sector</h2>
      <table class="avoid-break">
        <thead><tr><th>Sector</th><th class="num">Directors</th><th class="num">Mean floor</th></tr></thead>
        <tbody>
          <?php foreach ($sectors as $sector): ?>
            <tr>
              <td><?= e($sector['sector']) ?></td>
              <td class="num"><?= e($sector['n']) ?></td>
              <td class="num"><?= e($fmt($sector['mean_floor'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="small muted">A sector with a single record is an anecdote, not a finding.</p>
    <?php endif; ?>

    <div class="page-break"></div>
    <h2>Statement-level detail</h2>
    <p class="small muted">
      A competency mean of 3.00 can be five 3s, or a 1 and four 3.5s. This is
      where those differ. Marks run 1 (Unaware) to 4 (Systematic), left to right.
    </p>

    <?php foreach ($statements as $code => $detail): ?>
      <div class="avoid-break" style="margin-top:16px">
        <h3><span class="code"><?= e($code) ?></span><?= e($detail['competency']['name']) ?></h3>
        <p class="small muted" style="margin-bottom:6px">“<?= e($detail['competency']['board_question']) ?>”</p>

        <?php if ($detail['aware_share'] !== null && $detail['aware_share'] > 0.35): ?>
          <p class="note">
            <?= e((int) round($detail['aware_share'] * 100)) ?>% of marks here sit at
            Aware: directors who understand the value and have acted on none of it.
          </p>
        <?php endif; ?>

        <table>
          <thead><tr><th style="width:44px">Item</th><th>Statement</th><th class="num">n</th>
                     <th class="num">Mean</th><th style="width:110px">Marks</th></tr></thead>
          <tbody>
            <?php foreach ($detail['items'] as $item): ?>
              <tr>
                <td class="mono small"><?= e($code) ?> <?= e($item['position']) ?></td>
                <td class="statement"><?= e($item['statement']) ?></td>
                <td class="num"><?= e($item['n']) ?></td>
                <td class="num"><b><?= e($fmt($item['mean'])) ?></b></td>
                <td>
                  <?php if ($item['n'] > 0): ?>
                    <div class="stack">
                      <?php foreach ($item['distribution'] as $index => $count): ?>
                        <?php if ($count > 0): ?>
                          <i style="width:<?= e(round($count / $item['n'] * 100, 1)) ?>%;background:<?= e($levels[$index]['colour'] ?? '#7A8899') ?>"></i>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

    <h2>Associations</h2>
    <p class="small">
      Between a director's floor score and figures they reported about their own
      board. Synthetic records are excluded here unconditionally.
    </p>
    <table class="avoid-break">
      <thead><tr><th>Association</th><th class="num">n</th><th class="num">r</th><th>95% CI</th><th>Reading</th></tr></thead>
      <tbody>
        <?php foreach ($correlations as $correlation): ?>
          <tr>
            <td>Floor vs <?= e($correlation['label']) ?></td>
            <td class="num"><?= e($correlation['n']) ?></td>
            <td class="num">
              <?= $correlation['reportable'] && $correlation['stat']
                  ? e(($correlation['stat']['r'] > 0 ? '+' : '') . number_format($correlation['stat']['r'], 2))
                  : '—' ?>
            </td>
            <td class="num muted">
              <?= $correlation['reportable'] && $correlation['stat']
                  ? e(number_format($correlation['stat']['lo'], 2) . ' to ' . number_format($correlation['stat']['hi'], 2))
                  : '—' ?>
            </td>
            <td class="small">
              <?php if (!$correlation['reportable']): ?>
                <span class="muted">Withheld below n = <?= e($correlation['minimum']) ?></span>
              <?php elseif ($correlation['stat']['crosses']): ?>
                Interval includes zero — indistinguishable from no association
              <?php else: ?>
                Association, not an effect
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="page-break"></div>
    <h2>Appendix: records</h2>
    <p class="small muted"><?= e(count($records)) ?> scorecard<?= count($records) === 1 ? '' : 's' ?>.</p>
    <table>
      <thead>
        <tr><th>Board</th><th>Sector</th><th class="num">Year</th><th>Director</th><th>Seat</th>
            <th class="num">Level</th><th class="num">Floor</th><th>On</th><th class="num">Average</th></tr>
      </thead>
      <tbody>
        <?php foreach ($records as $record): ?>
          <tr>
            <td>
              <?= e($record['board'] ?? 'Anonymous') ?>
              <?php if ((int) $record['is_synthetic'] === 1): ?><span class="tag">syn</span><?php endif; ?>
            </td>
            <td class="small muted"><?= e($record['sector'] ?? '—') ?></td>
            <td class="num"><?= e($record['assessment_year']) ?></td>
            <td class="small"><?= e($record['director_name'] ?? '—') ?></td>
            <td class="small muted"><?= e($record['seat_role'] ?? '—') ?></td>
            <td class="num"><b><?= e($record['level_value'] ?? '—') ?></b></td>
            <td class="num"><?= e($fmt($record['floor_score'] === null ? null : (float) $record['floor_score'])) ?></td>
            <td class="mono small"><?= e($record['floor_code'] ?? '—') ?></td>
            <td class="num muted"><?= e($fmt($record['mean_score'] === null ? null : (float) $record['mean_score'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="caveats avoid-break">
      <h3 style="margin-top:0">How to read this</h3>
      <ul>
        <?php foreach ($caveats as $caveat): ?>
          <li><?= e($caveat) ?></li>
        <?php endforeach; ?>
        <li>
          A director's level is the band of their weakest scored competency. An
          unscored competency cannot set the level, so a partial scorecard reads
          better than it is.
        </li>
        <li>
          Band cut points are inclusive below and exclusive above: 2.75 is
          Practiced, not Aware.
        </li>
      </ul>
    </div>

  <?php endif; ?>

  <footer class="report">
    <?= e($instrument['name']) ?> v<?= e($instrument['version']) ?> ·
    <?= e($includeSynthetic ? 'Includes synthetic demo records' : 'Real records only') ?> ·
    Generated <?= e(date('Y-m-d H:i')) ?> ·
    <?= e(config('app.copyright')) ?>
  </footer>

</div>
</body>
</html>
