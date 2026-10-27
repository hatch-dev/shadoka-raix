<?php
/**
 * One scorecard — the page's openRecord() dialog: name, role and
 * organization, the level, email and phone, the profile line, when it was
 * submitted, and the six skill bars. ← Console, Organization, PDF, Close.
 *
 * @var array<string, mixed> $record a Records record
 * @var \Apps\HumanEdgeScorecard\Services\Stats $stats
 * @var string $slug
 * @var bool $canExport
 */

use Apps\HumanEdgeScorecard\Services\Html;

$r     = $record;
$org   = $r['org'];
$lv    = $stats->rungNamed($r['level'] ?? null);
$email = ($r['userEmail'] ?? '') ?: ($org['email'] ?? '');
$phone = ($r['userPhone'] ?? '') ?: ($org['phone'] ?? '');
$sub   = implode(' · ', array_values(array_filter([$org['role'] ?? '', $org['org'] ?? ''], static fn ($v): bool => $v !== null && $v !== '')));
$line  = implode(' · ', array_values(array_filter([
    $org['sector'] ?? '',
    ($org['size'] ?? '') !== '' ? $org['size'] . ' employees' : '',
    ($org['tenure'] ?? '') !== '' && ($org['tenure'] ?? null) !== null ? $org['tenure'] . ' in role' : '',
], static fn ($v): bool => $v !== null && $v !== '')));
$console = url('admin/' . $slug . '/console');
?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div><h3><?= e(($r['userName'] ?? '') ?: (($org['name'] ?? '') ?: 'Unnamed respondent')) ?></h3>
    <p class="muted small"><?= e($sub) ?></p></div>
  <div style="text-align:right;flex-shrink:0"><div style="font-family:var(--num);font-size:34px;font-weight:700;color:<?= e($lv['c'] ?? 'var(--stone)') ?>;line-height:1"><?= e($lv['v'] ?? '—') ?></div>
    <div class="small muted"><?= e($lv['name'] ?? 'Unscored') ?></div></div>
</div>
<?php if ($r['synthetic']): ?><div class="warnbox" style="margin:12px 0"><b>Synthetic.</b> Generated demo data. Excluded from benchmarks and from default exports.</div><?php endif; ?>
<div class="grid2" style="margin:14px 0 6px">
  <div class="stat"><div class="v" style="font-size:20px"><?= $email !== '' ? '<a href="mailto:' . e($email) . '" style="color:var(--blue-3)">' . e($email) . '</a>' : '—' ?></div><div class="k">Email</div></div>
  <div class="stat"><div class="v" style="font-size:20px"><?= $phone !== '' ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $phone)) . '" style="color:var(--blue-3)">' . e($phone) . '</a>' : '—' ?></div><div class="k">Phone</div></div>
</div>
<p class="small muted" style="margin-bottom:16px"><?= e($line) ?><br>
  Submitted <?= e(Html::dateTime((string) $r['timestamp'])) ?> · <?= e(Html::since((string) $r['timestamp'])) ?> · id <span style="font-family:var(--num);font-size:12px"><?= e($r['id']) ?></span></p>

<?= Html::dimRows($stats, $r['dims']) ?>

<div class="nav-row">
  <div style="display:flex;gap:8px"><a class="btn quiet sm" href="<?= e($console) ?>" data-modal-src="<?= e($console . '?fragment=1') ?>" data-modal-wide>&larr; Console</a>
    <?php if (($org['org'] ?? '') !== ''): ?><a class="btn quiet sm" <?= Html::orgLink($slug, (string) $org['org']) ?>>Organization</a><?php endif; ?></div>
  <div style="display:flex;gap:8px">
    <?php if ($canExport): ?>
      <button class="btn gold sm" type="button" data-record-pdf="<?= e(url('admin/' . $slug . '/research-data/' . $r['_id'] . '/scorecard-data')) ?>" data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">PDF</button>
    <?php endif; ?>
    <a class="btn sm" data-close-modal href="<?= e(url('admin/' . $slug . '/research-data')) ?>">Close</a></div>
</div>
