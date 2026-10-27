<?php
/**
 * Research data — the reference's renderData().
 *
 * The toolbar (include synthetic, Seed data, JSON, CSV, PDF, Console), the
 * newest real submission, the counts, and the Records · Dimensions · Levels
 * tabs. The tabs and the synthetic toggle are URLs; the search filters the list
 * as you type, over the rows already on the page, as the reference's
 * filterDataRecords() did.
 *
 * @var \Platform\Core\View $this
 * @var list<array<string, mixed>> $recs newest first
 */

use Apps\AiExecutiveReadinessScorecard\Services\Stats;

$this->extend('layouts/admin');

$base = url('admin/' . $slug . '/research-data');
$syn  = $includeSynthetic ? '1' : '0';
$link = static fn (array $changes): string => $base . '?' . http_build_query(array_merge(
    ['tab' => $tab, 'synthetic' => $syn],
    $changes
));

$exportQuery = '?synthetic=' . $syn;
$jspdf       = app_asset($slug, 'vendor/jspdf.umd.min.js');

// sinceLabel(): minutes, hours, days, then the date.
$since = static function (string $iso): string {
    $time = strtotime($iso);
    if ($time === false) {
        return '—';
    }

    $minutes = intdiv(time() - $time, 60);
    $hours   = intdiv(max(0, $minutes), 60);
    $days    = intdiv($hours, 24);

    return match (true) {
        $minutes < 0  => date('M j, Y', $time),
        $minutes < 1  => 'just now',
        $minutes < 60 => $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago',
        $hours < 24   => $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago',
        $days < 45    => $days . ' day' . ($days === 1 ? '' : 's') . ' ago',
        default       => date('M j, Y', $time),
    };
};
?>

<?php $this->start('content'); ?>
<div class="eyebrow">Research data</div>
<h1 class="title">The dataset</h1>

<?php if ($notice !== ''): ?>
  <div class="notice" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<div class="toolbar">
  <label class="small muted" style="cursor:pointer">
    <input type="checkbox" <?= $includeSynthetic ? 'checked' : '' ?>
           onchange="location.href=<?= e(json_encode($link(['synthetic' => $includeSynthetic ? '0' : '1']))) ?>">
    Include <?= e($syntheticCount) ?> synthetic records
  </label>
  <?php if ($canSeed): ?>
    <a class="btn gold sm" href="<?= e(url('admin/' . $slug . '/data-seeder')) ?>"
       data-modal-src="<?= e(url('admin/' . $slug . '/data-seeder') . '?fragment=1') ?>">Seed data</a>
  <?php endif; ?>
  <?php if ($canExport): ?>
    <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/json') . $exportQuery) ?>">JSON</a>
    <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/csv') . $exportQuery) ?>">CSV</a>
    <button class="btn quiet sm" type="button"
            data-report-pdf="<?= e(url('admin/' . $slug . '/report/data') . $exportQuery . '&source=data') ?>"
            data-jspdf="<?= e($jspdf) ?>">PDF</button>
  <?php endif; ?>
  <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/console') . $exportQuery) ?>"
     data-modal-src="<?= e(url('admin/' . $slug . '/console') . $exportQuery . '&fragment=1') ?>" data-modal-wide>Console</a>
</div>

<?php /* renderLatestStrip(): the newest REAL submission; a generated row is never "the latest". */ ?>
<div id="latestStrip">
  <?php if ($latest === null): ?>
    <div class="callout" style="margin-top:16px"><h4>No real submission yet</h4><p>The newest scorecard appears here the moment someone completes one. Synthetic demo records are never shown as the latest.</p></div>
  <?php else: ?>
    <div class="eyebrow" style="margin-top:20px;margin-bottom:8px">Most recent submission · <?= e($since((string) $latest['timestamp'])) ?></div>
    <?= $this->partial('admin/_rec-row', ['record' => $latest, 'slug' => $slug]) ?>
  <?php endif; ?>
</div>

<p class="lede" style="margin-top:18px"><?= e(count($recs)) ?> questionnaires from <?= e($respondents) ?> respondents across <?= e($organisations) ?> organizations. Each questionnaire contains 24 ratings. Synthetic records are labeled and excluded from exports unless included here.</p>

<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>" href="<?= e($link(['tab' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div id="dataPane">
<?php if ($recs === []): ?>
  <div class="empty">No scorecards to show.</div>
<?php elseif ($tab === 'records'): ?>
  <input class="f" id="dataQ" type="search" placeholder="Search by name, email, phone, or organization" value="<?= e($search) ?>">
  <p class="muted small" id="recCount" style="margin:10px 0" hidden></p>
  <div class="empty" id="recEmpty" hidden></div>
  <div id="recList">
    <?php foreach ($recs as $record): ?>
      <?= $this->partial('admin/_rec-row', ['record' => $record, 'slug' => $slug]) ?>
    <?php endforeach; ?>
  </div>
  <script>
    (function () {
      // filterDataRecords() / matchRec(): every word must appear, accents and
      // punctuation ignored; a number is matched against the phone's digits.
      var box = document.getElementById('dataQ');
      var list = document.getElementById('recList');
      var count = document.getElementById('recCount');
      var empty = document.getElementById('recEmpty');
      var rows = Array.prototype.slice.call(list.querySelectorAll('.rec'));
      var norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
      var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

      function filter() {
        var needle = norm(box.value).trim();
        var words = needle ? needle.split(/\s+/) : [];
        var hits = 0;

        rows.forEach(function (row) {
          var hay = row.getAttribute('data-hay') || '';
          var show = words.every(function (w) { return hay.indexOf(w) !== -1; });
          row.hidden = !show;
          if (show) hits++;
        });

        count.hidden = !needle || hits === 0;
        count.textContent = hits + ' of ' + rows.length + ' record' + (rows.length === 1 ? '' : 's') + ' match, newest first.';

        empty.hidden = hits !== 0;
        empty.innerHTML = '<b>No match for “' + esc(box.value) + '”</b>Names are stored as the respondent typed them, and nothing is verified, so check for a middle name or a work address.';
      }

      box.addEventListener('input', filter);
      if (box.value) filter();
    })();
  </script>
<?php elseif ($tab === 'dims'): ?>
  <p class="small muted">Means across the questionnaires shown. Each set of ratings is kept separate. Repeated assessments contribute once per questionnaire.</p>
  <?= $this->partial('shared/_paired-bars', [
      'scores' => $scores, 'dimensions' => $dimensions, 'bander' => $bander, 'spread' => $spread,
  ]) ?>
<?php else: ?>
  <?= $this->partial('admin/_table', [
      'headers' => ['Level', 'Self-ratings', 'Ratings of leaders'],
      'rows'    => $levelRows,
  ]) ?>
<?php endif; ?>
</div>
<?php $this->stop(); ?>
