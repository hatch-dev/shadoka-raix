<?php
/**
 * The four dimensions as bars — the reference's `dimRows()`.
 *
 * Shared, because four screens draw it: Analytics → Overview, Research data →
 * Dimensions, the organisation profile and the printed report. In the reference
 * all of them called one `dimRows()`, and a second copy of this markup is a
 * second set of bars that can disagree with the first about what a mean of 2.60
 * is called.
 *
 * `.bars`/`.brow` is also the markup the public result page draws, so the admin
 * and the respondent see one design.
 *
 * Two things this partial is careful about:
 *
 *  - The track runs from 1 to 5, so a fill is (mean - 1) / 4. Dividing by 3 —
 *    the four-level sibling instrument's divisor — draws every bar a third too
 *    long, and anything above 4.00 overflows its track.
 *  - The Floor tag marks the weakest dimension ACROSS THE SET ON SCREEN. That is
 *    a different question from "which dimension is most organisations' weakest",
 *    which is floor_count and is reported separately. And on this instrument
 *    neither of them sets the level, which the caller says in words above.
 *
 * @var list<array<string, mixed>> $dimensions
 * @var string|null $floorCode  the weakest in this set, or null to tag none
 * @var callable $bandOf  a score to its band, by the instrument's cut points
 * @var callable $fmt     a score to two decimals, or an em dash
 */
$floorCode = $floorCode ?? null;
?>
<div class="bars">
  <?php foreach ($dimensions as $dimension): ?>
    <?php
    $mean    = $dimension['mean'] ?? $dimension['score'] ?? null;
    $band    = $bandOf($mean);
    $isFloor = $floorCode !== null && $dimension['code'] === $floorCode;
    ?>
    <?php if ($mean === null): ?>
      <div class="brow unscored">
        <div class="k"><span class="code"><?= e($dimension['code']) ?></span><?= e($dimension['name']) ?></div>
        <div class="track"></div>
        <div class="v">—</div>
        <div class="b muted">Not scored</div>
      </div>
    <?php else: ?>
      <div class="brow<?= $isFloor ? ' weak' : '' ?>" style="--c:<?= e($band['colour_hex'] ?? 'var(--stone)') ?>">
        <div class="k">
          <span class="code"><?= e($dimension['code']) ?></span><?= e($dimension['name']) ?>
          <?php if ($isFloor): ?><span class="tag gold" style="margin-left:6px">Floor</span><?php endif; ?>
        </div>
        <div class="track">
          <i style="width:<?= e(round((((float) $mean - 1) / 4) * 100, 2)) ?>%"></i>
          <?php
          /*
           * The min–max range across the records in this set, drawn only when
           * more than one of them scored this dimension. On a single record
           * min = max = mean, and a zero-width marker on every bar is noise.
           */
          $n   = (int) ($dimension['n'] ?? 0);
          $min = $dimension['min'] ?? null;
          $max = $dimension['max'] ?? null;
          ?>
          <?php if ($n > 1 && $min !== null && $max !== null && $max > $min): ?>
            <div class="sp" style="left:<?= e(round((((float) $min - 1) / 4) * 100, 2)) ?>%;
                                    width:<?= e(round((((float) $max - (float) $min) / 4) * 100, 2)) ?>%"></div>
          <?php endif; ?>
        </div>
        <div class="v"><?= e($fmt($mean)) ?></div>
        <div class="b"><?= e($band['name'] ?? '') ?></div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
