<?php
/**
 * The admin footer: this app's public footer, so it stays on screen when the
 * console opens, as it did in the reference (its admin views were the public
 * page with the nav swapped). Keep it in step with Views/layouts/public.php.
 */
?>
<footer>
  <div>RAI-X, the Responsible AI Index · Director Scorecard. A <?= e($owner ?? 'SHADOKA, LLC') ?> research edition.</div>
  <div class="legal">
    <span class="copy"><?= e($copyright ?? '') ?></span>
    <span>
      <a href="https://shadoka.com/terms/" target="_blank" rel="noopener noreferrer">Terms of Use</a>
      <span class="sep"> &nbsp;|&nbsp; </span>
      <a href="https://shadoka.com/privacy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
    </span>
  </div>
</footer>
