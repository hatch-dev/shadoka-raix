<?php
/**
 * The admin console, as a page.
 *
 * The Research data screen opens this as a dialog, which is how the reference
 * presents it. This is the same partial with the shell around it: what a
 * browser without scripting gets, and what the dialog's own link points at.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Research data</div>
    <h1 class="title">Console</h1>
    <p class="lede">
      The dataset's own tools: generate or remove the synthetic demo records,
      and download the corpus. Real scorecards are never touched by anything on
      the Data tab.
    </p>
  </div>
</div>

<?php /* Set only by a refused wipe: the POST is a navigation, so it lands
         here rather than back in the dialog the button was pressed in. */ ?>
<?php if ($error !== ''): ?>
  <div class="err" role="alert" style="margin-bottom:18px"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <?= $this->partial('admin/_console', [
      'slug'             => $slug,
      'tab'              => $tab,
      'counts'           => $counts,
      'csrf'             => $csrf,
      'includeSynthetic' => $includeSynthetic,
      'canExport'        => $canExport,
      'canSeed'          => $canSeed,
      'canWipe'          => $canWipe,
      'wipePhrase'       => $wipePhrase,
  ]) ?>
</div>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script src="<?= e(app_asset($slug, 'js/report-pdf.js')) ?>"></script>
<?php $this->stop(); ?>
