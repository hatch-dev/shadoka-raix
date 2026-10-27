<?php
/**
 * Both profiles, dimension by dimension — the reference's pairedBars(scores, spread).
 *
 * For each dimension, a bar per scope in that scope's colour, the score, the
 * level it falls in, and the difference between the two. With `$spread` (the
 * pooled views) each bar also carries its n, range and SD, and the labels switch
 * to the pooled wording.
 *
 * The bar is `(score − 1) / 4` of the track, so 1 is empty and 5 is full.
 *
 * The difference is written as the reference computed it, `self − leadership`
 * on the two dimension values; JavaScript reads a missing value as 0 in that
 * subtraction, so a dimension neither scope scored prints "0.00", not a dash.
 *
 * @var array<string, array<string, mixed>>     $scores     scope → ['dims' => code → ?float]
 * @var list<array<string, mixed>>              $dimensions code + name, in order
 * @var \Platform\Assessment\Bander             $bander
 * @var array<string, array<string, mixed>>|null $spread    scope → code → {n,min,max,sd}
 */

use Apps\AiExecutiveReadinessScorecard\Services\Scopes;
use Apps\AiExecutiveReadinessScorecard\Services\Stats;

$spread = $spread ?? null;
?>
<div class="paired-bars">
  <?php foreach ($dimensions as $dimension): ?>
    <?php $code = (string) $dimension['code']; ?>
    <div class="paired-domain">
      <h3 class="sub"><span class="muted small"><?= e($code) ?></span> <?= e($dimension['name']) ?></h3>
      <?php foreach (Scopes::all() as $scope): ?>
        <?php
        $value = $scores[$scope['key']]['dims'][$code] ?? null;
        $value = $value === null ? null : (float) $value;
        $band  = $bander->band($value);
        $width = max(0, ($value ?? 1) - 1) / 4 * 100;
        ?>
        <div class="brow paired-row">
          <div class="k" style="color:<?= e($scope['colour']) ?>"><?= e($spread ? $scope['group_label'] : $scope['label']) ?></div>
          <div class="track"><i style="width:<?= e(rtrim(rtrim(number_format($width, 4, '.', ''), '0'), '.')) ?>%;background:<?= e($scope['colour']) ?>"></i></div>
          <div class="v"><?= e(Stats::fmt($value)) ?></div>
          <div class="b" style="--c:<?= e($band['colour'] ?? 'var(--stone)') ?>"><?= e($band['name'] ?? 'Unscored') ?></div>
        </div>
        <?php if ($spread): ?>
          <?php $s = $spread[$scope['key']][$code]; ?>
          <div class="small muted spread-note">n = <?= e($s['n']) ?> · Range <?= e(Stats::fmt($s['min'])) ?>–<?= e(Stats::fmt($s['max'])) ?> · SD <?= e(Stats::fmt($s['sd'])) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php
      $self       = $scores['self']['dims'][$code] ?? null;
      $leadership = $scores['leadership']['dims'][$code] ?? null;
      ?>
      <p class="small muted">Difference <?= e(Stats::delta((float) ($self ?? 0) - (float) ($leadership ?? 0))) ?></p>
    </div>
  <?php endforeach; ?>
</div>
