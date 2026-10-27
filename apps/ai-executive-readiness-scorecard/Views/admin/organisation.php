<?php
/**
 * One organization as a page — what its link opens in a new tab or without
 * scripting. The same partial as the dialog.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="eyebrow"><a href="<?= e(url('admin/' . $slug . '/analytics')) ?>">Analytics</a> &rsaquo; organization</div>

<div class="card" style="margin-top:12px">
  <?= $this->partial('admin/_organisation-modal', [
      'name'        => $name,
      'recs'        => $recs,
      'description' => $description,
      'respondents' => $respondents,
      'scores'      => $scores,
      'spread'      => $spread,
      'dimensions'  => $dimensions,
      'bander'      => $bander,
      'slug'        => $slug,
      'query'       => $query,
  ]) ?>
</div>
<?php $this->stop(); ?>
