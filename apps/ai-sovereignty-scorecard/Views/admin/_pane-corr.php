<?php
/**
 * Analytics → Correlation.
 *
 * The reference's pane: its "Read this first" warning, one full-width card per
 * figure, the coefficient and its interval to the right of the heading, the
 * scatter under it, and a written read at the foot once anything clears the
 * reporting threshold.
 *
 * The scatter is drawn below the threshold as well as above it, as it is there.
 * Withholding the coefficient is a statement about what can be claimed; hiding
 * the points as well would hide the reason — which is that there are eleven of
 * them.
 *
 * One departure from the reference, and it is the important one: synthetic
 * records never reach this pane, whatever the filter bar says. The seeder does
 * not generate these figures at all, which is the same restraint the reference
 * showed — and unlike that build, the exclusion is also written into the query,
 * so it does not depend on the generator continuing to behave.
 *
 * Plotted against the overall score, and titled and labelled so, as the
 * reference does.
 *
 * @var \Platform\Core\View $this
 */
?>
<div class="warnbox" style="margin-bottom:22px">
  <b>Read this first.</b> These are associations between a self-reported score and
  a self-reported figure about the respondent's own organization, in a
  self-selected sample. They cannot establish that these activities strengthen
  sovereignty, or that organizations with stronger sovereignty practices simply
  undertake more of them. Coefficients are
  withheld below n=<?= e($minimumN) ?>; the scatter is shown anyway so you can
  see how thin the data is.
</div>

<?php foreach ($correlations as $correlation): ?>
  <div class="chart-card" style="margin-bottom:18px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap">
      <div>
        <h4>Overall score vs <?= e($correlation['label']) ?></h4>
        <div class="cap" style="margin-bottom:0">
          <?= e($correlation['n']) ?> respondent<?= $correlation['n'] === 1 ? '' : 's' ?>
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
              Interval crosses zero: not distinguishable from no association
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="small" style="color:var(--r1);max-width:280px;font-weight:500">
            Coefficient withheld. n = <?= e($correlation['n']) ?>, below the
            reporting floor of <?= e($correlation['minimum']) ?>.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php
    /*
     * The plot area is drawn whatever the figures are, as it is in the reference
     * — including with nothing in it. An empty set of axes under a card that has
     * just said n = 0 is the point: the warning above promises the scatter is
     * shown anyway so you can see how thin the data is, and swapping it for a
     * sentence breaks that promise on exactly the datasets it was written for.
     */
    ?>
    <div style="height:240px;margin-top:12px">
      <canvas id="cCorr-<?= e($correlation['metric']) ?>"></canvas>
    </div>

  </div>
<?php endforeach; ?>

<?php
$reportable = array_values(array_filter(
    $correlations,
    static fn (array $c): bool => $c['reportable'] && $c['stat'] !== null
));
?>

<?php if ($reportable !== []): ?>
  <?php
  /*
   * The reference asked an AI endpoint for this paragraph, from the browser, with
   * no API key, and fell back to this sentence whenever the call failed, which
   * was always. So this sentence is what that build printed, word for word.
   */
  ?>
  <div class="analysis" style="margin-top:10px">
    Associations at this sample size are suggestive at best. Report the confidence
    interval alongside any coefficient, and never describe these as effects.
  </div>
<?php else: ?>
  <?php
  /*
   * The reference's sentence spells the threshold out, and that reads better than
   * a numeral mid-sentence — but the threshold is configurable, so the word is
   * only used when it is still the thirty the sentence was written for.
   */
  ?>
  <p class="small muted">
    Once <?= $minimumN === 30 ? 'thirty' : e($minimumN) ?> or more respondents
    report a figure, coefficients and confidence intervals appear here. Until then
    this page exists to show the gap, not to fill it.
  </p>
<?php endif; ?>
