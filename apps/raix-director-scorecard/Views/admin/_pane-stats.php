<?php
/**
 * Analytics → Overview.
 *
 * The reference dashboard's Overview pane, section for section: three stats,
 * the level distribution and radar, the competency bars, the two lower charts,
 * then what directors are aware of and the read of the dataset.
 *
 * The competency bars are `admin/_bars`, shared with Research data →
 * Competencies, because the reference drew both from one `dimRows()` and two
 * copies of that markup can disagree about what a mean of 1.80 is called.
 *
 * @var \Platform\Core\View $this
 */
?>
<div class="grid grid-3" style="margin-bottom:22px">
  <div class="stat">
    <div class="v"><?= e($overview['n']) ?></div>
    <div class="k">Scorecards, across <?= e($overview['boards']) ?> boards</div>
  </div>

  <div class="stat" style="--c:<?= e($floorBand['colour_hex'] ?? 'var(--stone)') ?>">
    <div class="v" style="color:var(--c)"><?= e($fmt($overview['mean_floor'])) ?></div>
    <div class="k">
      Mean floor<?= $floorBand ? ', ' . e(strtolower((string) $floorBand['name'])) : '' ?>
    </div>
  </div>

  <div class="stat">
    <div class="v"><?= e($pct($overview['gap'])) ?></div>
    <div class="k">Average above floor</div>
  </div>
</div>

<div class="grid grid-2" style="margin-bottom:22px">
  <div class="chart-card">
    <h4>Level distribution</h4>
    <div class="cap">Set by each director’s weakest competency, not their average.</div>

    <div class="stack">
      <?php foreach ($levels as $level): ?>
        <?php if ($level['n'] > 0): ?>
          <i style="width:<?= e($level['percent']) ?>%;background:<?= e($level['colour']) ?>"
             title="<?= e($level['name']) ?>: <?= e($level['n']) ?>"></i>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <div class="legend">
      <?php foreach ($levels as $level): ?>
        <span style="--c:<?= e($level['colour']) ?>">
          <?= e($level['name']) ?>: <?= e($level['n']) ?> (<?= e($level['percent']) ?>%)
        </span>
      <?php endforeach; ?>
    </div>

    <div style="height:200px;margin-top:18px"><canvas id="cLevels"></canvas></div>
  </div>

  <div class="chart-card">
    <h4>Seven competencies</h4>
    <div class="cap">Mean across the filtered set. The Floor tag marks the weakest.</div>
    <div style="height:270px"><canvas id="cRadarAn"></canvas></div>
  </div>
</div>

<?= $this->partial('admin/_bars', [
    'competencies' => $competencies,
    'bandOf'       => $bandOf,
    'fmt'          => $fmt,
]) ?>

<hr class="rule">

<div class="grid grid-2">
  <div class="chart-card">
    <h4>By sector</h4>
    <div class="cap">
      Mean director floor. Sectors with a single record are noisy. Treat them as anecdote.
    </div>
    <?php if ($sectors === []): ?>
      <p class="muted small">No sector was recorded on any matching scorecard.</p>
    <?php else: ?>
      <div style="height:280px"><canvas id="cSector"></canvas></div>
    <?php endif; ?>
  </div>

  <div class="chart-card">
    <h4>Where the floor sits</h4>
    <div class="cap">Which competency caps the level, counted across directors.</div>
    <div style="height:280px"><canvas id="cFloor"></canvas></div>
  </div>
</div>

<hr class="rule">

<div class="grid grid-2">
  <div>
    <h3 class="sub">What directors are aware of</h3>
    <p class="muted">
      <?= e($systems['answered']) ?> of <?= e($systems['total']) ?> directors gave a count of the
      AI systems they are aware of.
      Median <?= $systems['median'] === null ? '—' : e(rtrim(rtrim(number_format($systems['median'], 1), '0'), '.')) ?>.
      A blank means they could not put a number on it, or chose not to say.
    </p>
  </div>

  <div>
    <h3 class="sub">Read of the dataset</h3>
    <?php
    /*
     * The reference asked an API for this paragraph and fell back to composed
     * prose whenever the call was off — which it always was. The fallback is
     * what that build actually printed, so it is what is printed here, and it
     * is composed on the server from the figures already on this page.
     */
    ?>
    <div class="analysis"><?= e($insight) ?></div>
  </div>
</div>
