<?php
/**
 * The admin console — the reference's `adminPanel()` dialog.
 *
 * A partial because it is rendered twice, like the seeder form: into the dialog
 * the Research data screen opens, and on its own page for a browser without
 * scripting. One copy, so the two cannot drift.
 *
 * Three deliberate differences from that build:
 *
 *  - Its tabs were `adminTab()` swapping innerHTML; here each is a URL, so a pane
 *    can be linked and reloaded. Inside the dialog the link re-opens the dialog
 *    on the other pane; on the page it is an ordinary link.
 *  - Its subtitle called the PIN gate demo-grade. It was: a constant in client
 *    JavaScript. This console's actions are guarded server-side and written to
 *    the audit log, so the sentence would be false here and is not repeated.
 *  - Its rows were <label>s wrapping their own button, so clicking the sentence
 *    pressed the button beside it. Here they are plain rows.
 *
 * Its Latest and Find tabs are not here, and are not missing: both are on the
 * Research data screen itself, where the newest real submission sits above the
 * list and the search box is the list's own. The reference needed them in a
 * dialog because its dataset page could only filter an array in memory.
 *
 * The fourth Data row, "Wipe", deletes every synthetic record and the previous
 * edition's real submissions. Since the v4 page it does NOT delete the
 * scorecards that page saved (exr_records): directive v4 allows no bulk
 * deletion of real records, so those go one at a time, from Requests. The
 * previous edition's rows are real research submissions, and on this instrument those rows name contactable
 * people. Nothing in this application can bring them back. There it emptied a
 * browser store and asked confirm(); here it needs the word typed, so it cannot
 * be a slip and is still guarded for a browser with no scripting, and the audit
 * entry naming who did it is written before the rows go.
 *
 * @var \Platform\Core\View $this
 */
$export = static fn (string $path): string => url('admin/' . $slug . '/' . $path)
    . ($includeSynthetic ? '?synthetic=1' : '');

$pane = static fn (string $key): string => url('admin/' . $slug . '/console')
    . '?tab=' . $key . ($includeSynthetic ? '&synthetic=1' : '');
?>
<h3>Admin console</h3>
<p class="muted small" style="margin-bottom:14px">
  <?= e($counts['real']) ?> real &middot; <?= e($counts['synthetic']) ?> synthetic
  scorecard<?= $counts['synthetic'] === 1 ? '' : 's' ?>.
  Everything below is recorded in the audit log.
</p>

<?php /* The reference's four tabs, in its order, opening on Latest. */ ?>
<div class="dash-tabs" style="margin-top:0">
  <?php foreach (['latest' => 'Latest', 'find' => 'Find', 'data' => 'Data', 'export' => 'Export'] as $key => $label): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>" href="<?= e($pane($key)) ?>"
       data-modal-src="<?= e($pane($key) . '&fragment=1') ?>" data-modal-wide><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div id="adminPane">
  <?php if ($tab === 'latest'): ?>

    <?php
    /*
     * The reference's adminLatestPane(): the newest scorecard, then the five
     * before it, with its own include-synthetic box. The box is a link here —
     * ticking it reloads this pane with the other setting.
     */
    $latestToggle = url('admin/' . $slug . '/console') . '?tab=latest'
        . ($includeSynthetic ? '&synthetic=1' : '')
        . ($latestSynthetic ? '' : '&lsyn=1');
    $hiddenSyn = (int) $counts['synthetic'];
    $toggle = static fn (string $style, string $text): string =>
        '<a class="small muted" href="' . e($latestToggle) . '" data-modal-src="' . e($latestToggle . '&fragment=1') . '" data-modal-wide'
        . ' style="display:flex;gap:8px;align-items:center;cursor:pointer;text-decoration:none;' . $style . '">'
        . '<input type="checkbox" tabindex="-1"' . ($latestSynthetic ? ' checked' : '') . ' style="accent-color:var(--r1);pointer-events:none"> '
        . e($text) . '</a>';
    ?>

    <?php if ($latest === []): ?>
      <div class="empty">
        <b>No <?= $latestSynthetic ? '' : 'real ' ?>scorecards yet</b>
        <?php if ($hiddenSyn > 0 && !$latestSynthetic): ?>
          The store holds <?= e($hiddenSyn) ?> synthetic record<?= $hiddenSyn === 1 ? '' : 's' ?>. Tick the box to include them.
        <?php else: ?>
          Nothing has been submitted.
        <?php endif; ?>
      </div>
      <?php if ($hiddenSyn > 0): ?>
        <?= $toggle('margin-top:12px', 'include synthetic records') ?>
      <?php endif; ?>
    <?php else: ?>
      <div class="eyebrow" style="margin-bottom:8px">Most recent submission</div>
      <?= $this->partial('admin/_rec-row', ['record' => $latest[0], 'slug' => $slug]) ?>
      <p class="small muted" style="margin-top:10px">Click any row for the full scorecard, the contact details, and all 24 ratings.</p>
      <?php if (count($latest) > 1): ?>
        <div class="eyebrow" style="margin:20px 0 8px">Before that</div>
        <?php foreach (array_slice($latest, 1) as $record): ?>
          <?= $this->partial('admin/_rec-row', ['record' => $record, 'slug' => $slug]) ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <?= $toggle('margin-top:14px;border-top:1px solid var(--rule-soft);padding-top:12px',
          'include ' . $hiddenSyn . ' synthetic record' . ($hiddenSyn === 1 ? '' : 's')) ?>
    <?php endif; ?>

  <?php elseif ($tab === 'find'): ?>

    <?php
    /*
     * The reference's adminFindPane(): one box, results as you type. The list
     * is the server's, so typing refetches it (results=1); submitting the form
     * does the same with scripting off.
     */
    $findUrl = url('admin/' . $slug . '/console');
    ?>
    <form method="get" action="<?= e($findUrl) ?>" data-console-find>
      <input type="hidden" name="tab" value="find">
      <?php if ($includeSynthetic): ?><input type="hidden" name="synthetic" value="1"><?php endif; ?>
      <label class="f" for="adminQ">Search by name</label>
      <input class="f" id="adminQ" name="q" type="search" autocomplete="off"
             placeholder="Name, email, phone, or organization" value="<?= e($find) ?>">
      <div class="hint">Accents and punctuation are ignored. Several words must all match, in any order.</div>
    </form>
    <div id="findResults" style="margin-top:16px">
      <?= $this->partial('admin/_console-find-results', [
          'slug' => $slug, 'find' => $find, 'hits' => $hits, 'hitsTotal' => $hitsTotal, 'counts' => $counts,
      ]) ?>
    </div>
    <script>
      (function () {
        var form = document.querySelector('[data-console-find]');
        var out = document.getElementById('findResults');
        if (!form || !out) return;
        var box = form.querySelector('input[name="q"]');
        var timer = null;
        var seq = 0;
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
      <p class="muted small">Generating and removing demo data needs the <b>exr.seed</b> capability.</p>
    <?php else: ?>
      <div class="checks">
        <div class="row">
          <span style="flex:1">Generate a synthetic dataset (organizations, years, respondents, archetype mix)</span>
          <a class="btn gold sm" href="<?= e(url('admin/' . $slug . '/data-seeder')) ?>"
             data-modal-src="<?= e(url('admin/' . $slug . '/data-seeder') . '?fragment=1') ?>">Seeder</a>
        </div>

        <?php
        /*
         * The reference's applySeed({count:14, seed:20260908}) — the same fourteen
         * organisations off the same seed, so "Default" means one fixed set rather
         * than a fresh random one. It has its own route because, like that call,
         * it replaces the synthetic records rather than adding to them.
         */
        ?>
        <div class="row">
          <span style="flex:1">Restore the small default demo set</span>
          <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder/default')) ?>"
                onsubmit="return confirm('Replace the synthetic records with the default demo set? Real scorecards are untouched.')">
            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
            <button class="btn quiet sm" type="submit" data-busy="Restoring…">Default</button>
          </form>
        </div>

        <div class="row"<?= $canWipe ? '' : ' style="border-bottom:1px solid var(--rule-soft)"' ?>>
          <span style="flex:1">Delete synthetic records only. Real submissions survive.</span>
          <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder/purge')) ?>"
                onsubmit="return confirm('Remove every synthetic record? Real submissions are not touched.')">
            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
            <?php /* Back to the dataset, not to the seeder page: that is where this
                     was pressed and where the records it removed were. */ ?>
            <input type="hidden" name="return" value="research-data">
            <button class="btn quiet sm" type="submit" data-busy="Deleting…">Delete</button>
          </form>
        </div>

        <?php if ($canWipe): ?>
          <div class="row" style="border-bottom:1px solid var(--rule-soft)">
            <span style="flex:1;color:var(--r1)">
              Wipe every synthetic record and every previous-edition submission. Scorecards saved by the v4 page are kept: delete those one at a time, from Requests. Blocks auto-reseed.
            </span>
            <?php
            /*
             * The typed word is the gate, not the confirm(): this is the one
             * control here that destroys something no seed can rebuild, and it has
             * to hold for a browser where confirm() never runs.
             */
            ?>
            <form method="post" action="<?= e(url('admin/' . $slug . '/console/wipe')) ?>"
                  onsubmit="return confirm('Delete every synthetic record and every previous-edition submission? Scorecards saved by the v4 page are kept. This cannot be undone.')"
                  style="display:flex;gap:8px;align-items:center">
              <input type="hidden" name="_token" value="<?= e($csrf) ?>">
              <input class="f" type="text" name="confirm" autocomplete="off" spellcheck="false"
                     placeholder="type <?= e($wipePhrase) ?>" aria-label="Type <?= e($wipePhrase) ?> to confirm"
                     style="width:118px;padding:7px 10px;font-size:14px">
              <button class="btn danger sm" type="submit" data-busy="Wiping…">Wipe</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>

    <?php if (!$canExport): ?>
      <p class="muted small">Downloading the dataset needs the <b>exr.export</b> capability.</p>
    <?php else: ?>
      <div class="checks">
        <?php
        /*
         * The reference's exportFullPDF(), which admin-pdf.js draws from the
         * figures at /report/data. The href is real and is the printable report
         * page: without scripting, that page is what "PDF" gives you.
         */
        ?>
        <div class="row">
          <span style="flex:1">Full research report &middot; every scorecard, dimension means, level distribution</span>
          <button class="btn gold sm" type="button"
             data-report-pdf="<?= e($export('report/data')) ?>"
             data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">PDF</button>
        </div>
        <div class="row">
          <span style="flex:1">Records with all 24 ratings and both score profiles</span>
          <a class="btn quiet sm" href="<?= e($export('export/csv')) ?>">CSV</a>
        </div>
        <div class="row">
          <span style="flex:1">Full structured dataset including the named records behind each mark</span>
          <a class="btn quiet sm" href="<?= e($export('export/json')) ?>">JSON</a>
        </div>
        <div class="row" style="border-bottom:1px solid var(--rule-soft)">
          <span style="flex:1">Codebook &middot; instrument, ladder, scoring rules</span>
          <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/codebook')) ?>">JSON</a>
        </div>
      </div>

      <p class="small muted" style="margin-top:10px">
        Exports respect the &ldquo;include synthetic&rdquo; toggle on the Research
        data page (currently <b style="color:var(--paper)"><?= $includeSynthetic ? 'on' : 'off' ?></b>).
      </p>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php
/*
 * data-close-modal is bound only inside an open dialog, so on the page this stays
 * an ordinary link back to the dataset.
 */
?>
<div class="nav-row">
  <span></span>
  <a class="btn sm" data-close-modal
     href="<?= e(url('admin/' . $slug . '/research-data') . ($includeSynthetic ? '?synthetic=1' : '')) ?>">Close</a>
</div>
