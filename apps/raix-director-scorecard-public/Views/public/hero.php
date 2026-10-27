<?php
/**
 * The overview page.
 *
 * Everything on it that used to be hard-coded in JavaScript — the seven
 * competencies, their board questions, the four rungs and their descriptions —
 * now arrives from the database, so publishing a new instrument version changes
 * this page without a deploy.
 *
 * @var \Platform\Core\View $this
 * @var array $instrument
 * @var list<array<string,mixed>> $competencies
 * @var list<array<string,mixed>> $rungs
 * @var int $itemCount
 */
$this->extend('layouts/public');

$numbers = [
    4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
    35 => 'Thirty-five',
];
$competencyWord = $numbers[count($competencies)] ?? (string) count($competencies);
$itemWord       = $numbers[$itemCount] ?? (string) $itemCount;
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-hero">
 <div class="hero-shell"><div class="hero-bg" aria-hidden="true"></div>
  <div class="hero">
    <div>
      <div class="index-line">Responsible AI Index &middot; RAI-X</div>
      <div class="product-name"><?= e($instrument['subtitle'] ?? 'The Director Scorecard') ?></div>
      <h1><?= e($competencyWord) ?> things a director has to be able to do.</h1>
      <p>Boards are expected to oversee AI. Almost nobody asks whether individual
         directors have the skills and capabilities needed to do so. This scorecard
         measures you, not the company, across <?= e(strtolower($competencyWord)) ?>
         competencies. Each statement describes something a director does. You mark
         the statement on whether you had considered it, understand its value, have
         done it at least once, or do it as a matter of course.
         Your level is set by your weakest competency, not by your average.</p>
      <div class="cta">
        <a class="btn gold" href="<?= e(url($slug . '/assess')) ?>" data-start-assessment>Score yourself</a>
        <button class="btn" type="button" data-open-help>How scoring works</button>
      </div>
      <p class="small muted" style="margin-top:22px">
        <?= e($itemWord) ?> statements describing things a director does, marked
        against your own conduct over the last twelve months. Allow about fifteen minutes.
      </p>
      <p class="small muted" style="margin-top:10px">
        This is a self-assessment. Scores describe a director’s conduct, not
        competence in law or fiduciary standing.
      </p>
    </div>
    <div>
      <div class="comps-head">What is measured<span>The <?= e(strtolower($competencyWord)) ?> competencies</span></div>
      <div class="comps" aria-label="The <?= e(strtolower($competencyWord)) ?> competencies">
        <?php foreach ($competencies as $competency): ?>
        <div class="comp">
          <div class="c"><?= e($competency['code']) ?></div>
          <div class="n"><?= e($competency['name']) ?></div>
          <div class="q">“<?= e($competency['board_question']) ?>”</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
 </div>

  <div class="wrap" style="padding-bottom:40px">
    <hr class="rule">
    <div class="grid3">
      <div>
        <h3 class="sub"><?= e($competencyWord) ?> competencies</h3>
        <?php /* Editorial prose, not instrument data. Deriving it from the
                 competency names produced "risk, human, autonomy" — each one
                 truncated at its first word, and worse copy than the sentence
                 it replaced. Kept as written, like the rest of the hero. */ ?>
        <p class="muted">The scorecard covers governance, data, explanation,
           fairness, irreversible risk, human judgment, and the authority held
           by agents.</p>
      </div>
      <div>
        <h3 class="sub">What you get</h3>
        <p class="muted">Your Responsible AI level, the competency that sets it, and one
           thing to do before your next board meeting.</p>
      </div>
      <div>
        <h3 class="sub">When to score again</h3>
        <p class="muted">Every year, on joining a new board, and after any AI incident
           at the company.</p>
      </div>
    </div>
    <hr class="rule">

    <div class="reveal">
      <div class="eyebrow">The <?= e(strtolower($numbers[count($rungs)] ?? (string) count($rungs))) ?> levels</div>
      <p class="muted" style="max-width:760px;margin-bottom:22px">
        Each of the <?= e(strtolower($itemWord)) ?> statements describes something a
        director does, and you mark each one at the level that is true of you.
      </p>
      <div class="ladder-row">
        <?php foreach ($rungs as $rung): ?>
        <div class="lr" style="--c:<?= e($rung['colour_hex']) ?>">
          <div class="n">Level <?= e($rung['value']) ?>, <?= e($rung['name']) ?></div>
          <div class="t"><?= e(rtrim($rung['description'], '.')) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php $this->stop(); ?>
