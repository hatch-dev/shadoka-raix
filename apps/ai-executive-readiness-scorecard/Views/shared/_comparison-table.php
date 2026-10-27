<?php
/**
 * Every question with both ratings and their difference — the reference's
 * comparisonTable(rec).
 *
 * A dimension row (its two scores and their difference) above its questions,
 * and an Overall row at the foot. Numbers are the stored ratings, and the
 * difference is self minus leadership.
 *
 * @var array<string, array<string, mixed>> $scores      scope → ['dims', 'mean']
 * @var array<string, array<string, mixed>> $responses   scope → code → index → rating
 * @var array<string, mixed>                $difference  ['mean', 'dims', 'items']
 * @var list<array<string, mixed>>          $dimensions  code, name, items[] with question
 */

use Apps\AiExecutiveReadinessScorecard\Services\Stats;

$num = static fn ($v): ?float => $v === null ? null : (float) $v;
?>
<div class="table-scroll"><table class="data pair-table">
  <thead><tr>
    <th scope="col">Question</th>
    <th scope="col">You</th>
    <th scope="col">Organization&rsquo;s leaders</th>
    <th scope="col">Difference</th>
  </tr></thead>
  <tbody>
    <?php foreach ($dimensions as $dimension): ?>
      <?php $code = (string) $dimension['code']; ?>
      <tr class="domain-total">
        <th scope="row"><?= e($code) ?> · <?= e($dimension['name']) ?></th>
        <td><?= e(Stats::fmt($num($scores['self']['dims'][$code] ?? null))) ?></td>
        <td><?= e(Stats::fmt($num($scores['leadership']['dims'][$code] ?? null))) ?></td>
        <td><?= e(Stats::delta($num($difference['dims'][$code] ?? null))) ?></td>
      </tr>
      <?php foreach ($dimension['items'] as $i => $item): ?>
        <tr>
          <th scope="row"><span class="muted small"><?= e($code) ?> <?= e($i + 1) ?></span> <?= e($item['question']) ?></th>
          <td><?= e($responses['self'][$code][$i] ?? '') ?></td>
          <td><?= e($responses['leadership'][$code][$i] ?? '') ?></td>
          <td><?= e(Stats::delta($num($difference['items'][$code][$i] ?? null))) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <tr class="domain-total">
      <th scope="row">Overall</th>
      <td><?= e(Stats::fmt($num($scores['self']['mean'] ?? null))) ?></td>
      <td><?= e(Stats::fmt($num($scores['leadership']['mean'] ?? null))) ?></td>
      <td><?= e(Stats::delta($num($difference['mean'] ?? null))) ?></td>
    </tr>
  </tbody>
</table></div>
