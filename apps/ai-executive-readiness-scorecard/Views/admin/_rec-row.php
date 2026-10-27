<?php
/**
 * One questionnaire as the reference's recRow() drew it — Research data's
 * list, its latest strip, and the console's Latest and Find lists.
 *
 * Name, organization and the SYNTHETIC tag; email · phone · role · year; when.
 * On the right, both overall scores in their scope colours and the difference.
 * It opens the record dialog, as the reference's row did; the href is the page,
 * for a new tab or a browser without scripting.
 *
 * `data-hay` is the reference's recHaystack(), for Research data's live search.
 *
 * @var array<string, mixed> $record a Records::all() record
 * @var string               $slug
 */

use Apps\AiExecutiveReadinessScorecard\Services\Analytics;
use Apps\AiExecutiveReadinessScorecard\Services\Stats;

$href = url('admin/' . $slug . '/research-data/' . $record['_id']);
$time = strtotime((string) $record['timestamp']);

// fmtDateTime(): "Sep 29, 2026, 1:05 PM".
$when = $time === false ? '—' : date('M j, Y, g:i A', $time);
?>
<a class="rec" href="<?= e($href) ?>" data-modal-src="<?= e($href . '?fragment=1') ?>" data-modal-wide
   data-hay="<?= e(Analytics::haystack($record)) ?>" style="cursor:pointer;color:inherit;text-decoration:none">
  <div>
    <div class="t"><?= e($record['userName'] ?: 'Respondent') ?> <span class="muted"><?= e($record['org']['org']) ?></span> <?= $record['synthetic'] ? '<span class="tag syn">SYNTHETIC</span>' : '' ?></div>
    <div class="m"><?= e(implode(' · ', array_filter([
        $record['userEmail'], $record['userPhone'], $record['org']['role'] ?? '', (string) $record['year'],
    ], static fn ($v): bool => $v !== null && $v !== ''))) ?></div>
    <div class="m"><?= e($when) ?></div>
  </div>
  <div class="small">Self <b style="color:var(--gold)"><?= e(Stats::fmt($record['scores']['self']['mean'])) ?></b><br>Leaders <b style="color:var(--blue-2)"><?= e(Stats::fmt($record['scores']['leadership']['mean'])) ?></b><br>Difference <?= e(Stats::delta($record['difference']['mean'])) ?></div>
</a>
