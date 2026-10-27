<?php
/**
 * Requests from individuals — find a person's scorecards by email address
 * (directive v6, C4). Searches sov_records, the table the v6 page saves to.
 *
 * @var \Platform\Core\View $this
 * @var string $slug
 * @var string $email
 * @var ?list<array<string, mixed>> $results
 * @var ?string $notice
 */

use Apps\AiSovereigntyScorecard\Services\RecordStore;

$this->extend('layouts/admin');

$date = static fn (?string $d): string => $d === null ? '—' : date((string) config('app.date_format'), strtotime($d . ' UTC'));
?>

<?php $this->start('content'); ?>
<?= $this->partial('admin/_requests-fill') ?>
<div class="rq-page" hidden></div>
<div class="admin-head">
  <div>
    <div class="eyebrow">Privacy</div>
    <h1 class="title">Requests from individuals</h1>
    <p class="lede">Find a person's scorecards by the email address they gave, then export, correct, restrict or delete one. Withdrawing consent deletes the record. Every request must be finished within one month.</p>
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
      <thead><tr><th>Record</th><th>Organization</th><th>Completed</th><th>Delete by</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($results as $row):
          $rec = RecordStore::decodeObject((string) $row['payload']);
          $organization = is_object($rec->org ?? null) ? trim((string) ($rec->org->org ?? '')) : '';
      ?>
        <tr class="clickable">
          <td><a class="mono" href="<?= e(url('admin/' . $slug . '/requests/' . rawurlencode((string) $row['id']))) ?>"><?= e($row['id']) ?></a></td>
          <td><?= $organization === '' ? '<span class="muted">Organization not given</span>' : e($organization) ?></td>
          <td><?= e($date($row['completed_at'])) ?></td>
          <td><?= e($date(RecordStore::deleteBy((string) $row['completed_at']))) ?></td>
          <td><?= (int) $row['restricted'] === 1 ? '<span class="tag gold">Restricted</span>' : '<span class="muted">Active</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
  /* A click anywhere on a result row opens that record. The row's first cell
     keeps a real link, so ctrl-click, middle-click and the keyboard still work
     through it; a click on the link itself is left to the browser. */
  document.addEventListener('click', function (event) {
    var row = event.target.closest('table.data tr.clickable');
    if (!row || event.target.closest('a,button,input,select,textarea,label')) return;
    if (window.getSelection && String(window.getSelection()) !== '') return; // selecting text, not opening
    var link = row.querySelector('a[href]');
    if (!link) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey) { window.open(link.href, '_blank'); return; }
    location.href = link.href;
  });
</script>
<?php $this->stop(); ?>
