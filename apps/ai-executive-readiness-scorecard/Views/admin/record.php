<?php
/**
 * One questionnaire as a page — what the record dialog's link opens in a new
 * tab or without scripting. The same partial as the dialog, plus the delete.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="eyebrow"><a href="<?= e(url('admin/' . $slug . '/research-data')) ?>">Research data</a> &rsaquo; questionnaire</div>

<div class="card" style="margin-top:12px">
  <?= $this->partial('admin/_record-modal', [
      'record'    => $record,
      'result'    => $result,
      'bander'    => $bander,
      'slug'      => $slug,
      'canExport' => $canExport,
  ]) ?>
</div>

<?php if ($canDelete): ?>
  <?php /* Audited before it happens; the email is kept in the audit row, because a
           deletion request under the privacy notice arrives as an address. */ ?>
  <form method="post" action="<?= e(url('admin/' . $slug . '/research-data/' . $record['_id'] . '/delete')) ?>"
        onsubmit="return confirm('Delete this questionnaire? This cannot be undone.')" style="margin-top:22px">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <button class="btn quiet sm" type="submit" style="border-color:var(--r1);color:var(--r1)">Delete this questionnaire</button>
  </form>
<?php endif; ?>
<?php $this->stop(); ?>
