<?php
/**
 * The overview page.
 *
 * Structurally the reference's: one hero-shell holding the pitch and the two
 * calls to action on the left, and the four dimensions on the right. Nothing
 * below it — the levels, the cut points and the disclaimer all live in the "How
 * scoring works" dialog, which is what the second button opens.
 *
 * What used to be hard-coded in that build's JavaScript — the dimensions and
 * their one-line summaries — arrives from the database here, so publishing a new
 * instrument version changes this page without a deploy.
 *
 * $itemCount is counted rather than listed: the sentence says
 * "four dimensions with sixteen questions", and those numbers come from the
 * instrument so the prose cannot outlive a version that changes them.
 *
 * @var \Platform\Core\View $this
 * @var array $instrument
 * @var list<array<string,mixed>> $dimensions
 * @var list<array<string,mixed>> $rungs
 * @var int $itemCount
 * @var string $slug
 */
$this->extend('layouts/public');

$numbers = [
    3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight',
    9 => 'Nine', 10 => 'Ten', 16 => 'Sixteen', 30 => 'Thirty', 35 => 'Thirty-five',
];

$dimensionWord = $numbers[count($dimensions)] ?? (string) count($dimensions);
$itemWord      = $numbers[$itemCount] ?? (string) $itemCount;
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-hero">
 <div class="hero-shell"><div class="hero-bg" aria-hidden="true"></div>
  <div class="hero">
    <div>
      <div class="index-line">AI Sovereignty</div>
      <div class="product-name"><?= e($instrument['subtitle'] ?? 'The AI Sovereignty Scorecard') ?></div>
      <h1>Can your organization make its own choices as AI becomes part of its business?</h1>
      <p>AI sovereignty is your organization&rsquo;s ability to make, carry out, and
         revise its own choices as it adopts AI. It depends on control over data and
         technology, the capacity for independent human judgment, and the ability to
         change course. Assess your organization across these
         <?= e(strtolower($dimensionWord)) ?> dimensions with
         <?= e(strtolower($itemWord)) ?> questions about its current practices. Allow
         about ten minutes.</p>
      <?php
      /*
       * The reference's hero column ends here, at the two calls to action. It
       * carries no note about the scoring rule and no disclaimer — both of
       * those belong to the "How scoring works" dialog, which is what the
       * second button opens.
       */
      ?>
      <div class="cta">
        <a class="btn gold" href="<?= e(url($slug . '/assess')) ?>" data-start-assessment>Score your organization</a>
        <button class="btn" type="button" data-open-help>How scoring works</button>
      </div>
    </div>
    <div>
      <div class="comps-head">What is measured<span>The <?= e(strtolower($dimensionWord)) ?> dimensions</span></div>
      <div class="comps" aria-label="The <?= e(strtolower($dimensionWord)) ?> dimensions">
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
