<?php
/**
 * One organization as a page: the dialog's partial inside the admin shell.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="eyebrow"><a href="<?= e(url('admin/' . $slug . '/analytics')) ?>">Analytics</a> &rsaquo; organization</div>
<div class="card" style="margin-top:12px">
  <?= $this->partial('admin/_organisation-modal', ['org' => $org, 'stats' => $stats, 'dims' => $dims, 'slug' => $slug]) ?>
</div>
<?php $this->stop(); ?>
