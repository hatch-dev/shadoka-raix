<?php
/**
 * Generate and remove demo data.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$date = static fn (?string $d): string => $d === null ? '—' : date('M j, Y H:i', strtotime($d));
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">The AI Sovereignty Scorecard</div>
    <h1 class="title">Data seeder</h1>
    <p class="lede">
      Generates flagged demo scorecards so the analytics have something in them
      before real organizations have scored. Everything it writes is marked
      <span class="tag syn">synthetic</span> and can be removed in full.
    </p>
  </div>
</div>

<?php if ($purged > 0): ?>
  <div class="notice"><b><?= e($purged) ?> demo scorecards removed.</b>
    Real submissions were not touched.</div>
<?php elseif ($error !== ''): ?>
  <div class="err" role="alert"><?= e($error) ?></div>
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
  It does not invent the two figures a respondent reports about their own
  organization &mdash; executive meetings with AI as a decision item, and hours of
  AI education &mdash; and it records no consent, because nobody consented to
  anything. Deriving those figures from a record&rsquo;s own score is how a demo
  dataset comes to report a relationship the generator manufactured; the
  correlation pane excludes synthetic records regardless. The AI systems count IS
  generated, because it is a fact about the organization rather than an axis of
  any analysis. Every generated address is on a <span class="mono">.invalid</span>
  domain and every phone number is in the fictional 555-01xx range, so a seeded
  row can never be mistaken for a contactable person.
</div>

<div class="card" style="margin-bottom:22px">
  <?= $this->partial('admin/_seeder-form', [
      'slug'       => $slug,
      'csrf'       => $csrf,
      'year'       => $year,
      'error'      => $error,
      'archetypes' => $archetypes,
  ]) ?>
</div>

<hr class="rule">

<h2 class="sub">Previous runs</h2>
<?php if ($runs === []): ?>
  <div class="empty"><b>Nothing generated yet</b>Each run is recorded here with its seed,
    so any batch can be identified and removed in full.</div>
<?php else: ?>
  <table class="data">
    <thead>
      <tr>
        <th>When</th><th>By</th><th class="num">Seed</th><th class="num">Records</th>
        <th class="num">Organizations</th><th></th>
      </tr>
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
              <button class="btn sm" type="submit" data-busy="Removing…">Remove batch</button>
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
      <button class="btn danger" type="submit" data-busy="Removing…">
        Remove all <?= e($synthetic) ?> synthetic records
      </button>
    </form>
    <p class="hint" style="margin-top:8px">Real submissions are never touched by this.</p>
  </div>
<?php endif; ?>
<?php $this->stop(); ?>
