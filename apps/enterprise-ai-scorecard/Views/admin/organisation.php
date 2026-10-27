<?php
/**
 * One organisation's profile, as a page.
 *
 * The dialog the dashboard opens is the same body — see `_organisation-modal` —
 * so this page exists for the cases a dialog cannot serve: a link someone was
 * sent, a bookmark, a browser with scripting off, and printing.
 *
 * @var \Platform\Core\View $this
 * @var array $org
 * @var \Platform\Assessment\Bander $bander
 * @var bool $includeSynthetic
 * @var string $slug
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">
      <a href="<?= e(url('admin/' . $slug . '/analytics?tab=yoy')) ?>">Analytics</a> &rsaquo; organization
    </div>
    <h1 class="title"><?= e($org['organisation']['name']) ?></h1>
  </div>
</div>

<?= $this->partial('admin/_organisation-modal', [
    'org'              => $org,
    'bander'           => $bander,
    'includeSynthetic' => $includeSynthetic,
    'slug'             => $slug,
]) ?>
<?php $this->stop(); ?>

<?php /* The PDF button's script loads once for every admin page, from
         admin/_shell-footer.php. */ ?>
