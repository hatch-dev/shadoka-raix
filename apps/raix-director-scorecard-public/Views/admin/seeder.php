<?php
/**
 * Generate and remove demo data.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$date = static fn (?string $d): string => $d === null
    ? '—'
    : date('M j, Y H:i', strtotime($d));
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Director Scorecard</div>
    <h1 class="title">Data seeder</h1>
    <p class="lede">
      Generates flagged demo scorecards so the analytics have something in them
      before real directors have scored. Everything it writes is marked
      <span class="tag syn">synthetic</span> and can be removed in full.
    </p>
  </div>
</div>

<?php if (!empty($_GET['generated'])): ?>
  <div class="notice"><b><?= e((int) $_GET['generated']) ?> demo scorecards generated.</b>
    They are flagged synthetic and excluded from analytics unless you opt in.</div>
<?php elseif (isset($_GET['purged'])): ?>
  <div class="notice"><b><?= e((int) $_GET['purged']) ?> demo scorecards removed.</b>
    Real submissions were not touched.</div>
<?php elseif (!empty($_GET['error'])): ?>
  <div class="err" role="alert"><?= e((string) $_GET['error']) ?></div>
<?php endif; ?>

<div class="grid grid-2" style="margin-bottom:24px">
  <div class="stat"><div class="v"><?= e($real) ?></div><div class="k">Real scorecards</div></div>
  <div class="stat" style="--c:var(--r1)">
    <div class="v" style="color:var(--c)"><?= e($synthetic) ?></div>
    <div class="k">Synthetic demo records</div>
  </div>
</div>

<div class="notice">
  <b>What this generator will not do.</b>
  It does not invent the figures directors report about their own board —
  meetings with AI on the agenda, hours of education, board size are all left
  empty. The previous generator derived those from each record's own score,
  which made the correlation screen report a relationship it had manufactured.
  Correlations exclude synthetic records regardless.
</div>

<div class="card" style="margin-bottom:22px">
  <?= $this->partial('admin/_seeder-form', [
      'slug' => $slug, 'csrf' => $csrf, 'year' => $year,
      'error' => $error, 'archetypes' => $archetypes,
  ]) ?>
</div>

<hr class="rule">

<h2 class="sub">Previous runs</h2>
<?php if ($runs === []): ?>
  <div class="empty"><b>Nothing generated yet</b>Each run is recorded here with its seed.</div>
<?php else: ?>
  <table class="data">
    <thead>
      <tr><th>When</th><th>By</th><th class="num">Seed</th><th class="num">Records</th>
          <th class="num">Boards</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($runs as $run): ?>
        <tr>
          <td class="muted small"><?= e($date($run['created_at'])) ?></td>
          <td class="muted small"><?= e($run['created_by_name'] ?? 'system') ?></td>
          <td class="num mono small"><?= e($run['seed']) ?></td>
          <td class="num"><?= e($run['records_created']) ?></td>
          <td class="num"><?= e($run['organisations_created']) ?></td>
          <td style="text-align:right">
            <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder/purge')) ?>"
                  onsubmit="return confirm('Remove this batch of demo records?')">
              <input type="hidden" name="_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="seed_run_id" value="<?= e($run['id']) ?>">
              <button class="btn sm" type="submit">Remove batch</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if ($synthetic > 0): ?>
  <div style="margin-top:20px">
    <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder/purge')) ?>"
          onsubmit="return confirm('Remove ALL <?= e($synthetic) ?> synthetic records? Real submissions are not touched.')">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <button class="btn danger" type="submit">Remove all <?= e($synthetic) ?> synthetic records</button>
    </form>
    <p class="hint" style="margin-top:8px">Real submissions are never touched by this.</p>
  </div>
<?php endif; ?>
<?php $this->stop(); ?>
