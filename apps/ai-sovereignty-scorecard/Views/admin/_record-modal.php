<?php
/**
 * One scorecard, as the dialog the dataset list opens over itself — the
 * reference's `openRecord()`.
 *
 * The same partial body is used by the full page (admin/record.php), so the
 * dialog and the page cannot disagree about a record.
 *
 * @var \Platform\Core\View $this
 * @var array $submission
 * @var array|null $result
 * @var list<array<string,mixed>> $items
 * @var array|null $organisation
 * @var \Platform\Assessment\Bander $bander
 */
$fmt = static fn (?float $v): string => $v === null ? '—' : number_format((float) $v, 2);

$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

$level = $bandOf($submission['mean_score'] === null ? null : (float) $submission['mean_score']);
$href  = url('admin/' . $slug . '/research-data/' . $submission['id']);

/*
 * "Submitted Sep 28, 2026, 6:11 AM · 2 hours ago" — the reference's
 * fmtDateTime() and sinceLabel(), as _rec-row.php prints them.
 */
$when = null;

if (!empty($submission['submitted_at']) && ($time = strtotime((string) $submission['submitted_at'])) !== false) {
    $minutes = intdiv(time() - $time, 60);
    $hours   = intdiv(max(0, $minutes), 60);
    $days    = intdiv($hours, 24);

    $since = match (true) {
        $minutes < 0  => date((string) config('app.date_format'), $time),
        $minutes < 1  => 'just now',
        $minutes < 60 => $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago',
        $hours < 24   => $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago',
        $days < 45    => $days . ' day' . ($days === 1 ? '' : 's') . ' ago',
        default       => date((string) config('app.date_format'), $time),
    };

    $when = date((string) config('app.date_format') . ', g:i A', $time) . ' · ' . $since;
}
?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div>
    <h3><?= e($submission['respondent_name'] ?: 'Unnamed respondent') ?></h3>
    <p class="muted small">
      <?= e(implode(' · ', array_filter([$submission['role'], $submission['organisation_name']]))) ?>
    </p>
  </div>
  <?php
  /*
   * The LEVEL, as the reference's openRecord() prints it (`lv.v`, 1 to 5), with
   * its name beneath — not the overall score. The score is in the note below,
   * to two decimals; a one-decimal score here read as a third figure ("3.3"
   * beside a 3.25 everywhere else).
   */
  ?>
  <div style="text-align:right;flex-shrink:0">
    <div style="font-family:var(--num);font-size:34px;font-weight:700;line-height:1;
                color:<?= e($level['colour_hex'] ?? 'var(--stone)') ?>">
      <?= e($level['value'] ?? '—') ?>
    </div>
    <div class="small muted"><?= e($level['name'] ?? 'Unscored') ?></div>
  </div>
</div>

<?php if ((int) $submission['is_synthetic'] === 1): ?>
  <div class="warnbox" style="margin:12px 0">
    <b>Synthetic.</b> Generated demo data. Excluded from benchmarks and from default exports.
  </div>
<?php endif; ?>

<?php /* The contact details. This is what the console is for. */ ?>
<?php /* Two equal cells, as the reference's grid2. The email stays on one line;
         an unusually long address ends in an ellipsis, with the whole of it in
         the tooltip and the mailto link. */ ?>
<div class="grid grid-2" style="margin:14px 0 6px">
  <div class="stat">
    <div class="v" style="font-size:20px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
         title="<?= e($submission['respondent_email'] ?? '') ?>">
      <?php if ($submission['respondent_email']): ?>
        <a href="mailto:<?= e($submission['respondent_email']) ?>"
           style="color:var(--blue-3)"><?= e($submission['respondent_email']) ?></a>
      <?php else: ?>—<?php endif; ?>
    </div>
    <div class="k">Email</div>
  </div>
  <div class="stat">
    <div class="v" style="font-size:20px">
      <?php if ($submission['respondent_phone']): ?>
        <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $submission['respondent_phone'])) ?>"
           style="color:var(--blue-3)"><?= e($submission['respondent_phone']) ?></a>
      <?php else: ?>—<?php endif; ?>
    </div>
    <div class="k">Phone</div>
  </div>
</div>

<p class="small muted" style="margin-bottom:16px">
  <?= e(implode(' · ', array_filter([
      $submission['sector'],
      $submission['organisation_size'] ? $submission['organisation_size'] . ' employees' : null,
      $submission['tenure'] ? $submission['tenure'] . ' in role' : null,
  ]))) ?><br>
  <?php if ($when !== null): ?>Submitted <?= e($when) ?> · <?php endif; ?>assessment year <?= e($submission['assessment_year']) ?> ·
  id <span style="font-family:var(--num);font-size:12px"><?= e($submission['id']) ?></span>
</p>

<?php if ($result !== null && $result['floor_name'] !== null): ?>
  <div class="warnbox" style="margin-bottom:16px">
    <b>Weakest dimension (floor): <?= e($result['floor_name']) ?> at <?= e($fmt($result['floor'])) ?>.</b>
    Overall score <?= e($fmt($result['mean'])) ?>.
  </div>
<?php endif; ?>

<?php if ($result !== null): ?>
  <?= $this->partial('admin/_bars', [
      'dimensions' => array_map(
          static fn (array $d): array => $d + ['mean' => $d['score'], 'n' => 1],
          $result['dimensions']
      ),
      'floorCode'  => $result['floor_code'],
      'bandOf'     => $bandOf,
      'fmt'        => $fmt,
  ]) ?>
<?php else: ?>
  <div class="empty"><b>In progress</b>This scorecard has not been finished, so it has no scores yet.</div>
<?php endif; ?>

<div class="nav-row">
  <?php
  /*
   * The reference's pair: "← Console" goes back to the admin console, and
   * "Organization" opens that organisation's dialog (wide) in place of this
   * one — both as dialogs, never leaving the screen underneath. The hrefs are
   * the real pages, for a browser without scripting.
   *
   * The organisation is shown with synthetic records included: the reference's
   * openOrg() took every record for that organisation, and a synthetic
   * scorecard's organisation has nothing else in it.
   */
  $consoleHref = url('admin/' . $slug . '/console');
  $orgHref     = $submission['organisation_id']
      ? url('admin/' . $slug . '/analytics/organisation/' . $submission['organisation_id']) . '?synthetic=1'
      : null;
  ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn quiet sm" href="<?= e($consoleHref) ?>"
       data-modal-src="<?= e($consoleHref . '?fragment=1') ?>" data-modal-wide>&larr; Console</a>
    <?php if ($orgHref !== null): ?>
      <a class="btn quiet sm" href="<?= e($orgHref) ?>"
         data-modal-src="<?= e($orgHref . '&fragment=1') ?>" data-modal-wide>Organization</a>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php
    /*
     * The respondent's own scorecard, as the document they received. Offered
     * only on a finished one — there is nothing to print until it is scored —
     * and only to someone who may export, because it names them.
     */
    ?>
    <?php if ($result !== null && \Platform\Core\Auth::can('sov.export')): ?>
      <button class="btn gold sm" type="button"
              data-record-pdf="<?= e(url('admin/' . $slug . '/research-data/' . $submission['id'] . '/scorecard-data')) ?>"
              data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">PDF</button>
    <?php endif; ?>
    <button class="btn sm" type="button" data-close-modal>Close</button>
  </div>
</div>
