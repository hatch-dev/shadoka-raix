<?php
/**
 * Research data — the page's renderData().
 *
 * The toolbar (include synthetic, Seed data, JSON, CSV, Console), the newest
 * real submission, the counts, and the Records · Dimensions · Levels tabs.
 * The tabs and the synthetic toggle are URLs; the search filters the list as
 * you type, over the rows already on the page, as the page's
 * filterDataRecords() did.
 *
 * @var \Platform\Core\View $this
 * @var list<array<string, mixed>> $recs newest first
 * @var \Apps\HumanEdgeScorecard\Services\Stats $stats
 */

use Apps\HumanEdgeScorecard\Services\Html;
use Apps\HumanEdgeScorecard\Services\Stats;

$this->extend('layouts/admin');

$base = url('admin/' . $slug . '/research-data');
$syn  = $includeSynthetic ? '1' : '0';
$link = static fn (array $changes): string => $base . '?' . http_build_query(array_merge(['tab' => $tab, 'synthetic' => $syn], $changes));

$exportQuery = '?synthetic=' . $syn;
$console     = url('admin/' . $slug . '/console') . $exportQuery;
?>

<?php $this->start('content'); ?>
<div class="eyebrow">Research data</div>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap"><h2 class="title" style="margin-bottom:0">The dataset</h2>
  <div class="toolbar" style="margin:0">
    <label class="small muted" style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="checkbox" <?= $includeSynthetic ? 'checked' : '' ?> onchange="location.href=<?= e(json_encode($link(['synthetic' => $includeSynthetic ? '0' : '1']))) ?>" style="accent-color:var(--r1)"> include <?= e($syntheticCount) ?> synthetic demo records</label>
    <?php if ($canSeed): ?>
      <a class="btn gold sm" href="<?= e(url('admin/' . $slug . '/data-seeder')) ?>" data-modal-src="<?= e(url('admin/' . $slug . '/data-seeder') . '?fragment=1') ?>">Seed data</a>
    <?php endif; ?>
    <?php if ($canExport): ?>
      <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/json') . $exportQuery) ?>">JSON</a>
      <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/csv') . $exportQuery) ?>">CSV</a>
    <?php endif; ?>
    <a class="btn quiet sm" href="<?= e($console) ?>" data-modal-src="<?= e($console . '&fragment=1') ?>" data-modal-wide>Console</a>
  </div>
</div>

<?php if ($notice !== ''): ?>
  <?php /* The page's toast for what just happened, then the parameters come back out
           of the address bar so a reload does not announce it again. */ ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var t = document.getElementById('toast');
      if (t) { t.textContent = <?= json_encode($notice, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>; t.classList.add('show'); setTimeout(function () { t.classList.remove('show'); }, 2600); }
      var q = new URLSearchParams(location.search);
      ['done', 'records', 'orgs', 'empty'].forEach(function (k) { q.delete(k); });
      var s = q.toString();
      history.replaceState(null, '', location.pathname + (s ? '?' + s : '') + location.hash);
    });
  </script>
<?php endif; ?>

<?php /* renderLatestStrip(): the newest REAL submission; a generated row is never "the latest". */ ?>
<div id="latestStrip">
  <?php if ($latest === null): ?>
    <div class="callout" style="margin-top:16px"><h4>No real submission yet</h4><p>The newest scorecard appears here the moment someone completes one. Synthetic demo records are never shown as the latest.</p></div>
  <?php else: ?>
    <div class="eyebrow" style="margin-top:20px;margin-bottom:8px">Most recent submission · <?= e(Html::since((string) $latest['timestamp'])) ?></div>
    <?= Html::recRow($stats, $slug, $latest) ?>
  <?php endif; ?>
</div>

<p class="lede" style="margin-top:10px"><?= e($realCount) ?> real scorecard<?= $realCount === 1 ? '' : 's' ?>. Synthetic records are flagged, excluded from every benchmark, and excluded from export unless you tick the box. Self-reported, unverified, and not yet public.</p>

<div class="grid3">
  <div class="stat"><div class="v"><?= e(count($recs)) ?></div><div class="k">Records shown</div></div>
  <div class="stat"><div class="v"><?= e($sectorCount) ?></div><div class="k">Sectors</div></div>
  <div class="stat"><div class="v"><?= e($recs === [] ? '—' : Stats::f2($median)) ?></div><div class="k">Median overall score</div></div>
</div>

<div class="tabs" style="margin-top:26px">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>" href="<?= e($link(['tab' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div id="dataPane">
<?php if ($recs === []): ?>
  <div class="empty"><b>No scorecards yet</b>Complete and save a scorecard, or tick the box to inspect the synthetic demo set.</div>
<?php elseif ($tab === 'records'): ?>
  <input class="f" id="dataQ" type="search" autocomplete="off" style="margin:4px 0 16px" placeholder="Search by name, email, phone, or organization" value="<?= e($search) ?>">
  <p class="muted small" id="recCount" style="margin-bottom:10px" hidden></p>
  <div class="empty" id="recEmpty" hidden></div>
  <div id="recList">
    <?php foreach ($recs as $record): ?>
      <?= Html::dataRow($stats, $slug, $record) ?>
    <?php endforeach; ?>
  </div>
  <script>
    (function () {
      // filterDataRecords() / matchRec(): every word must appear, accents and
      // punctuation ignored; digits are matched against the phone's digits.
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
  <p class="muted small" style="margin-bottom:14px">Mean of each skill across the scorecards shown, with the range across respondents.</p>
  <?= Html::dimRows($stats, $aggDims, $agg) ?>
<?php else: ?>
  <div class="grid2">
    <?php foreach ($rungs as $r): ?>
      <div class="stat" style="--c:<?= e($r['c']) ?>"><div class="v" style="color:var(--c)"><?= e($levels[$r['name']] ?? 0) ?></div><div class="k">Level <?= e($r['v']) ?>, <?= e(mb_strtolower($r['name'])) ?></div><div class="small muted" style="margin-top:6px"><?= e($r['desc']) ?></div></div>
    <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin-top:14px">Level follows each respondent&rsquo;s overall score, the average of all their answers.</p>
<?php endif; ?>
</div>
<?php $this->stop(); ?>
