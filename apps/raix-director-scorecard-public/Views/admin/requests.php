<?php
/**
 * Requests from individuals — find a person's scorecards by email address.
 *
 * Also the console's landing screen, so it says where the rest of the admin
 * lives now: analytics, research data, exports and demo data are the v13
 * page's own admin views, which read through this session.
 *
 * @var \Platform\Core\View $this
 * @var string $slug
 * @var string $email
 * @var ?list<array<string, mixed>> $results
 * @var ?string $notice
 * @var string $pageUrl
 * @var string $noticeVersion
 */
$this->extend('layouts/admin');

$date = static fn (?string $d): string => $d === null ? '—' : date((string) config('app.date_format'), strtotime($d . ' UTC'));
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Privacy</div>
    <h1 class="title">Requests from individuals</h1>
    <p class="lede">Find a director's scorecards by the email address they gave, then export, correct, restrict or delete one. Withdrawing consent deletes the record. Every request must be finished within one month.</p>
  </div>
</div>

<?php if ($notice !== null): ?>
  <div class="notice" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<?php /* POST, never GET: an address in a URL ends up in the access log (C6). */ ?>
<form method="post" action="<?= e(url('admin/' . $slug . '/requests')) ?>" class="toolbar" style="gap:10px;align-items:flex-end;margin-bottom:22px">
  <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">
  <div style="flex:1;min-width:240px">
    <label class="f" for="rq-email">Email address</label>
    <input class="f" id="rq-email" name="email" type="email" value="<?= e($email) ?>" autocomplete="off" required>
  </div>
  <button class="btn gold sm" type="submit">Find</button>
</form>

<?php if ($results !== null): ?>
  <?php if ($results === []): ?>
    <div class="empty"><b>No scorecards for that address</b>The search is exact and ignores case. It covers real scorecards only.</div>
  <?php else: ?>
    <table class="data sans-num">
      <thead><tr><th>Record</th><th>Board</th><th>Completed</th><th>Delete by</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($results as $row):
          $rec = \Apps\RaixDirectorScorecardPublic\Services\RecordStore::decodeObject((string) $row['payload']);
          $board = is_object($rec->org ?? null) ? trim((string) ($rec->org->org ?? '')) : '';
          $deleteBy = date('Y-m-d H:i:s', strtotime($row['completed_at'] . ' UTC +' . \Apps\RaixDirectorScorecardPublic\Services\RecordStore::RETENTION_MONTHS . ' months'));
      ?>
        <tr class="clickable">
          <td><a class="mono" href="<?= e(url('admin/' . $slug . '/requests/' . rawurlencode((string) $row['id']))) ?>"><?= e($row['id']) ?></a></td>
          <td><?= $board === '' ? '<span class="muted">Board not given</span>' : e($board) ?></td>
          <td><?= e($date($row['completed_at'])) ?></td>
          <td><?= e($date($deleteBy)) ?></td>
          <td><?= (int) $row['restricted'] === 1 ? '<span class="tag gold">Restricted</span>' : '<span class="muted">Active</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>

<hr class="rule" style="margin-top:34px">
<h3 class="sub">Analytics, research data and demo data</h3>
<p class="muted small">
  These are inside the scorecard page itself: open
  <a href="<?= e($pageUrl) ?>" target="_blank" rel="noopener">the scorecard</a> and choose Admin. Its dialog takes
  this same admin password and signs in here too, so either way in opens both; Exit on either signs out of both.
  Restricted records never appear there. Privacy notice in force: <b><?= e($noticeVersion) ?></b>.
</p>
<?php $this->stop(); ?>
