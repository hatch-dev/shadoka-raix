<?php
/**
 * One record as the reference's `recRow()` drew it, for the console's Latest
 * and Find lists.
 *
 * Name (and the SYNTHETIC tag), then organization · role, then the contact
 * details, then when — the level and the two scores on the right. It opens the
 * record dialog, as the reference's row did.
 *
 * @var array<string, mixed> $record  a row from DatasetController::listed()
 * @var string               $slug
 */
$fmt = static fn ($v): string => $v === null ? '—' : number_format((float) $v, 2);

$href = url('admin/' . $slug . '/research-data/' . $record['id']);

$who     = implode(' · ', array_filter([$record['organisation_name'] ?? null, $record['role'] ?? null]));
$contact = implode(' · ', array_filter([$record['respondent_email'] ?? null, $record['respondent_phone'] ?? null]));

$when = '—';

if (!empty($record['submitted_at']) && ($time = strtotime((string) $record['submitted_at'])) !== false) {
    // The reference's sinceLabel(): minutes, hours, days, then the date.
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

    // The reference's fmtDateTime(): "Aug 16, 2026, 9:45 AM".
    $when = date((string) config('app.date_format') . ', g:i A', $time) . ' · ' . $since;
}
?>
<a class="rec" style="--c:<?= e($record['level_colour'] ?? 'var(--stone)') ?>"
   href="<?= e($href) ?>" data-modal-src="<?= e($href . '?fragment=1') ?>" data-modal-wide>
  <div>
    <div class="t">
      <?= e($record['respondent_name'] ?: 'Unnamed') ?>
      <?php if (!empty($record['is_synthetic'])): ?><span class="tag syn">SYNTHETIC</span><?php endif; ?>
    </div>
    <div class="m"><?= $who !== '' ? e($who) : '<span class="muted">no organization recorded</span>' ?></div>
    <?php if ($contact !== ''): ?><div class="m"><?= e($contact) ?></div><?php endif; ?>
    <div class="m"><?= e($when) ?></div>
  </div>
  <div class="s"><?= e($record['level_value'] ?? '—') ?><small>Overall <?= e($fmt($record['mean_score'])) ?>, floor <?= e($fmt($record['floor_score'])) ?></small></div>
</a>
