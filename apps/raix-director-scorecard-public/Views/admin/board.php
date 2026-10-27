<?php
/**
 * One board: every director who scored it.
 *
 * This is the view the platform-level `organisations` table exists to make
 * possible. It also answers the coverage question a board actually has — not
 * "what is our average" but "is there anyone here who can press this".
 *
 * @var \Platform\Core\View $this
 * @var array<string,mixed> $board
 * @var float $threshold
 */
$this->extend('layouts/admin');

$fmt  = static fn (?string $v): string => $v === null ? '—' : number_format((float) $v, 2);
$org  = $board['organisation'];
$dirs = $board['directors'];
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">
      <a href="<?= e(url('admin/' . $slug . '/analytics' . ($includeSynthetic ? '?synthetic=1' : ''))) ?>">← Analytics</a>
    </div>
    <h1 class="title">
      <?= e($org['name']) ?>
      <?php if ((int) $org['is_synthetic'] === 1): ?><span class="tag syn">synthetic</span><?php endif; ?>
    </h1>
    <p class="lede">
      <?= e(implode(' · ', array_filter([$org['sector'], $org['size_band']]))) ?>
      — <?= e(count($dirs)) ?> director<?= count($dirs) === 1 ? '' : 's' ?> scored.
    </p>
  </div>
</div>

<?php if ($dirs === []): ?>
  <div class="empty"><b>No scorecards</b>Nobody on this board has completed one.</div>
<?php else: ?>

  <h2 class="sub">Directors</h2>
  <table class="data" style="margin-bottom:26px">
    <thead>
      <tr><th>Director</th><th>Seat</th><th class="num">Year</th><th class="num">Level</th>
          <th class="num">Floor</th><th>On</th><th class="num">Average</th></tr>
    </thead>
    <tbody>
      <?php foreach ($dirs as $director): ?>
        <?php $href = url('admin/' . $slug . '/research-data/' . $director['id']); ?>
        <tr class="clickable" onclick="location.href=<?= e(json_encode($href, JSON_UNESCAPED_SLASHES)) ?>">
          <td>
            <?= e($director['director_name'] ?? 'Anonymous') ?>
            <?php if ((int) $director['is_synthetic'] === 1): ?><span class="tag syn">syn</span><?php endif; ?>
          </td>
          <td class="muted small"><?= e($director['seat_role'] ?? '—') ?></td>
          <td class="num"><?= e($director['assessment_year']) ?></td>
          <td class="num"><b><?= e($director['level_value'] ?? '—') ?></b></td>
          <td class="num"><?= e($fmt($director['floor_score'])) ?></td>
          <td><span class="pill"><?= e($director['floor_code'] ?? '—') ?></span></td>
          <td class="num muted"><?= e($fmt($director['mean_score'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2 class="sub">Where this board is covered</h2>
  <p class="muted small" style="margin-bottom:12px">
    How many of these directors reach Practiced (<?= e(number_format($threshold, 2)) ?>)
    on each competency. A competency nobody reaches is one this board cannot
    press, whatever its average says.
  </p>

  <table class="data">
    <thead>
      <tr><th>Competency</th><th class="num">Board mean</th>
          <th style="width:200px">Spread</th>
          <th class="num">At Practiced</th><th>Coverage</th></tr>
    </thead>
    <tbody>
      <?php foreach ($board['competencies'] as $competency): ?>
        <?php $covered = $competency['at_practiced']; ?>
        <tr>
          <td><span class="pill"><?= e($competency['code']) ?></span> <?= e($competency['name']) ?></td>
          <td class="num"><b><?= e($competency['mean'] === null ? '—' : number_format($competency['mean'], 2)) ?></b></td>
          <td>
            <?php if ($competency['mean'] !== null): ?>
              <div class="bar-track"><i style="width:<?= e(round((($competency['mean'] - 1) / 3) * 100, 1)) ?>%"></i></div>
            <?php endif; ?>
          </td>
          <td class="num"><?= e($covered) ?> of <?= e($competency['n']) ?></td>
          <td class="small">
            <?php if ($competency['n'] === 0): ?>
              <span class="muted">not scored</span>
            <?php elseif ($covered === 0): ?>
              <span style="color:var(--r1)">Nobody</span>
            <?php elseif ($covered === 1): ?>
              <span style="color:var(--r2)">One person only</span>
            <?php else: ?>
              <span style="color:var(--r4)">Covered</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="hint" style="margin-top:10px">
    “One person only” is worth noticing: a competency that rests on a single
    director leaves with them.
  </p>
<?php endif; ?>
<?php $this->stop(); ?>
