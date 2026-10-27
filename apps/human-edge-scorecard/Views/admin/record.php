<?php
/**
 * One scorecard as a page: the dialog's partial inside the admin shell.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="eyebrow"><a href="<?= e(url('admin/' . $slug . '/research-data')) ?>">Research data</a> &rsaquo; scorecard</div>
<div class="card" style="margin-top:12px">
  <?= $this->partial('admin/_record-modal', ['record' => $record, 'stats' => $stats, 'slug' => $slug, 'canExport' => $canExport]) ?>
</div>
<?php $this->stop(); ?>
