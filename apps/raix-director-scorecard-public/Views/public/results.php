<?php
/**
 * A director's result.
 *
 * Rendered entirely on the server from the payload the scoring service already
 * produces. The legacy build assembled this in JavaScript from a record the
 * browser had scored itself; here the page states what the database holds, and
 * the only script is the radar chart.
 *
 * @var \Platform\Core\View $this
 * @var array $result
 */
$this->extend('layouts/public');

$level   = $result['level'];
$floor   = $result['floor'];
$fmt     = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
$date    = static fn (?string $d): string => $d === null ? '—' : date((string) config('app.date_format'), strtotime($d));
?>

<?php $this->start('content'); ?>
<section class="view active" id="view-results">
 <div class="wrap" style="padding-top:40px;padding-bottom:80px">

  <div class="eyebrow">
    <?= e(implode(' · ', array_filter([$result['director'], $result['board'], $result['year']]))) ?>
  </div>

  <?php /* The public build names the level without "(n/4)": the number beside it
           is now the floor score itself, not the rung value. */ ?>
  <h2 class="title">
    As a director of this company, your Responsible AI level is
    <?= $level ? e($level['name']) : 'unscored' ?>.
  </h2>

  <?php $one = static fn (?float $v): string => $v === null ? '—' : number_format($v, 1); ?>
  <div class="score-hero">
    <div class="hero-left" style="--c:<?= e($level['colour'] ?? 'var(--stone)') ?>">
      <div class="level-block">
        <div class="level"><?= e($one($floor)) ?></div>
        <div class="level-sub">
          <?php if ($level): ?><b style="color:var(--c)"><?= e($level['name']) ?></b>. <?php endif; ?>Your level, set by your weakest competency<?= $result['floor_name'] ? ', ' . e($result['floor_name']) : '' ?>. Unweighted average <?= e($one($result['mean'])) ?>.
        </div>

        <?php if ($result['floor_name']): ?>
          <div class="floor-note">This is the competency to spend the next quarter on, however strong the rest of the row looks.</div>
        <?php endif; ?>

        <?php if ($result['unscored']): ?>
          <p class="small muted" style="margin-top:10px">
            <?= e(implode(', ', $result['unscored'])) ?> unscored. An unscored competency
            cannot set your floor, so your level may look better than it is.
          </p>
        <?php endif; ?>
      </div>

      <div class="donuts">
        <?= $this->partial('public/_gauges', [
            'rungs'  => $result['rungs'],
            'score'  => $floor,
            'level'  => $level,
            'scored' => $result['scored_count'],
            'total'  => $result['total_count'],
        ]) ?>
      </div>
    </div>

    <div class="radar-wrap">
      <canvas id="radar" height="300" aria-label="Radar of competency scores"></canvas>
    </div>
  </div>

  <div class="bars">
    <?php foreach ($result['competencies'] as $competency): ?>
      <?php $rung = $competency['rung']; ?>
      <div class="brow<?= $competency['is_floor'] ? ' weak' : '' ?><?= $competency['score'] === null ? ' unscored' : '' ?>"
           style="--c:<?= e($rung['colour'] ?? 'var(--stone)') ?>">
        <div class="k">
          <span class="code"><?= e($competency['code']) ?></span><?= e($competency['name']) ?>
          <?php if ($competency['is_floor']): ?>
            <span class="tag gold" style="margin-left:6px">Floor</span>
          <?php endif; ?>
        </div>
        <div class="track">
          <?php if ($competency['score'] !== null): ?>
            <i style="width:<?= e(round((($competency['score'] - 1) / 3) * 100, 2)) ?>%"></i>
          <?php endif; ?>
        </div>
        <div class="v"><?= e($fmt($competency['score'])) ?></div>
        <div class="b<?= $competency['score'] === null ? ' muted' : '' ?>">
          <?= e($rung['name'] ?? 'Not scored') ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <hr class="rule">

  <div class="grid2">
    <div>
      <h3 class="sub">Read this before the average</h3>
      <div class="analysis">
        <?= e($result['floor_narrative'] ?? 'Summary unavailable: no competency was scored.') ?>
        <?php if ($result['spread'] !== null): ?>
          <?php if ($result['spread'] >= 0.25): ?>
            Your average is <?= e($fmt($result['mean'])) ?>, <?= e($fmt($result['spread'])) ?>
            above your floor. That gap shows how much attention has gone to the
            competencies you were already comfortable asking about, and it is the
            number to watch next year.
          <?php else: ?>
            Your average is <?= e($fmt($result['mean'])) ?>, within a quarter point of
            your floor. The row is level, which means no single competency is carrying
            the others, and no single act will raise the level on its own.
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <h3 class="sub">When to score yourself again</h3>
      <div class="cad">
        <div class="c">
          <div class="k">Full instrument</div>
          <div class="v"><?= e($date($result['cadence']['full']['due'])) ?></div>
          <div class="d"><?= e($result['cadence']['full']['note']) ?></div>
        </div>
        <div class="c">
          <div class="k">Sooner</div>
          <div class="v"><?= e($result['cadence']['sooner']['due'] ? $date($result['cadence']['sooner']['due']) : 'None due') ?></div>
          <div class="d"><?= e($result['cadence']['sooner']['note']) ?></div>
        </div>
        <div class="c">
          <div class="k">On incident</div>
          <div class="v">Immediately</div>
          <div class="d"><?= e($result['cadence']['on_incident']['note']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <hr class="rule">

  <h3 class="sub">What you will do before the next meeting</h3>
  <div class="checks" id="commitments" data-token="<?= e($result['token']) ?>">
    <?php foreach ($result['commitments'] as $commitment): ?>
      <label>
        <input type="checkbox" value="<?= e($commitment['key']) ?>"
               <?= $commitment['checked'] ? 'checked' : '' ?>>
        <span><?= e($commitment['label']) ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin-top:10px"><?= e($result['commitments_note']) ?></p>

  <hr class="rule">

  <?php if ($result['benchmark'] !== null): ?>
    <div class="callout">
      <?php if ($result['benchmark']['reportable']): ?>
        <h4>Against <?= e($result['benchmark']['peers']) ?> other directors</h4>
        <p>
          Your floor of <?= e($fmt($result['floor'])) ?> sits above
          <?= e($result['benchmark']['percentile']) ?>% of them. Median floor
          <?= e(number_format((float) $result['benchmark']['median'], 2)) ?>.
          Self-reported and unverified, and the directors who have scored are not
          a random sample. Read it as a rough position, not a ranking.
        </p>
      <?php else: ?>
        <h4>No benchmark yet</h4>
        <?php
        // The reference's sentence, word for word: the threshold spelled out
        // ("five"), and no running count of peers so far.
        $minimum = (int) $result['benchmark']['minimum'];
        $words   = [1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];
        ?>
        <p>Comparison against other directors appears once the dataset holds <?= e($words[$minimum] ?? (string) $minimum) ?> or more real scorecards. Synthetic demo records are not used as peers.</p>
      <?php endif; ?>
    </div>

    <hr class="rule">
  <?php endif; ?>

  <div class="nav-row">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn quiet sm" type="button" id="downloadPdf">Download PDF</button>
      <a class="btn quiet sm" href="<?= e(url($slug . '/assess?resume=' . urlencode($result['token']))) ?>">Review answers</a>
    </div>
    <?php
    /*
     * Both buttons record the director accepting this result, which is the one
     * thing the page can still tell the database that it does not already know
     * — the answers themselves were saved as they were given. The left one then
     * opens a fresh scorecard carrying the board context over; the right one
     * stays here. The assessment URL rides on the button rather than becoming
     * another global, because only this button needs it.
     */
    ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn quiet sm" type="button" id="saveAndNew"
              data-assess="<?= e(url($slug . '/assess')) ?>">Save · score another seat</button>
      <button class="btn gold sm" type="button" id="saveAndStay">Save this scorecard</button>
    </div>
  </div>

  <?php /* min-height reserves the line, so filling it in does not nudge the page. */ ?>
  <p class="small muted" style="margin-top:12px;text-align:right;min-height:1.4em" id="confirmedNote">
    <?php if ($result['confirmed_at']): ?>
      You accepted this scorecard on <?= e($date($result['confirmed_at'])) ?>.
    <?php endif; ?>
  </p>

  <?php /* Shown only on paper: a printed page has lost the address it came from. */ ?>
  <div class="print-footer">
    RAI-X · The Director Scorecard · <?= e($copyright ?? '') ?><br>
    This is a self-assessment. Scores describe a director’s conduct, not competence
    in law or fiduciary standing. Your level is set by your weakest competency.
  </div>

 </div>
</section>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script src="<?= e(app_asset($slug, 'vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>"></script>
<?php
/*
 * The whole result payload, for the radar and the PDF. Both read the same
 * object the page was rendered from, so neither can state a number the page
 * does not show. HEX_* keeps it safe inside a <script> block.
 */
?>
<script type="application/json" id="resultData"><?= json_encode(
    $result,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script type="module" src="<?= e(app_asset($slug, 'js/results.js')) ?>"></script>
<?php $this->stop(); ?>
