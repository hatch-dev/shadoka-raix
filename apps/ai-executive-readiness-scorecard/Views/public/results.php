<?php
/**
 * A respondent's result — the reference's renderResults(rec).
 *
 * Rendered on the server from the payload the scoring service produces: the
 * two score cards, the radar with the comparison note beside it, the paired
 * bars, then every answer side by side. The reference assembled this in
 * JavaScript from a record the browser had scored itself; here the page states
 * what the database holds, and the only scripts are the radar, the PDF,
 * "Review answers" and "Score another organization".
 *
 * @var \Platform\Core\View $this
 * @var array $result
 * @var string $slug
 */

use Apps\AiExecutiveReadinessScorecard\Services\Stats;
use Platform\Assessment\Bander;

$this->extend('layouts/public');

$bander = new Bander(array_map(
    static fn (array $r): array => $r + ['colour_hex' => $r['colour'], 'band_min' => $r['min'], 'band_max' => $r['max']],
    $result['rungs']
));
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-results">
 <div class="wrap" id="resultsRoot" style="padding-top:40px;padding-bottom:80px">

  <div class="eyebrow"><?= e($result['organisation']) ?> · <?= e($result['respondent'] ?: 'Respondent') ?> · <?= e($result['year']) ?></div>
  <h2 class="title">Your AI executive readiness results</h2>
  <p class="lede">You have assessed yourself and your organization&rsquo;s leaders across four dimensions. Both profiles reflect your own answers.</p>

  <?= $this->partial('shared/_score-cards', ['scores' => $result['scores'], 'bander' => $bander, 'group' => false]) ?>

  <div class="score-hero">
    <div class="radar-wrap"><canvas id="radar" height="300" role="img" aria-label="Comparison of your four dimension scores and your ratings of the organization’s leaders"></canvas></div>
    <div class="callout">
      <h4>Understanding the comparison</h4>
      <p><?= e($result['text']['comparison_note']) ?></p>
      <p style="margin-top:16px">Your overall difference is <b><?= e(Stats::delta($result['difference']['mean'] === null ? null : (float) $result['difference']['mean'])) ?></b>. Each overall score is the average of 12 answers. Each dimension score is the average of three.</p>
    </div>
  </div>

  <?= $this->partial('shared/_paired-bars', [
      'scores'     => $result['scores'],
      'dimensions' => $result['dimensions'],
      'bander'     => $bander,
      'spread'     => null,
  ]) ?>

  <hr class="rule">
  <h3 class="sub">Your answers side by side</h3>
  <?= $this->partial('shared/_comparison-table', [
      'scores'     => $result['scores'],
      'responses'  => $result['responses'],
      'difference' => $result['difference'],
      'dimensions' => $result['dimensions'],
  ]) ?>

  <p class="small muted" style="margin-top:20px"><?= e($result['text']['disclaimer']) ?></p>

  <div class="nav-row">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn quiet sm" type="button" id="downloadPdf">Download PDF</button>
      <?php
      /*
       * The reference's reviewAnswers() reopens the questionnaire at the first
       * dimension with every rating in place. A link carrying the token, so it
       * works in a new tab too; assess.js opens a finished scorecard there.
       */
      ?>
      <a class="btn quiet sm" href="<?= e(url($slug . '/assess?resume=' . urlencode($result['token']))) ?>">Review answers</a>
    </div>
    <?php
    /*
     * scoreAnotherOrganization(): nothing to save first — every rating was
     * written as it was given — so this opens the consent gate over this page
     * (Cancel stays here) and, once accepted, a blank scorecard.
     */
    ?>
    <button class="btn gold sm" type="button" id="scoreAnother"
            data-assess="<?= e(url($slug . '/assess')) ?>">Score another organization</button>
  </div>

 </div>
</section>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script src="<?= e(app_asset($slug, 'vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>"></script>
<?php
/*
 * The whole result payload, for the radar and the PDF. Both read the same object
 * the page was rendered from, so neither can state a number the page does not.
 */
?>
<script type="application/json" id="resultData"><?= json_encode(
    $result,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script type="module" src="<?= e(app_asset($slug, 'js/results.js')) ?>"></script>
<?php $this->stop(); ?>
