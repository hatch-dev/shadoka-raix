<?php
/**
 * Admin overview.
 *
 * @var \Platform\Core\View $this
 * @var list<array<string,mixed>> $apps
 * @var int $organisations
 * @var list<array<string,mixed>> $recent
 */
$this->extend('layouts/admin');

$date = static fn (?string $d): string => $d === null
    ? '—'
    : date((string) config('app.date_format'), strtotime($d));
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Overview</div>
    <?php /* Not a greeting: "Good to see you, RAI-X" reads oddly for the
             system account, and this page is a working surface, not a welcome. */ ?>
    <h1 class="title">Overview</h1>
    <p class="lede">What the platform is holding, across every app you can see.</p>
  </div>
</div>

<div class="grid grid-3" style="margin-bottom:26px">
  <div class="stat">
    <div class="v"><?= e(array_sum(array_column($apps, 'real'))) ?></div>
    <div class="k">Completed scorecards, real</div>
  </div>
  <div class="stat">
    <div class="v"><?= e($organisations) ?></div>
    <div class="k">Boards on record</div>
  </div>
  <div class="stat">
    <div class="v"><?= e(count($apps)) ?></div>
    <div class="k">Apps you can see</div>
  </div>
</div>

<h2 class="sub">Apps</h2>
<div class="grid grid-2" style="margin-bottom:28px">
  <?php foreach ($apps as $app): ?>
    <div class="card">
      <h3><?= e($app['name']) ?></h3>
      <div class="cap"><span class="pill"><?= e($app['slug']) ?></span></div>

      <?php if (!$app['installed']): ?>
        <p class="muted small">
          Scaffolded but not migrated. Its tables do not exist yet, so nothing
          can be recorded against it.
        </p>
      <?php else: ?>
        <table class="data" style="margin-top:4px">
          <tr>
            <td>Completed, real</td>
            <td class="num"><?= e($app['real']) ?></td>
          </tr>
          <tr>
            <td>In progress</td>
            <td class="num"><?= e($app['draft']) ?></td>
          </tr>
          <tr>
            <td>Synthetic <span class="tag syn">demo</span></td>
            <td class="num"><?= e($app['synthetic']) ?></td>
          </tr>
          <tr>
            <td>Most recent</td>
            <td class="num"><?= e($date($app['latest'])) ?></td>
          </tr>
        </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<h2 class="sub">Recent activity</h2>
<?php if ($recent === []): ?>
  <div class="empty"><b>Nothing logged yet</b>Admin actions appear here as they happen.</div>
<?php else: ?>
  <table class="data">
    <thead>
      <tr><th>When</th><th>Who</th><th>Action</th><th>App</th></tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $entry): ?>
        <tr>
          <td class="muted small"><?= e(date('M j, H:i', strtotime((string) $entry['created_at']))) ?></td>
          <td><?= e($entry['actor_label']) ?></td>
          <td class="mono small"><?= e($entry['action']) ?></td>
          <td class="muted small"><?= e($entry['app'] ?? 'platform') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint" style="margin-top:10px">
    Research data that anyone can quietly edit is not research data. Every admin
    write is recorded here.
  </p>
<?php endif; ?>
<?php $this->stop(); ?>
