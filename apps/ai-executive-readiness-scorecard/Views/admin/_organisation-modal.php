<?php
/**
 * One organization — the reference's openOrg() dialog: its questionnaires
 * pooled into the two profiles, with each dimension's spread, and the
 * organization PDF.
 *
 * @var string                              $name
 * @var list<array<string, mixed>>          $recs
 * @var string                              $description
 * @var int                                 $respondents
 * @var array<string, array<string, mixed>> $scores
 * @var array<string, array<string, mixed>> $spread
 * @var list<array<string, mixed>>          $dimensions
 * @var \Platform\Assessment\Bander         $bander
 * @var string                              $slug
 * @var string                              $query the request's own query string
 */
?>
<h3><?= e($name) ?></h3>
<p class="small muted"><?= e($description) ?></p>
<p class="muted"><?= e(count($recs)) ?> questionnaires from <?= e($respondents) ?> respondents. Each questionnaire contributes once to each mean.</p>

<?= $this->partial('shared/_score-cards', ['scores' => $scores, 'bander' => $bander, 'group' => true]) ?>
<p class="small muted">The first profile averages respondents&rsquo; assessments of themselves. The second averages their assessments of this organization&rsquo;s leaders. These are distinct views. Repeated assessments contribute separately.</p>
<?= $this->partial('shared/_paired-bars', ['scores' => $scores, 'dimensions' => $dimensions, 'bander' => $bander, 'spread' => $spread]) ?>

<div class="nav-row">
  <?php if (\Platform\Core\Auth::can('exr.export')): ?>
    <button class="btn gold sm" type="button"
            data-org-pdf="<?= e(url('admin/' . $slug . '/analytics/organisation/report-data') . '?' . preg_replace('/(^|&)fragment=1/', '', $query)) ?>"
            data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">Download organization PDF</button>
  <?php else: ?>
    <span></span>
  <?php endif; ?>
  <a class="btn sm" data-close-modal href="<?= e(url('admin/' . $slug . '/analytics')) ?>">Close</a>
</div>
