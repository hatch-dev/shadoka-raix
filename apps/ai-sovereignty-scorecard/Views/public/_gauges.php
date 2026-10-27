<?php
/**
 * The two gauges on the results page, drawn server-side.
 *
 * The reference built these in JavaScript from `gaugeDonut()` and
 * `countDonut()`. They are static SVG describing numbers the page already knows,
 * so drawing them in the browser only means the page arrives with two holes in
 * it and fills them a moment later. Everything here is arithmetic on the payload
 * the page was rendered from.
 *
 * @var list<array<string,mixed>> $rungs   the instrument's levels, lowest first
 * @var list<float>               $edges   band cut points, e.g. [1.8, 2.6, 3.4, 4.2]
 * @var float|null                $score   the overall average
 * @var array|null                $level   the rung $score falls in
 * @var int                       $scored  dimensions with a score
 * @var int                       $total   dimensions in the instrument
 */

/* The 1–5 scale over 270 degrees, starting at the lower left. */
$cx = 125;
$cy = 104;
$r  = 58;
$sw = 14;
$a0 = -135;
$sweep = 270;

/** A point on the circle, with 0 degrees at twelve o'clock. */
$point = static function (float $cx, float $cy, float $r, float $deg): array {
    $a = ($deg - 90) * M_PI / 180;
    return [$cx + $r * cos($a), $cy + $r * sin($a)];
};

/** An SVG arc path between two angles. */
$arc = static function (float $cx, float $cy, float $r, float $from, float $to) use ($point): string {
    [$x0, $y0] = $point($cx, $cy, $r, $from);
    [$x1, $y1] = $point($cx, $cy, $r, $to);
    $large = ($to - $from) > 180 ? 1 : 0;

    return sprintf('M%.2f,%.2f A%d,%d 0 %d 1 %.2f,%.2f', $x0, $y0, $r, $r, $large, $x1, $y1);
};

/* Stops are [1, ...edges, 5]: one arc per level, at the instrument's own cut
   points, so the gauge cannot mark a boundary the scorer does not use. */
$stops = array_merge([1.0], $edges, [5.0]);
?>
<div class="donut">
  <svg viewBox="0 0 250 200" width="250" height="200" role="img"
       aria-label="Overall score <?= $score === null ? 'unscored' : e(number_format($score, 1)) ?> out of 5">
    <?php foreach ($rungs as $i => $rung): ?>
      <?php
      if (!isset($stops[$i + 1])) {
          continue;
      }

      $from   = $a0 + (($stops[$i] - 1) / 4) * $sweep;
      $to     = $a0 + (($stops[$i + 1] - 1) / 4) * $sweep;
      $active = $level !== null && (int) $level['value'] === (int) $rung['value'];

      [$lx, $ly] = $point($cx, $cy, $r + $sw / 2 + 11, ($from + $to) / 2);
      $anchor = $lx < $cx - 8 ? 'end' : ($lx > $cx + 8 ? 'start' : 'middle');
      ?>
      <path d="<?= e($arc($cx, $cy, $r, $from, $to)) ?>"
            stroke="<?= e($rung['colour']) ?>" stroke-width="<?= $sw ?>" fill="none"
            opacity="<?= $active ? '1' : '.38' ?>"/>
      <text x="<?= e(number_format($lx, 1)) ?>" y="<?= e(number_format($ly, 1)) ?>"
            text-anchor="<?= e($anchor) ?>" dominant-baseline="middle"
            font-size="8.5" font-weight="600"
            fill="<?= $active ? e($rung['colour']) : 'var(--stone)' ?>"><?= e($rung['name']) ?></text>
    <?php endforeach; ?>

    <?php if ($score !== null): ?>
      <?php [$mx, $my] = $point($cx, $cy, $r, $a0 + ((min(5.0, max(1.0, $score)) - 1) / 4) * $sweep); ?>
      <circle cx="<?= e(number_format($mx, 1)) ?>" cy="<?= e(number_format($my, 1)) ?>" r="6"
              fill="var(--paper)" stroke="var(--ink)" stroke-width="2"/>
    <?php endif; ?>

    <text x="<?= $cx ?>" y="<?= $cy - 2 ?>" text-anchor="middle" font-family="var(--num)"
          font-weight="700" font-size="28" fill="var(--paper)"><?=
      $score === null ? '&mdash;' : e(number_format($score, 1))
    ?></text>
    <text x="<?= $cx ?>" y="<?= $cy + 18 ?>" text-anchor="middle" font-size="11" font-weight="600"
          fill="<?= $level ? e($level['colour']) : 'var(--stone)' ?>"><?= e($level['name'] ?? '') ?></text>
  </svg>
  <div class="k">Where your score sits on the five levels</div>
</div>

<div class="donut">
  <?php $gap = 7; ?>
  <svg viewBox="0 0 250 200" width="250" height="200" role="img"
       aria-label="<?= e($scored) ?> of <?= e($total) ?> dimensions scored">
    <?php for ($i = 0; $i < $total; $i++): ?>
      <?php
      $from = -90 + $i * (360 / $total) + $gap / 2;
      $to   = -90 + ($i + 1) * (360 / $total) - $gap / 2;
      ?>
      <path d="<?= e($arc($cx, $cy, $r, $from, $to)) ?>"
            stroke="<?= $i < $scored ? 'var(--blue-2)' : 'var(--rule)' ?>"
            stroke-width="<?= $sw ?>" fill="none"/>
    <?php endfor; ?>
    <text x="<?= $cx ?>" y="<?= $cy + 9 ?>" text-anchor="middle" font-family="var(--num)"
          font-weight="700" font-size="28" fill="var(--paper)"><?= e($scored) ?>/<?= e($total) ?></text>
  </svg>
  <div class="k">Dimensions scored</div>
</div>
