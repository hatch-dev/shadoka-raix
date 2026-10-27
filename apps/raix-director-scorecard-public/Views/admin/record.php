<?php
/**
 * One scorecard, down to the individual marks.
 *
 * The item-level view is what makes this a research record rather than a score:
 * a competency mean of 3.00 can be five 3s or a 1 and four 3.5s, and only this
 * page can tell you which.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$fmt  = static fn (?string $v): string => $v === null ? '—' : number_format((float) $v, 2);
$date = static fn (?string $d): string => $d === null
    ? '—'
    : date((string) config('app.date_format'), strtotime($d));

$markColours = [1 => 'var(--r1)', 2 => 'var(--r2)', 3 => 'var(--r3)', 4 => 'var(--r4)'];
$currentCode = null;
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">
      <a href="<?= e(url('admin/' . $slug . '/research-data')) ?>">← Research data</a>
    </div>
    <h1 class="title">
      <?= e($result['board'] ?? 'Anonymous') ?>
      <?php if ((int) $submission['is_synthetic'] === 1): ?>
        <span class="tag syn">synthetic</span>
      <?php endif; ?>
    </h1>
    <p class="lede">
      <?= e(implode(' · ', array_filter([
          $submission['director_name'],
          $submission['seat_role'],
          $submission['tenure'],
          $submission['assessment_year'],
      ]))) ?>
    </p>
    <?php /* The public edition collects contact details under a consent gate;
             both are shown to whoever may read the record. */ ?>
    <p class="small muted" style="margin-top:6px">
      <?= e(implode(' · ', array_filter([
          $submission['director_email'] ?? null,
          $submission['director_phone'] ?? null,
          $submission['consent_agreed_at'] ?? null
              ? 'Terms accepted ' . date((string) config('app.date_format'), strtotime((string) $submission['consent_agreed_at']))
              : ((int) $submission['is_synthetic'] === 1 ? 'Synthetic: no consent recorded' : 'No consent recorded'),
      ]))) ?>
    </p>
  </div>

  <?php if ($canDelete): ?>
    <form method="post"
          action="<?= e(url('admin/' . $slug . '/research-data/' . $submission['id'] . '/delete')) ?>"
          onsubmit="return confirm('Delete this scorecard permanently? This cannot be undone.')">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <button class="btn sm danger" type="submit">Delete scorecard</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($result === null): ?>
  <div class="notice">
    <b>This scorecard is still in progress.</b>
    It has not been submitted, so it has no score and appears in no analytics.
  </div>
<?php else: ?>

  <div class="grid grid-4" style="margin-bottom:24px">
    <div class="stat" style="--c:<?= e($result['level']['colour'] ?? 'var(--stone)') ?>">
      <div class="v" style="color:var(--c)"><?= e($result['level']['value'] ?? '—') ?></div>
      <div class="k">Level, <?= e(strtolower($result['level']['name'] ?? 'unscored')) ?></div>
    </div>
    <div class="stat">
      <div class="v"><?= e($fmt($submission['floor_score'])) ?></div>
      <div class="k">Floor</div>
    </div>
    <div class="stat">
      <div class="v"><?= e($fmt($submission['mean_score'])) ?></div>
      <div class="k">Unweighted average</div>
    </div>
    <div class="stat">
      <div class="v"><?= e($result['spread'] === null ? '—' : '+' . number_format($result['spread'], 2)) ?></div>
      <div class="k">Average above floor</div>
    </div>
  </div>

  <h2 class="sub">Competencies</h2>
  <table class="data" style="margin-bottom:26px">
    <thead>
      <tr><th>Competency</th><th class="num">Score</th><th style="width:220px">Level</th><th>Band</th></tr>
    </thead>
    <tbody>
      <?php foreach ($result['competencies'] as $competency): ?>
        <tr>
          <td>
            <span class="pill"><?= e($competency['code']) ?></span>
            <?= e($competency['name']) ?>
            <?php if ($competency['is_floor']): ?><span class="tag gold">Floor</span><?php endif; ?>
          </td>
          <td class="num"><b><?= e($competency['score'] === null ? '—' : number_format($competency['score'], 2)) ?></b></td>
          <td>
            <?php if ($competency['score'] !== null): ?>
              <div class="bar-track" style="--c:<?= e($competency['rung']['colour'] ?? 'var(--blue)') ?>">
                <i style="width:<?= e(round((($competency['score'] - 1) / 3) * 100, 1)) ?>%"></i>
              </div>
            <?php endif; ?>
          </td>
          <td class="muted small"><?= e($competency['rung']['name'] ?? 'Not scored') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2 class="sub">Every mark</h2>
<table class="data">
  <thead>
    <tr><th style="width:70px">Item</th><th>Statement</th><th class="num" style="width:70px">Mark</th><th style="width:260px">When and where</th></tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <?php if ($item['code'] !== $currentCode): ?>
        <?php $currentCode = $item['code']; ?>
        <tr><td colspan="4" style="background:rgba(61,130,196,.07);font-weight:600;font-size:12.5px">
          <?= e($item['code']) ?>
        </td></tr>
      <?php endif; ?>
      <tr>
        <td class="mono small muted"><?= e($item['code']) ?> <?= e($item['i_pos']) ?></td>
        <td class="small"><?= e($item['statement']) ?></td>
        <td class="num">
          <?php if ($item['mark'] === null): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <b style="color:<?= e($markColours[(int) $item['mark']] ?? 'var(--paper)') ?>"><?= e($item['mark']) ?></b>
          <?php endif; ?>
        </td>
        <td class="muted small"><?= e($item['evidence_note'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p class="hint" style="margin-top:10px">
  “When and where” is free text the director offered voluntarily. It is never
  verified, and an empty cell means only that they did not place the act.
</p>

<hr class="rule">

<h2 class="sub">Context they reported</h2>
<table class="data" style="max-width:520px">
  <?php /* The director's own answer first; the board's row is the fallback. */ ?>
  <tr><td>Sector</td><td class="num">
    <?= e($submission['sector'] ?? $organisation['sector'] ?? '—') ?></td></tr>
  <tr><td>Company size</td><td class="num">
    <?= e($submission['company_size'] ?? $organisation['size_band'] ?? '—') ?></td></tr>
  <tr><td>Seat</td><td class="num"><?= e($submission['seat_role'] ?? '—') ?></td></tr>
  <tr><td>Tenure</td><td class="num"><?= e($submission['tenure'] ?? '—') ?></td></tr>
  <tr><td>AI systems they are aware of</td><td class="num"><?= e($submission['systems_seen'] ?? '—') ?></td></tr>
  <tr><td>Directors on the board</td><td class="num"><?= e($submission['board_seats'] ?? '—') ?></td></tr>
  <tr><td>Meetings with AI as a decision item</td><td class="num"><?= e($submission['agenda_meetings'] ?? '—') ?></td></tr>
  <tr><td>Hours of AI education</td><td class="num"><?= e($submission['education_hours'] ?? '—') ?></td></tr>
  <tr><td>Committee remit</td><td class="num"><?= e($submission['remit'] ? implode(', ', (array) json_decode((string) $submission['remit'], true)) : '—') ?></td></tr>
  <tr><td>Submitted</td><td class="num"><?= e($date($submission['submitted_at'])) ?></td></tr>
  <?php
  /*
   * Distinct from Submitted: the director pressed Save on their own result
   * rather than only reaching the page. Not a quality mark and nothing filters
   * on it — an unaccepted scorecard scores, exports and benchmarks the same.
   */
  ?>
  <tr>
    <td>Accepted by the director</td>
    <td class="num">
      <?= $submission['confirmed_at'] ? e($date($submission['confirmed_at'])) : 'Not accepted' ?>
    </td>
  </tr>
</table>
<?php $this->stop(); ?>
