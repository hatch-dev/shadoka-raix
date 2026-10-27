<?php
/**
 * The console's Find results — the page's adminSearch(), word for word.
 * Rendered inside the Find pane and on its own for the box's live refresh.
 *
 * @var string                     $slug
 * @var string                     $find
 * @var list<array<string, mixed>> $hits
 * @var int                        $hitsTotal
 * @var array{real:int, synthetic:int} $counts
 * @var \Apps\HumanEdgeScorecard\Services\Stats $stats
 */

use Apps\HumanEdgeScorecard\Services\Html;

$stored = (int) $counts['real'] + (int) $counts['synthetic'];
?>
<?php if (trim($find) === ''): ?>
  <p class="muted small"><?= e($stored) ?> record<?= $stored === 1 ? '' : 's' ?> in the store. Start typing to narrow them.</p>
<?php elseif ($hits === []): ?>
  <div class="empty"><b>No match for &ldquo;<?= e($find) ?>&rdquo;</b>Names are stored as the respondent typed them, and nothing is verified, so check for a middle name or a work address.</div>
<?php else: ?>
  <p class="muted small" style="margin-bottom:10px"><?= e($hitsTotal) ?> match<?= $hitsTotal === 1 ? '' : 'es' ?>, newest first.</p>
  <?php foreach ($hits as $record): ?><?= Html::recRow($stats, $slug, $record) ?><?php endforeach; ?>
  <?php if ($hitsTotal > count($hits)): ?><p class="muted small" style="margin-top:10px">Showing the first <?= e(count($hits)) ?>. Add a word to narrow it.</p><?php endif; ?>
<?php endif; ?>
