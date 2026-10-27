<?php
/**
 * A respondent's result.
 *
 * Rendered entirely on the server from the payload the scoring service already
 * produces. The reference assembled this in JavaScript from a record the browser
 * had scored itself; here the page states what the database holds, and the only
 * scripts are the radar chart, the PDF and "Score another organization".
 *
 * @var \Platform\Core\View $this
 * @var array $result
 * @var string $slug
 */
$this->extend('layouts/public');

$level = $result['level'];
$fmt   = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-results">
 <div class="wrap" style="padding-top:40px;padding-bottom:80px">

  <div class="eyebrow">
    <?= e(implode(' · ', array_filter([$result['organisation'], $result['respondent'], $result['year']]))) ?>
  </div>

  <h2 class="title">
    By your own assessment, your organization&rsquo;s AI sovereignty level is
    <?= $level ? e($level['name']) : 'unscored' ?>.
  </h2>

  <div class="score-hero">
    <div class="hero-left" style="--c:<?= e($level['colour'] ?? 'var(--stone)') ?>">
      <div class="level-block">
        <div class="level"><?= $result['mean'] === null ? '—' : e(number_format($result['mean'], 1)) ?></div>
        <div class="level-sub">
          <?php if ($level): ?><b style="color:var(--c)"><?= e($level['name']) ?></b>. <?php endif; ?>
          Your overall score out of 5, from the average of all your answers.
        </div>

        <?php if ($result['unscored']): ?>
          <p class="small muted" style="margin-top:10px">
            <?= e(implode(', ', $result['unscored'])) ?> unscored and left out of the average.
          </p>
        <?php endif; ?>
      </div>

      <div class="donuts">
        <?= $this->partial('public/_gauges', [
            'rungs'  => $result['rungs'],
            'edges'  => $result['band_edges'],
            'score'  => $result['mean'],
            'level'  => $level,
            'scored' => $result['scored_count'],
            'total'  => $result['total_count'],
        ]) ?>
      </div>
    </div>

    <div class="radar-wrap">
      <canvas id="radar" height="300" aria-label="Radar of four dimension scores"></canvas>
    </div>
  </div>

  <div class="bars">
    <?php
    /*
     * No Floor tag and no gold row here. The reference draws these bars with
     * `dimRows(rec.dims, null, null)` — no floor code — so the respondent's page
     * names no weakest dimension; that is reported in analytics only.
     */
    ?>
    <?php foreach ($result['dimensions'] as $dimension): ?>
      <?php $rung = $dimension['rung']; ?>
      <div class="brow<?= $dimension['score'] === null ? ' unscored' : '' ?>"
           style="--c:<?= e($rung['colour'] ?? 'var(--stone)') ?>">
        <div class="k">
          <span class="code"><?= e($dimension['code']) ?></span><?= e($dimension['name']) ?>
        </div>
        <div class="track">
          <?php if ($dimension['score'] !== null): ?>
            <?php /* The scale runs 1 to 5, so a full track is four points wide. */ ?>
            <i style="width:<?= e(round((($dimension['score'] - 1) / 4) * 100, 2)) ?>%"></i>
          <?php endif; ?>
        </div>
        <div class="v"><?= e($fmt($dimension['score'])) ?></div>
        <div class="b<?= $dimension['score'] === null ? ' muted' : '' ?>">
          <?= e($rung['name'] ?? 'Not scored') ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="nav-row">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn quiet sm" type="button" id="downloadPdf">Download PDF</button>
      <a class="btn quiet sm" href="<?= e(url($slug . '/assess?resume=' . urlencode($result['token']))) ?>">Review answers</a>
    </div>
    <?php
    /*
     * The reference's single button, and no Save: that build wrote the record the
     * moment the results opened, and here it was written as each answer was
     * given. This opens the consent gate over the page — Cancel stays here — and
     * only a new acceptance starts the next scorecard, blank, carrying nothing
     * over. The assessment URL rides on the button because only it needs it.
     */
    ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn gold sm" type="button" id="scoreAnother"
              data-assess="<?= e(url($slug . '/assess')) ?>">Score another organization</button>
    </div>
  </div>

  <?php /* Shown only on paper: a printed page has lost the address it came from. */ ?>
  <div class="print-footer">
    AI Sovereignty &mdash; The Enterprise Scorecard &middot; <?= e($copyright ?? '') ?><br>
    This is a self-assessment. Scores describe an organization&rsquo;s stated practice
    and do not constitute an audit. The level is the band of the overall average;
    the weakest dimension is reported beside it and does not cap the level.
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
 * The HEX_* flags keep it safe inside a <script> block.
 */
?>
<script type="application/json" id="resultData"><?= json_encode(
    $result,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script type="module" src="<?= e(app_asset($slug, 'js/results.js')) ?>"></script>
<?php $this->stop(); ?>
