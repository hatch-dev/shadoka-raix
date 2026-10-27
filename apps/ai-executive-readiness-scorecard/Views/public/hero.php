<?php
/**
 * The overview page.
 *
 * Structurally the reference's: one hero-shell holding the pitch and the two
 * calls to action on the left, and the four dimensions on the right. Nothing
 * below it — the levels, the cut points and the disclaimer all live in the "How
 * scoring works" dialog, which is what the second button opens.
 *
 * The dimensions and their one-line summaries come from the database, so
 * publishing a new instrument version changes this list without a deploy. The
 * paragraph's "four dimensions" and "12 questions" are the reference's own
 * words; the counts are checked against the instrument by
 * instrument_integrity_test.php rather than interpolated, so the sentence reads
 * exactly as written.
 *
 * @var \Platform\Core\View $this
 * @var array $instrument
 * @var list<array<string,mixed>> $dimensions
 * @var int $itemCount
 * @var string $slug
 */
$this->extend('layouts/public');
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-hero">
 <div class="hero-shell"><div class="hero-bg" aria-hidden="true"></div>
  <div class="hero">
    <div>
      <div class="index-line">AI Executive Readiness</div>
      <div class="product-name"><?= e($instrument['subtitle'] ?? 'The AI Executive Readiness Scorecard') ?></div>
      <h1>How ready are you and your organization&rsquo;s leaders to lead with AI?</h1>
      <p>AI changes the decisions leaders make and the work they ask people to do. Assess
         your organization&rsquo;s readiness to lead AI-related change by measuring it
         across four dimensions. Answer 12 questions for both yourself and your
         organization&rsquo;s leadership team, then compare the two profiles. Allow about
         15 minutes.</p>
      <div class="cta">
        <a class="btn gold" href="<?= e(url($slug . '/assess')) ?>" data-start-assessment>Start your scorecard</a>
        <button class="btn" type="button" data-open-help>How scoring works</button>
      </div>
    </div>
    <div>
      <div class="comps-head">What is measured<span>The four dimensions</span></div>
      <div class="comps" id="heroComps" aria-label="The four dimensions">
        <?php foreach ($dimensions as $dimension): ?>
        <div class="comp">
          <div class="c"><?= e($dimension['code']) ?></div>
          <div class="n"><?= e($dimension['name']) ?></div>
          <div class="q"><?= e($dimension['summary_line']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
 </div>
</section>
<?php $this->stop(); ?>
