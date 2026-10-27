<?php
/**
 * Analytics → Overview.
 *
 * The reference dashboard's Overview pane, section for section: four stats, the
 * level distribution and the radar, the dimension bars, the two lower charts,
 * then what is actually enumerated and the read of the dataset.
 *
 * The dimension bars are `admin/_bars`, shared with Research data → Dimensions
 * and the organisation profile, because the reference drew all of them from one
 * `dimRows()` and two copies of that markup can disagree about what a mean of
 * 2.60 is called.
 *
 * @var \Platform\Core\View $this
 */
?>
<?php /* Three across with four cards, as the reference's grid3: the fourth
         wraps onto a row of its own. */ ?>
<div class="grid grid-3" style="margin-bottom:22px">
  <div class="stat">
    <div class="v"><?= e($overview['n']) ?></div>
    <div class="k">Scorecards, across <?= e($overview['organisations']) ?> organizations</div>
  </div>

  <div class="stat" style="--c:<?= e($meanBand['colour_hex'] ?? 'var(--stone)') ?>">
    <div class="v" style="color:var(--c)"><?= e($fmt($overview['overall_of_dimensions'])) ?></div>
    <div class="k">
      Mean overall score<?= $meanBand ? ', ' . e($meanBand['name']) : '' ?>
    </div>
  </div>

  <div class="stat">
    <div class="v"><?= e($fmt($overview['lowest_dimension_mean'])) ?></div>
    <div class="k">
      Lowest dimension mean<?php
        $floorBand = $bandOf($overview['lowest_dimension_mean']);
        echo $floorBand ? ', ' . e($floorBand['name']) : '';
      ?>
    </div>
  </div>

  <div class="stat">
    <?php /* The reference's `'+' + gap.toFixed(2)`: signed even at zero. */ ?>
    <div class="v"><?= $overview['overall_above_lowest'] === null ? '—' : '+' . e(number_format($overview['overall_above_lowest'], 2)) ?></div>
    <div class="k">Overall score above lowest dimension mean</div>
  </div>
</div>

<div class="grid grid-2" style="margin-bottom:22px">
  <div class="chart-card">
    <h4>Level distribution</h4>
    <div class="cap">Each organization&rsquo;s level, from the average of all its answers.</div>

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
    <h4>Four dimensions</h4>
    <div class="cap">Mean across the filtered set. The Floor tag marks the weakest dimension.</div>
    <div style="height:270px"><canvas id="cRadarAn"></canvas></div>
  </div>
</div>

<?= $this->partial('admin/_bars', [
    'dimensions' => $dimensions,
    'floorCode'  => $aggregateFloor['code'],
    'bandOf'     => $bandOf,
    'fmt'        => $fmt,
]) ?>

<hr class="rule">

<div class="grid grid-2">
  <div class="chart-card">
    <h4>By sector</h4>
    <div class="cap">
      Mean overall score. Sectors with a single record are noisy and best treated
      as anecdote.
    </div>
    <?php if ($sectors === []): ?>
      <p class="muted small">No sector was recorded on any matching scorecard.</p>
    <?php else: ?>
      <div style="height:280px"><canvas id="cSector"></canvas></div>
    <?php endif; ?>
  </div>

  <div class="chart-card">
    <h4>Where the floor sits</h4>
    <div class="cap">
      Which dimension is each organization&rsquo;s weakest, counted across scorecards.
    </div>
    <div style="height:280px"><canvas id="cFloor"></canvas></div>
  </div>
</div>

<hr class="rule">

<div class="grid grid-2">
  <div>
    <h2 class="sub">What is actually enumerated</h2>
    <p class="muted">
      <?php
      /*
       * The reference's own sentence: a blank here is ambiguous — no inventory
       * exists, or the respondent does not know what is on it — and "could not
       * give a count" and "runs no AI" look identical in the data.
       */
      ?>
      <?= e($systems['answered']) ?> of <?= e($systems['total']) ?>
      respondents could give a count of the
      AI systems on an inventory they have seen enumerated.
      Median <?= $systems['median'] === null
          ? '—'
          : e(rtrim(rtrim(number_format($systems['median'], 1), '0'), '.')) ?>.
      A blank answer may mean no inventory exists or that the respondent does not
      know its contents.
    </p>
  </div>

  <div>
    <h2 class="sub">Read of the dataset</h2>
    <?php
    /*
     * The reference asked an AI endpoint for this paragraph, from the browser,
     * with no API key, and fell back to composed prose whenever the call failed —
     * which was always. The fallback is what that build actually printed, so it
     * is what is printed here, composed on the server from the figures already
     * on this page.
     */
    ?>
    <div class="analysis"><?= e($reading) ?></div>
  </div>
</div>
