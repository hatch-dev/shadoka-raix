<?php
/**
 * The overview page.
 *
 * Structurally the reference's: one hero-shell holding the pitch and the two
 * calls to action on the left, and the six dimensions on the right. Nothing
 * below it — the levels, the cut points and the disclaimer all live in the "How
 * scoring works" dialog, which is what the second button opens.
 *
 * What used to be hard-coded in that build's JavaScript — the dimensions and
 * their one-line summaries — arrives from the database here, so publishing a new
 * instrument version changes this page without a deploy.
 *
 * $rungs and $itemCount are counted rather than listed: the sentence says
 * "thirty questions … on a five-point scale", and those numbers come from the
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
    9 => 'Nine', 10 => 'Ten', 30 => 'Thirty', 35 => 'Thirty-five',
];

$dimensionWord = $numbers[count($dimensions)] ?? (string) count($dimensions);
$itemWord      = $numbers[$itemCount] ?? (string) $itemCount;
$levelWord     = $numbers[count($rungs)] ?? (string) count($rungs);
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-hero">
 <div class="hero-shell"><div class="hero-bg" aria-hidden="true"></div>
  <div class="hero">
    <div>
      <div class="index-line">AI Readiness</div>
      <div class="product-name"><?= e($instrument['subtitle'] ?? 'The Enterprise Scorecard') ?></div>
      <h1>Is your organization ready for AI?</h1>
      <p>Everyone measures AI adoption. Few measure whether the organization
         underneath it is ready to implement AI effectively. This scorecard rates
         your enterprise across <?= e(strtolower($dimensionWord)) ?> dimensions of
         readiness. <?= e($itemWord) ?> questions about what your organization
         actually does, each answered on a <?= e(strtolower($levelWord)) ?>-point
         scale. Allow about fifteen minutes.</p>
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
