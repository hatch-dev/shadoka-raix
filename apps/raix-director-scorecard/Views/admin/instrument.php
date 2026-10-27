<?php
/**
 * The published instrument.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$date = static fn (?string $d): string => $d === null
    ? '—'
    : date((string) config('app.date_format'), strtotime($d));
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Director Scorecard</div>
    <h1 class="title">The instrument</h1>
    <?php if ($instrument !== null): ?>
      <p class="lede">
        Version <?= e($instrument['version']) ?>,
        <?= e($instrument['status']) ?><?= $instrument['published_at'] ? ' ' . e($date($instrument['published_at'])) : '' ?>.
        <?= e($instrument['rule_text'] ?? '') ?>
      </p>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?php if (count($versions) > 1): ?>
      <form method="get">
        <label class="f" for="version">Version</label>
        <select class="f" id="version" name="version" onchange="this.form.submit()">
          <?php foreach ($versions as $version): ?>
            <option value="<?= e($version['id']) ?>"<?= (int) $version['id'] === (int) $instrument['id'] ? ' selected' : '' ?>>
              v<?= e($version['version']) ?> · <?= e($version['status']) ?>
              <?= $version['submissions'] > 0 ? '(' . e($version['submissions']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>

    <?php if ($canEdit && $instrument !== null): ?>
      <?php if ($draft !== null): ?>
        <a class="btn sm gold" href="<?= e(url('admin/' . $slug . '/instrument/' . $draft['id'] . '/edit')) ?>">
          Continue draft v<?= e($draft['version']) ?>
        </a>
      <?php else: ?>
        <form method="post" action="<?= e(url('admin/' . $slug . '/instrument/draft')) ?>"
              style="display:flex;gap:8px;align-items:flex-end">
          <input type="hidden" name="_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="source_id" value="<?= e($instrument['id']) ?>">
          <div>
            <label class="f" for="new-version">New version</label>
            <input class="f" id="new-version" name="version" value="<?= e($nextVersion ?? '') ?>"
                   maxlength="20" style="width:110px" required>
          </div>
          <button class="btn sm gold" type="submit">Create draft</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($flash)): ?>
  <div class="notice"><?= e((string) $flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="err" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<?php if ($instrument === null): ?>
  <div class="empty">
    <b>No instrument</b>
    Run <code>php scripts/seed.php</code> to load one.
  </div>
<?php else: ?>

  <?php if ($instrument['status'] === 'draft'): ?>
    <div class="notice">
      <b>This is a draft.</b>
      It affects no score until it is published. Publishing archives the current
      version rather than replacing it.
      <?php if ($canEdit): ?>
        <a href="<?= e(url('admin/' . $slug . '/instrument/' . $instrument['id'] . '/edit')) ?>">Edit it →</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="notice">
      <b><?= e(ucfirst((string) $instrument['status'])) ?> versions cannot be edited.</b>
      <?= e($usage) ?> scorecard<?= $usage === 1 ? ' is' : 's are' ?> pinned to this
      version. Changing the wording of an item in place would silently rewrite the
      meaning of every score already taken against it, so a change means drafting
      a new version and leaving this one as it stands.
    </div>
  <?php endif; ?>

  <h2 class="sub">The ladder</h2>
  <table class="data" style="margin-bottom:26px;max-width:720px">
    <thead>
      <tr><th class="num" style="width:60px">Level</th><th>Name</th><th>Means</th><th class="num">Band</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rungs as $rung): ?>
        <tr>
          <td class="num"><b style="color:<?= e($rung['colour_hex']) ?>"><?= e($rung['value']) ?></b></td>
          <td><?= e($rung['name']) ?></td>
          <td class="muted"><?= e($rung['description']) ?></td>
          <td class="num mono small">
            <?= $rung['band_min'] === null ? '&lt;' : e(number_format((float) $rung['band_min'], 2)) ?>
            <?= $rung['band_min'] !== null && $rung['band_max'] !== null ? '–' : '' ?>
            <?= $rung['band_max'] === null ? '+' : ($rung['band_min'] === null ? e(number_format((float) $rung['band_max'], 2)) : e(number_format((float) $rung['band_max'], 2))) ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint" style="margin:-16px 0 26px">
    Bands are inclusive below, exclusive above, so a score exactly on a cut point
    lands in the higher band: 2.75 is Practiced, not Aware.
  </p>

  <h2 class="sub">
    <?= e(count($competencies)) ?> competencies,
    <?= e(array_sum(array_map(static fn (array $c): int => count($c['items']), $competencies))) ?> statements
  </h2>

  <?php foreach ($competencies as $competency): ?>
    <div class="card" style="margin-bottom:14px">
      <h3><span class="pill"><?= e($competency['code']) ?></span> <?= e($competency['name']) ?></h3>
      <div class="cap"><?= e($competency['description']) ?></div>

      <p class="small" style="margin-bottom:6px">
        <b style="color:var(--gold)">Board asks:</b> “<?= e($competency['board_question']) ?>”
      </p>
      <p class="small muted" style="margin-bottom:12px">
        <b>Failing looks like:</b> <?= e($competency['failure_line']) ?>
      </p>

      <ol style="padding-left:20px;font-size:13.5px;line-height:1.7;color:var(--stone)">
        <?php foreach ($competency['items'] as $item): ?>
          <li><?= e($item['statement']) ?></li>
        <?php endforeach; ?>
      </ol>
    </div>
  <?php endforeach; ?>

<?php endif; ?>
<?php $this->stop(); ?>
