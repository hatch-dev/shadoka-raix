<?php
/**
 * Analytics → Correlation.
 *
 * The reference's pane: one full-width card per figure, the coefficient and its
 * interval to the right of the heading, the scatter under it, and a written
 * read at the foot once anything clears the reporting threshold.
 *
 * The scatter is drawn below the threshold as well as above it, as it is there.
 * Withholding the coefficient is a statement about what can be claimed; hiding
 * the points as well would hide the reason — which is that there are eleven of
 * them.
 *
 * The one departure from the reference is the rule this build already enforced:
 * synthetic records never reach this pane, whatever the filter bar says. The
 * legacy seeder derived each demo director's "meetings with AI" and "hours of
 * education" from that record's own score, and its own n ≥ 30 gate did not
 * help, because the demo set cleared it. That is how the reference came to
 * print r = +0.49 for education hours, with a paragraph of prose around it.
 *
 * @var \Platform\Core\View $this
 */
?>
<div class="warnbox" style="margin-bottom:22px">
  <b>Read this first.</b> These are associations between a self-reported score
  and a self-reported figure about the director’s own board, in a self-selected
  sample. They cannot establish that exposure builds competence, or that
  competent directors simply demand more exposure. Coefficients are withheld
  below n = <?= e($correlations[0]['minimum'] ?? 30) ?>. The scatter is shown
  anyway so you can see how thin the data is. Synthetic records are excluded
  here unconditionally, whatever the filter bar says.
</div>

<?php foreach ($correlations as $correlation): ?>
  <div class="chart-card" style="margin-bottom:18px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap">
      <div>
        <h4>Director floor vs <?= e($correlation['label']) ?></h4>
        <div class="cap" style="margin-bottom:0">
          <?= e($correlation['n']) ?> director<?= $correlation['n'] === 1 ? '' : 's' ?>
          reported this figure.
        </div>
      </div>

      <div style="text-align:right">
        <?php if ($correlation['reportable'] && $correlation['stat']): ?>
          <div style="font-family:var(--num);font-size:28px;font-weight:700;letter-spacing:-.02em;
                      color:<?= abs($correlation['stat']['r']) >= 0.3 ? 'var(--gold)' : 'var(--stone)' ?>">
            r = <?= e(($correlation['stat']['r'] > 0 ? '+' : '') . number_format($correlation['stat']['r'], 2)) ?>
          </div>
          <div class="small muted">
            95% CI <?= e(number_format($correlation['stat']['lo'], 2)) ?>
            to <?= e(number_format($correlation['stat']['hi'], 2)) ?>,
            n = <?= e($correlation['stat']['n']) ?>
          </div>
          <?php if ($correlation['stat']['crosses']): ?>
            <div class="small" style="color:var(--r1);font-weight:500;max-width:280px">
              Interval crosses zero, so this is not distinguishable from no association.
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="small" style="color:var(--r1);max-width:280px;font-weight:500">
            Coefficient withheld. n = <?= e($correlation['n']) ?>, below the
            reporting threshold of <?= e($correlation['minimum']) ?>.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php
    /*
     * The plot area is drawn whatever the figures are, as it is in the
     * reference — including with nothing in it. An empty set of axes under a
     * card that has just said n = 0 is the point: the warnbox promises the
     * scatter is shown anyway so you can see how thin the data is, and swapping
     * it for a sentence breaks that promise on exactly the datasets it was
     * written for.
     */
    ?>
    <div style="height:240px;margin-top:12px">
      <canvas id="cCorr-<?= e($correlation['metric']) ?>"></canvas>
    </div>

    <?php if ($correlation['points'] === []): ?>
      <p class="muted small" style="margin-top:10px">
        No director has reported this figure. It is optional on the scorecard,
        and a blank means unanswered rather than zero.
      </p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($corrInsight !== ''): ?>
  <?php
  /*
   * The reference asked an API for this paragraph and fell back to composed
   * prose whenever the call was off — which it always was. The fallback is what
   * that build actually printed, so it is what is printed here, composed on the
   * server from the coefficients already on this page.
   */
  ?>
  <div class="analysis" style="margin-top:10px"><?= e($corrInsight) ?></div>
<?php else: ?>
  <?php
  /*
   * The reference's sentence spells the threshold out, and that reads better
   * than a numeral mid-sentence — but the threshold is configurable, so the
   * word is only used when it is still the thirty the sentence was written for.
   */
  $minimum = (int) ($correlations[0]['minimum'] ?? 30);
  ?>
  <p class="small muted">
    Once <?= $minimum === 30 ? 'thirty' : e($minimum) ?> or more directors report
    a figure, coefficients and confidence intervals appear here. Until then this
    page shows how thin the data is.
  </p>
<?php endif; ?>
