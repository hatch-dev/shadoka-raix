<?php
/**
 * The admin console, as a page: the same partial the dialog shows, with the
 * shell around it — what a browser without scripting gets.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="card">
  <?= $this->partial('admin/_console', [
      'slug'             => $slug,
      'tab'              => $tab,
      'counts'           => $counts,
      'csrf'             => $csrf,
      'includeSynthetic' => $includeSynthetic,
      'stats'            => $stats,
      'canExport'        => $canExport,
      'canSeed'          => $canSeed,
      'latest'           => $latest,
      'latestSynthetic'  => $latestSynthetic,
      'find'             => $find,
      'hits'             => $hits,
      'hitsTotal'        => $hitsTotal,
  ]) ?>
</div>
<?php $this->stop(); ?>
