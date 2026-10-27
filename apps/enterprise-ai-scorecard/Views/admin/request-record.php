<?php
/**
 * One scorecard, for a request from the person who completed it: export,
 * correct, restrict, delete. Only real records reach this screen.
 *
 * @var \Platform\Core\View $this
 * @var string $slug
 * @var string $csrf
 * @var array<string, mixed> $row
 * @var ?object $record
 * @var string $pretty
 * @var array<string, string> $errors keyed by form: identity, json, delete
 * @var ?string $notice
 * @var string $base
 */

use Apps\EnterpriseAiScorecard\Services\RecordStore;

$this->extend('layouts/admin');

$org  = is_object($record->org ?? null) ? $record->org : new stdClass();
$id   = (string) $row['id'];
$href = static fn (string $suffix = ''): string => url($base . '/' . rawurlencode($id) . $suffix);
$date = static fn (?string $d): string => $d === null ? '—' : date((string) config('app.date_format'), strtotime($d . ' UTC'));
$restricted = (int) $row['restricted'] === 1;
$err = static fn (string $form): string => isset($errors[$form]) ? '<div class="err" role="alert" style="margin-top:8px">' . e($errors[$form]) . '</div>' : '';
$token = '<input type="hidden" name="_token" value="' . e($csrf ?? '') . '">';
?>

<?php $this->start('content'); ?>
<?= $this->partial('admin/_requests-fill') ?>
<div class="rq-page" hidden></div>
<div class="admin-head">
  <div>
    <div class="eyebrow"><a href="<?= e(url($base)) ?>">← Requests</a></div>
    <h1 class="title"><?= e((string) ($record->userName ?? $org->name ?? 'Respondent')) ?>
      <?php if ($restricted): ?><span class="tag gold">Restricted</span><?php endif; ?></h1>
    <p class="lede mono small"><?= e($id) ?> · completed <?= e($date($row['completed_at'])) ?> · deleted automatically by <?= e($date(RecordStore::deleteBy((string) $row['completed_at']))) ?>
      · notice <?= e((string) ($org->consent->notice ?? '—')) ?></p>
  </div>
</div>

<?php if ($notice !== null): ?>
  <div class="notice" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<div class="toolbar" style="gap:10px;margin-bottom:26px">
  <a class="btn quiet sm" href="<?= e($href('/export')) ?>">Export as JSON</a>
  <form method="post" action="<?= e($href('/restrict')) ?>" style="display:inline">
    <?= $token ?>
    <input type="hidden" name="restricted" value="<?= $restricted ? '0' : '1' ?>">
    <button class="btn quiet sm" type="submit"><?= $restricted ? 'Lift restriction' : 'Restrict use' ?></button>
  </form>
</div>

<h3 class="sub">Correct contact details</h3>
<p class="muted small" style="margin-bottom:12px">Each is stored twice in the record (at the top and inside <span class="mono">org</span>); both copies change together.</p>
<form method="post" action="<?= e($href('/identity')) ?>" class="form" style="margin-bottom:10px">
  <?= $token ?>
  <div><label class="f" for="c-name">Full name</label><input class="f" id="c-name" name="name" value="<?= e((string) ($record->userName ?? $org->name ?? '')) ?>" required></div>
  <div><label class="f" for="c-email">Email</label><input class="f" id="c-email" name="email" type="email" value="<?= e((string) ($record->userEmail ?? $org->email ?? '')) ?>" required></div>
  <div><label class="f" for="c-phone">Phone</label><input class="f" id="c-phone" name="phone" value="<?= e((string) ($record->userPhone ?? $org->phone ?? '')) ?>"></div>
  <div><label class="f" for="c-org">Organization</label><input class="f" id="c-org" name="organization" value="<?= e((string) ($org->org ?? '')) ?>"></div>
  <div class="full"><button class="btn gold sm" type="submit">Save corrections</button><?= $err('identity') ?></div>
</form>

<hr class="rule">
<h3 class="sub">Correct any other field</h3>
<p class="muted small" style="margin-bottom:10px">The whole record as stored. It must stay one JSON object with the same id, <span class="mono">synthetic: false</span> and research consent.</p>
<form method="post" action="<?= e($href('/json')) ?>">
  <?= $token ?>
  <textarea class="f mono" name="payload" rows="18" spellcheck="false" style="width:100%;font-size:12.5px;line-height:1.45"><?= e($pretty) ?></textarea>
  <div style="margin-top:10px"><button class="btn quiet sm" type="submit">Save record</button><?= $err('json') ?></div>
</form>

<hr class="rule">
<h3 class="sub">Delete</h3>
<p class="muted small" style="margin-bottom:10px">Removes the whole record. Use this for a deletion request and when consent is withdrawn. It cannot be undone.</p>
<form method="post" action="<?= e($href('/delete')) ?>" class="form">
  <?= $token ?>
  <div><label class="f" for="d-reason">Reason</label>
    <select class="f no-select2" id="d-reason" name="reason"><option value="deletion">Deletion request</option><option value="withdrawal">Consent withdrawn</option></select></div>
  <div><label class="f" for="d-confirm">Type DELETE to confirm</label><input class="f" id="d-confirm" name="confirm" autocomplete="off"></div>
  <div class="full"><button class="btn sm danger" type="submit">Delete record</button><?= $err('delete') ?></div>
</form>
<?php $this->stop(); ?>
