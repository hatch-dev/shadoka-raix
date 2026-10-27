<?php
/**
 * The assessment shell.
 *
 * The questionnaire is built by js/assess.js — there is one renderer, and the
 * page cannot drift from it. What changed from the reference is where the
 * instrument comes from: it is inlined below rather than hard-coded in the
 * script, so the first dimension can be drawn on first paint AND there is still
 * only one copy of the wording, in the database.
 *
 * @var \Platform\Core\View $this
 * @var array|null $instrument the published instrument, or null if none is
 * @var string $slug
 */
$this->extend('layouts/public');
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-assess">
  <?php
  /*
   * min-height holds the page open while the first step is drawn. Without it the
   * footer sits directly under the header for as long as that takes, and then
   * the page jumps by the height of a whole step — which reads as a glitch
   * rather than as loading.
   */
  ?>
  <div class="wrap-n" id="assessRoot"
       style="padding-top:40px;padding-bottom:80px;min-height:calc(100vh - 80px)">
    <noscript>
      <div class="callout" style="margin-top:40px">
        <h4>The scorecard needs JavaScript</h4>
        <p>The assessment saves each answer as you give it, which needs scripting enabled.</p>
      </div>
    </noscript>
  </div>
</section>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<?php if ($instrument !== null): ?>
  <?php
  /*
   * The published instrument, inlined. The HEX_* flags keep it safe inside a
   * <script> block — without them a `</script>` in any instrument text would end
   * the element early. assess.js falls back to /api/instrument if this is
   * absent, so a page cached from before this change still works.
   */
  ?>
  <script type="application/json" id="instrumentData"><?= json_encode(
      $instrument,
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?></script>
<?php endif; ?>
<?php
/*
 * assess.js imports these, and the browser cannot discover them until it has
 * fetched and parsed assess.js first. The URLs must be the bare,
 * un-fingerprinted ones the imports resolve to, or the browser preloads one file
 * and then fetches another — app_module() exists for exactly that.
 */
?>
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/api.js')) ?>">
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/ui.js')) ?>">
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/consent.js')) ?>">
<script type="module" src="<?= e(app_asset($slug, 'js/assess.js')) ?>"></script>
<?php $this->stop(); ?>
