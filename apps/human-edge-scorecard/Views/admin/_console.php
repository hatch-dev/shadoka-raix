<?php
/**
 * The admin console — the page's adminPanel() dialog: Latest · Find · Data ·
 * Export, opening on Latest.
 *
 * A partial because it is rendered twice: into the dialog the bar and Research
 * data open, and on its own page for a browser without scripting.
 *
 * Differences from the page, each because this console is not the page:
 *  - Its tabs swapped innerHTML; here each is a URL, so a pane can be linked
 *    and reloaded. Inside the dialog a tab re-opens the dialog on that pane.
 *  - Its subtitle called the PIN gate demo-grade. This console's actions are
 *    guarded server-side and written to the audit log, so the sentence would
 *    be false here and is not repeated.
 *  - Its rows were <label>s wrapping their own button; here they are rows.
 *
 * @var \Platform\Core\View $this
 * @var \Apps\HumanEdgeScorecard\Services\Stats $stats
 */

use Apps\HumanEdgeScorecard\Services\Html;

$syn    = $includeSynthetic ? '1' : '0';
$export = static fn (string $path): string => url('admin/' . $slug . '/' . $path) . '?synthetic=' . $syn;
$pane   = static fn (string $key): string => url('admin/' . $slug . '/console') . '?tab=' . $key . '&synthetic=' . $syn;
$jspdf  = app_asset($slug, 'vendor/jspdf.umd.min.js');
$real   = (int) $counts['real'];
$synN   = (int) $counts['synthetic'];
$identified = 'This export contains names, contact details and organization names. Use it only to return scorecards, handle requests or delete records. Continue?';
?>
<h3>Admin console</h3>
<p class="muted small" style="margin-bottom:14px"><?= e($real) ?> real · <?= e($synN) ?> synthetic scorecard<?= $synN === 1 ? '' : 's' ?>. Everything below is recorded in the audit log.</p>

<div class="dash-tabs" style="margin-top:0">
  <?php foreach (['latest' => 'Latest', 'find' => 'Find', 'data' => 'Data', 'export' => 'Export'] as $key => $label): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>" href="<?= e($pane($key)) ?>" data-modal-src="<?= e($pane($key) . '&fragment=1') ?>" data-modal-wide><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div id="adminPane">
<?php if ($tab === 'latest'): ?>
  <?php
  /* adminLatestPane(): the newest scorecard, then the five before it, with its
     own include-synthetic box — a link here, reloading this pane. */
  $toggleUrl = $pane('latest') . ($latestSynthetic ? '' : '&lsyn=1');
  $toggle    = static fn (string $style, string $text): string =>
      '<a class="small muted" href="' . e($toggleUrl) . '" data-modal-src="' . e($toggleUrl . '&fragment=1') . '" data-modal-wide'
      . ' style="display:flex;gap:8px;align-items:center;cursor:pointer;text-decoration:none;' . $style . '">'
      . '<input type="checkbox" tabindex="-1"' . ($latestSynthetic ? ' checked' : '') . ' style="accent-color:var(--r1);pointer-events:none"> ' . e($text) . '</a>';
  ?>
  <?php if ($latest === []): ?>
    <div class="empty"><b>No <?= $latestSynthetic ? '' : 'real ' ?>scorecards yet</b><?php if ($synN > 0 && !$latestSynthetic): ?>The store holds <?= e($synN) ?> synthetic record<?= $synN === 1 ? '' : 's' ?>. Tick the box to include them.<?php else: ?>Nothing has been submitted.<?php endif; ?></div>
    <?php if ($synN > 0): ?><?= $toggle('margin-top:12px', 'include synthetic records') ?><?php endif; ?>
  <?php else: ?>
    <div class="eyebrow" style="margin-bottom:8px">Most recent submission</div>
    <?= Html::recRow($stats, $slug, $latest[0]) ?>
    <p class="small muted" style="margin-top:10px">Click any row for the full scorecard, the contact details, and the evidence the respondent named.</p>
    <?php if (count($latest) > 1): ?>
      <div class="eyebrow" style="margin:20px 0 8px">Before that</div>
      <?php foreach (array_slice($latest, 1) as $record): ?><?= Html::recRow($stats, $slug, $record) ?><?php endforeach; ?>
    <?php endif; ?>
    <?= $toggle('margin-top:14px;border-top:1px solid var(--rule-soft);padding-top:12px', 'include ' . $synN . ' synthetic record' . ($synN === 1 ? '' : 's')) ?>
  <?php endif; ?>

<?php elseif ($tab === 'find'): ?>
  <form method="get" action="<?= e(url('admin/' . $slug . '/console')) ?>" data-console-find>
    <input type="hidden" name="tab" value="find">
    <input type="hidden" name="synthetic" value="<?= e($syn) ?>">
    <label class="f" for="adminQ">Search by name</label>
    <input class="f" id="adminQ" name="q" type="search" autocomplete="off" placeholder="Name, email, phone, or organization" value="<?= e($find) ?>">
    <div class="hint">Accents and punctuation are ignored. Several words must all match, in any order.</div>
  </form>
  <div id="findResults" style="margin-top:16px">
    <?= $this->partial('admin/_console-find-results', ['slug' => $slug, 'find' => $find, 'hits' => $hits, 'hitsTotal' => $hitsTotal, 'counts' => $counts, 'stats' => $stats]) ?>
  </div>
  <script>
    (function () {
      // adminSearch(): results as you type. The list is the server's, so typing refetches it.
      var form = document.querySelector('[data-console-find]');
      var out = document.getElementById('findResults');
      if (!form || !out) return;
      var box = form.querySelector('input[name="q"]');
      var timer = null, seq = 0;
      function refresh() {
        var params = new URLSearchParams(new FormData(form));
        params.set('results', '1');
        var mine = ++seq;
        fetch(form.getAttribute('action') + '?' + params.toString(), { headers: { Accept: 'text/html' } })
          .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
          .then(function (html) { if (mine === seq) out.innerHTML = html; })
          .catch(function () {});
      }
      box.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(refresh, 180); });
      form.addEventListener('submit', function (event) { event.preventDefault(); refresh(); });
      box.focus();
      box.setSelectionRange(box.value.length, box.value.length);
    })();
  </script>

<?php elseif ($tab === 'data'): ?>
  <?php if (!$canSeed): ?>
    <p class="muted small">Generating and removing demo data needs the <b>he.seed</b> capability.</p>
  <?php else: ?>
    <div class="checks">
      <div class="row"><span style="flex:1">Generate a synthetic dataset (organizations, respondents, archetype mix)</span>
        <a class="btn gold sm" href="<?= e(url('admin/' . $slug . '/data-seeder')) ?>" data-modal-src="<?= e(url('admin/' . $slug . '/data-seeder') . '?fragment=1') ?>">Seeder</a></div>
      <div class="row"><span style="flex:1">Restore the small default demo set</span>
        <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder/default')) ?>">
          <input type="hidden" name="_token" value="<?= e($csrf) ?>">
          <button class="btn quiet sm" type="submit" data-busy="Restoring…">Default</button>
        </form></div>
      <div class="row" style="border-bottom:1px solid var(--rule-soft)"><span style="flex:1">Delete synthetic records only. Real submissions survive.</span>
        <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder/purge')) ?>">
          <input type="hidden" name="_token" value="<?= e($csrf) ?>">
          <button class="btn quiet sm" type="submit" data-busy="Deleting…">Delete</button>
        </form></div>
    </div>
  <?php endif; ?>

<?php else: ?>
  <?php if (!$canExport): ?>
    <p class="muted small">Downloading the dataset needs the <b>he.export</b> capability.</p>
  <?php else: ?>
    <div class="checks">
      <div class="row"><span style="flex:1">Full research report · every scorecard, dimension means, level distribution</span>
        <button class="btn gold sm" type="button" data-report-pdf="<?= e($export('report/data')) ?>" data-jspdf="<?= e($jspdf) ?>">PDF</button></div>
      <div class="row"><span style="flex:1">Records with all 16 item marks · names, contact details and organization names removed</span>
        <a class="btn quiet sm" href="<?= e($export('export/csv')) ?>">CSV</a></div>
      <div class="row"><span style="flex:1">Full structured dataset · names, contact details and organization names removed</span>
        <a class="btn quiet sm" href="<?= e($export('export/json')) ?>">JSON</a></div>
      <div class="row"><span style="flex:1">Codebook · instrument, ladder, scoring rules</span>
        <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/codebook')) ?>">JSON</a></div>
      <div class="row"><span style="flex:1;color:var(--paper);font-weight:600">Identified (restricted)</span></div>
      <div class="row"><span style="flex:1">Records with names, contact details and organization names</span>
        <a class="btn quiet sm" href="<?= e($export('export/csv-identified')) ?>" onclick="return confirm(<?= e(json_encode($identified)) ?>)">CSV</a></div>
      <div class="row" style="border-bottom:1px solid var(--rule-soft)"><span style="flex:1">Full structured dataset including names, contact details and organization names</span>
        <a class="btn quiet sm" href="<?= e($export('export/json-identified')) ?>" onclick="return confirm(<?= e(json_encode($identified)) ?>)">JSON</a></div>
    </div>
    <p class="small muted" style="margin-top:10px">Exports respect the &ldquo;include synthetic&rdquo; toggle on the Research data page (currently <b style="color:var(--paper)"><?= $includeSynthetic ? 'on' : 'off' ?></b>).</p>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php /* data-close-modal is bound only inside an open dialog; on the page this is a link back. */ ?>
<div class="nav-row"><span></span><a class="btn sm" data-close-modal href="<?= e(url('admin/' . $slug . '/research-data') . '?synthetic=' . $syn) ?>">Close</a></div>
