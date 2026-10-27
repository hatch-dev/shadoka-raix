<?php
/**
 * The seven competencies as bars — the reference's `dimRows()`.
 *
 * Shared, because two screens draw it: Analytics → Overview and Research
 * data → Competencies. In the reference both called the same function, and a
 * second copy of this markup is a second set of bars that can disagree with
 * the first about what a mean of 1.80 is called.
 *
 * `.bars`/`.brow` is also the markup the public result page draws, so the
 * admin and the director see one design.
 *
 * The Floor tag marks the WEAKEST competency across the set on screen. That is
 * not the same question as "which competency caps the most directors" — that
 * one is floor_count, and the Competencies table reports it separately.
 *
 * @var \Platform\Core\View $this
 * @var list<array<string, mixed>> $competencies
 * @var callable $bandOf  a score to its band, by the instrument's cut points
 * @var callable $fmt     a score to two decimals, or an em dash
 */
$scored = array_values(array_filter(
    $competencies,
    static fn (array $c): bool => $c['mean'] !== null
));
usort($scored, static fn (array $a, array $b): int => $a['mean'] <=> $b['mean']);
$floorCode = $scored[0]['code'] ?? null;
?>
<div class="bars">
  <?php foreach ($competencies as $competency): ?>
    <?php
    $mean = $competency['mean'];
    $band = $bandOf($mean);
    $isFloor = $floorCode !== null && $competency['code'] === $floorCode;
    ?>
    <?php if ($mean === null): ?>
      <div class="brow unscored">
        <div class="k"><span class="code"><?= e($competency['code']) ?></span><?= e($competency['name']) ?></div>
        <div class="track"></div>
        <div class="v">—</div>
        <div class="b muted">Not scored</div>
      </div>
    <?php else: ?>
      <div class="brow<?= $isFloor ? ' weak' : '' ?>" style="--c:<?= e($band['colour_hex'] ?? 'var(--stone)') ?>">
        <div class="k">
          <span class="code"><?= e($competency['code']) ?></span><?= e($competency['name']) ?>
          <?php if ($isFloor): ?><span class="tag gold" style="margin-left:6px">Floor</span><?php endif; ?>
        </div>
        <div class="track">
          <i style="width:<?= e(round((($mean - 1) / 3) * 100, 2)) ?>%"></i>
          <?php if ($competency['n'] > 1 && $competency['min'] !== null && $competency['max'] !== null): ?>
            <div class="sp" style="left:<?= e(round((($competency['min'] - 1) / 3) * 100, 2)) ?>%;
                                    width:<?= e(round((($competency['max'] - $competency['min']) / 3) * 100, 2)) ?>%"></div>
          <?php endif; ?>
        </div>
        <div class="v"><?= e($fmt($mean)) ?></div>
        <div class="b"><?= e($band['name'] ?? '') ?></div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
