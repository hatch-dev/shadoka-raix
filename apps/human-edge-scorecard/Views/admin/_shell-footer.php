<?php
/**
 * The admin footer: this app's public footer, so the console reads as the same
 * product. Keep it in step with the page (page/Human_Edge_Scorecard.html).
 */
?>
<footer>
  <div>The Human Edge Scorecard. A SHADOKA research edition.</div>
  <div class="legal">
    <span class="copy"><?= e($copyright ?? '') ?></span>
    <span>
      <a href="https://shadoka.com/terms/" target="_blank" rel="noopener noreferrer">Terms of Use</a>
      <span class="sep"> &nbsp;|&nbsp; </span>
      <a href="https://shadoka.com/privacy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
    </span>
  </div>
</footer>

<?php
/*
 * The console's scripts, once for every admin page: the console, the record
 * dialog and the organization dialog can each open over ANY admin screen (the
 * Console is in the bar), so their charts and PDF buttons must work everywhere.
 * Both scripts listen on the document; loading either twice would draw every
 * chart twice or save every PDF twice, so this is the only place they are
 * included. jsPDF itself is fetched only when a PDF button is pressed.
 */
$heSlug = 'human-edge-scorecard';
?>
<script src="<?= e(app_asset($heSlug, 'vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(app_asset($heSlug, 'js/admin-charts.js')) ?>"></script>
<script src="<?= e(app_asset($heSlug, 'js/admin-pdf.js')) ?>"></script>

<?php
/*
 * The custom cursor — the gold dot and the ring that trails it — which the
 * page keeps on every screen, admin included. Only for a fine pointer and
 * without reduced motion, as there.
 */
?>
<div class="cursor" id="cur"></div><div class="cursor-ring" id="curRing"></div>
<style>
  @media (hover:hover) and (pointer:fine) {
    body.cursorfx, body.cursorfx a, body.cursorfx button, body.cursorfx select,
    body.cursorfx input, body.cursorfx label { cursor: none }
  }
  .cursor { position: fixed; width: 7px; height: 7px; background: var(--gold); border-radius: 50%;
    pointer-events: none; z-index: 9999; transform: translate(-50%, -50%);
    transition: width .2s, height .2s, background .2s; display: none }
  .cursor-ring { position: fixed; width: 30px; height: 30px; border: 1px solid rgba(61, 130, 196, .55);
    border-radius: 50%; pointer-events: none; z-index: 9998; transform: translate(-50%, -50%);
    transition: width .25s, height .25s, border-color .25s; display: none }
  body.cursorfx .cursor, body.cursorfx .cursor-ring { display: block }
  .cursor.hot { width: 11px; height: 11px }
  .cursor-ring.hot { width: 44px; height: 44px; border-color: rgba(232, 147, 63, .7) }
</style>
<script>
  (function () {
    var reduced = window.matchMedia('(prefers-reduced-motion:reduce)').matches;
    var finePointer = window.matchMedia('(hover:hover) and (pointer:fine)').matches;
    var dot = document.getElementById('cur');
    var ring = document.getElementById('curRing');
    if (!finePointer || reduced || !dot || !ring) return;

    document.body.classList.add('cursorfx');

    var ringX = 0, ringY = 0, targetX = 0, targetY = 0;

    document.addEventListener('mousemove', function (event) {
      targetX = event.clientX;
      targetY = event.clientY;
      dot.style.left = targetX + 'px';
      dot.style.top = targetY + 'px';
    });

    (function follow() {
      ringX += (targetX - ringX) * 0.16;
      ringY += (targetY - ringY) * 0.16;
      ring.style.left = ringX + 'px';
      ring.style.top = ringY + 'px';
      requestAnimationFrame(follow);
    })();

    document.addEventListener('mouseover', function (event) {
      var hot = event.target.closest('button,a,select,input,label,.rec,tr.clickable');
      dot.classList.toggle('hot', Boolean(hot));
      ring.classList.toggle('hot', Boolean(hot));
    });
  })();
</script>
