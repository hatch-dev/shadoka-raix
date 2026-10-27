<?php
/**
 * The two gauges on the results page, drawn server-side.
 *
 * The reference built these in JavaScript (`gaugeDonut()`, `countDonut()`).
 * They are static SVG describing numbers the page already knows, so drawing
 * them in the browser would only make the page arrive with two holes in it.
 * Same geometry, same colours, same captions.
 *
 * The scale is 1 to N, where N is the number of rungs — four in v1.0 — and the
 * arcs meet at each rung's own lower cut point (1.75 / 2.75 / 3.75), so the
 * gauge cannot mark a boundary the scorer does not use.
 *
 * @var list<array<string,mixed>> $rungs   Bander::rungs(), lowest first
 * @var float|null                $score   the floor score
 * @var array|null                $level   the rung the floor falls in
 * @var int                       $scored  competencies with a score
 * @var int                       $total   competencies in the instrument
 */

$n     = max(2, count($rungs));
$cx    = 125;
$cy    = 104;
$r     = 58;
$sw    = 14;
$a0    = -135;
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

$frac = static fn (float $v): float => (min((float) $n, max(1.0, $v)) - 1) / ($n - 1);

/* Stops are [1, each higher rung's lower cut point, N]. */
$stops = [1.0];
foreach (array_slice($rungs, 1) as $rung) {
    $stops[] = (float) $rung['min'];
}
$stops[] = (float) $n;
?>
<div class="donut">
  <svg viewBox="0 0 250 200" width="250" height="200" role="img"
       aria-label="Score <?= $score === null ? 'unscored' : e(number_format($score, 1)) ?> out of <?= e($n) ?>">
    <?php foreach ($rungs as $i => $rung): ?>
      <?php
      if (!isset($stops[$i + 1])) {
          continue;
      }

      $from   = $a0 + $frac($stops[$i]) * $sweep;
      $to     = $a0 + $frac($stops[$i + 1]) * $sweep;
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
      <?php [$mx, $my] = $point($cx, $cy, $r, $a0 + $frac($score) * $sweep); ?>
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
  <div class="k">Where your weakest competency sits on the <?= e([2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'][$n] ?? (string) $n) ?> levels</div>
</div>

<div class="donut">
  <?php $gap = 7; ?>
  <svg viewBox="0 0 250 200" width="250" height="200" role="img"
       aria-label="<?= e($scored) ?> of <?= e($total) ?> competencies scored">
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
  <div class="k">Competencies scored</div>
</div>
