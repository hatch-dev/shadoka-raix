<?php
/**
 * One questionnaire — the reference's openRecord() dialog: who, both score
 * cards, the paired bars, every answer side by side, then Organization ·
 * Download PDF · Close.
 *
 * Rendered as the dialog's contents and inside record.php, so the two cannot
 * differ.
 *
 * @var array<string, mixed> $record a Records record
 * @var array<string, mixed> $result the SubmissionService payload for the same row
 * @var \Platform\Assessment\Bander $bander
 * @var string $slug
 */

$org    = $record['org'];
$orgUrl = url('admin/' . $slug . '/analytics/organisation') . '?' . http_build_query([
    'name'   => $org['org'],
    'source' => 'data',
    'force'  => $record['synthetic'] ? '1' : '0',
]);
?>
<h3><?= e($record['userName'] ?: 'Respondent') ?></h3>
<p class="muted"><?= e($org['org']) ?> · <?= e($record['year']) ?></p>
<p class="small muted"><?= e(implode(' · ', array_filter(
    [$record['userEmail'], $record['userPhone'], $org['role'] ?? '', $org['sector'] ?? '', $org['size'] ?? ''],
    static fn ($v): bool => $v !== null && $v !== ''
))) ?></p>
<?php if ($record['synthetic']): ?><p class="tag syn">SYNTHETIC DEMONSTRATION RECORD</p><?php endif; ?>

<?= $this->partial('shared/_score-cards', ['scores' => $record['scores'], 'bander' => $bander, 'group' => false]) ?>
<?= $this->partial('shared/_paired-bars', ['scores' => $record['scores'], 'dimensions' => $result['dimensions'], 'bander' => $bander, 'spread' => null]) ?>

<h3 class="sub">All 12 paired answers</h3>
<p class="small muted"><?= e($result['text']['comparison_note']) ?></p>
<?= $this->partial('shared/_comparison-table', [
    'scores'     => $record['scores'],
    'responses'  => $record['responses'],
    'difference' => $record['difference'],
    'dimensions' => $result['dimensions'],
]) ?>

<div class="nav-row">
  <a class="btn quiet sm" href="<?= e($orgUrl) ?>" data-modal-src="<?= e($orgUrl . '&fragment=1') ?>" data-modal-wide>Organization</a>
  <div>
    <?php if ($canExport): ?>
      <button class="btn gold sm" type="button"
              data-record-pdf="<?= e(url('admin/' . $slug . '/research-data/' . $record['_id'] . '/scorecard-data')) ?>"
              data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">Download PDF</button>
    <?php endif; ?>
    <a class="btn sm" data-close-modal href="<?= e(url('admin/' . $slug . '/research-data')) ?>">Close</a>
  </div>
</div>
