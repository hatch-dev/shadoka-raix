<?php
/**
 * The assessment shell.
 *
 * The questionnaire is still built by js/assess.js — there is one renderer, and
 * the page cannot drift from it. What changed is where the instrument comes
 * from: it is inlined below rather than fetched, so the first competency can be
 * drawn on first paint.
 *
 * @var \Platform\Core\View $this
 * @var array|null $instrument the published instrument, or null if none is
 */
$this->extend('layouts/public');
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-assess">
  <?php
  /*
   * min-height holds the page open while the first step is drawn. Without it
   * the footer sits directly under the header for as long as that takes, and
   * then the page jumps by the height of a whole step — which reads as a
   * glitch rather than as loading.
   *
   * A viewport's worth, less the header, puts the footer just past the fold, so
   * it is not seen at all before the step arrives. Every step of this
   * assessment is taller than that, so nothing is over-reserved.
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
   * The published instrument, inlined. HEX_* keeps it safe inside a <script>
   * block. assess.js falls back to /api/instrument if this is absent, so a
   * cached page from before this change still works.
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
 * fetched and parsed assess.js first: without these hints they were measured
 * starting 40–240ms after it, instead of alongside. Over localhost that costs
 * nothing worth reporting — the comparison came out inside the run-to-run
 * noise — but the dependent request it removes is one full round trip on any
 * real network, which is where this page will be read.
 *
 * The URLs must be the bare, un-fingerprinted ones the imports resolve to, or
 * the browser preloads one file and then fetches another. app_module() exists
 * for exactly that.
 */
?>
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/api.js')) ?>">
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/ui.js')) ?>">
<script type="module" src="<?= e(app_asset($slug, 'js/assess.js')) ?>"></script>
<?php $this->stop(); ?>
