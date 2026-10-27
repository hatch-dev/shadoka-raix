<?php
/**
 * The two score cards — the reference's scoreCards(scores, group).
 *
 * One card per scope: the overall score out of 5 in the colour of its level,
 * the level's name and its description. `$group` switches the headings from a
 * respondent's own ("Your executive readiness") to the pooled wording
 * ("Respondents’ self-ratings"), as the reference did on every admin screen.
 *
 * Shared by the results page, the record and organisation dialogs and the
 * analytics panes, so a profile is presented one way everywhere.
 *
 * @var array<string, array<string, mixed>> $scores scope → ['mean' => ?float, …]
 * @var \Platform\Assessment\Bander         $bander
 * @var bool                                $group
 */

use Apps\AiExecutiveReadinessScorecard\Services\Scopes;
use Apps\AiExecutiveReadinessScorecard\Services\Stats;

$group = $group ?? false;
?>
<div class="grid2 result-cards">
  <?php foreach (Scopes::all() as $scope): ?>
    <?php
    $mean  = $scores[$scope['key']]['mean'] ?? null;
    $level = $bander->band($mean === null ? null : (float) $mean);
    ?>
    <div class="card">
      <h3 class="sub" style="color:<?= e($scope['colour']) ?>"><?=
        e($group ? $scope['group_label'] : $scope['card_title'])
      ?></h3>
      <div class="level score-number" style="--c:<?= e($level['colour'] ?? 'var(--stone)') ?>"><?=
        e(Stats::fmt($mean === null ? null : (float) $mean))
      ?><span> / 5</span></div>
      <p class="level-sub"><b style="color:<?= e($level['colour'] ?? '') ?>"><?= e($level['name'] ?? 'Unscored') ?></b></p>
      <p class="small muted"><?= e($level['description'] ?? '') ?></p>
    </div>
  <?php endforeach; ?>
</div>
