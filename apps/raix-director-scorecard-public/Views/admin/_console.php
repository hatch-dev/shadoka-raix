<?php
/**
 * The admin console — the reference's `adminPanel()` dialog.
 *
 * A partial because it is rendered twice, like the seeder form: into the
 * dialog the Research data screen opens, and on its own page for a browser
 * without scripting. One copy, so the two cannot drift.
 *
 * Three deliberate differences from that build:
 *
 *  - Its tabs were `adminTab()` swapping innerHTML; here each is a URL, so a
 *    pane can be linked and reloaded. Inside the dialog the link re-opens the
 *    dialog on the other pane; on the page it is an ordinary link.
 *  - Its subtitle called the PIN gate demo-grade. It was: a constant in client
 *    JavaScript. This console's actions are guarded server-side and written to
 *    the audit log, so the sentence would be false here and is not repeated.
 *  - Its rows were <label>s wrapping their own button, where clicking the
 *    sentence presses the button next to it. Here they are plain rows.
 *
 * The fourth Data row, "Wipe every record, real and synthetic", does what it
 * says: it deletes real research submissions, and nothing in this application
 * can bring them back. There it emptied a browser store and asked confirm().
 * Here it needs the word typed, so that it cannot be a slip and so that it is
 * still guarded for a browser with no scripting, and the audit entry naming
 * who did it is written before the rows go.
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
  <?= e($counts['real']) ?> real · <?= e($counts['synthetic']) ?> synthetic
  scorecard<?= $counts['synthetic'] === 1 ? '' : 's' ?>.
  Everything below is recorded in the audit log.
</p>

<div class="dash-tabs" style="margin-top:0">
  <a class="<?= $tab === 'data' ? 'on' : '' ?>" href="<?= e($pane('data')) ?>"
     data-modal-src="<?= e($pane('data') . '&fragment=1') ?>" data-modal-wide>Data</a>
  <a class="<?= $tab === 'export' ? 'on' : '' ?>" href="<?= e($pane('export')) ?>"
     data-modal-src="<?= e($pane('export') . '&fragment=1') ?>" data-modal-wide>Export</a>
</div>

<div id="adminPane">
  <?php if ($tab === 'data'): ?>

    <?php if (!$canSeed): ?>
      <p class="muted small">Generating and removing demo data needs the <b>dsp.seed</b> capability.</p>
    <?php else: ?>
      <div class="checks">
        <div class="row">
          <span style="flex:1">Generate a synthetic dataset (boards, years, directors, archetype mix)</span>
          <a class="btn gold sm" href="<?= e(url('admin/' . $slug . '/data-seeder')) ?>"
             data-modal-src="<?= e(url('admin/' . $slug . '/data-seeder') . '?fragment=1') ?>">Seeder</a>
        </div>

        <?php
        /*
         * The reference's applySeed({count:14, seed:20260908}) — the same
         * fourteen boards off the same seed, so "Default" means one fixed set
         * rather than a fresh random one. The rest are the generator's own
         * defaults, stated here because the route requires every field.
         */
        ?>
        <div class="row">
          <span style="flex:1">Restore the small default demo set</span>
          <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder')) ?>"
                onsubmit="return confirm('Replace the synthetic records with the default demo set? Real scorecards are untouched.')">
            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="count" value="14">
            <input type="hidden" name="seed" value="20260908">
            <input type="hidden" name="years_back" value="3">
            <input type="hidden" name="board_share" value="0.35">
            <input type="hidden" name="spread" value="0.5">
            <button class="btn quiet sm" type="submit" data-busy="Restoring…">Default</button>
          </form>
        </div>

        <div class="row"<?= $canWipe ? '' : ' style="border-bottom:1px solid var(--rule-soft)"' ?>>
          <span style="flex:1">Delete synthetic records only. Real submissions survive.</span>
          <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder/purge')) ?>"
                onsubmit="return confirm('Remove every synthetic record? Real submissions are not touched.')">
            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
            <?php /* Back to the dataset, not to the seeder page: that is where
                     this was pressed and where the records it removed were. */ ?>
            <input type="hidden" name="return" value="research-data">
            <button class="btn quiet sm" type="submit" data-busy="Deleting…">Delete</button>
          </form>
        </div>

        <?php if ($canWipe): ?>
          <div class="row" style="border-bottom:1px solid var(--rule-soft)">
            <span style="flex:1;color:var(--r1)">
              Wipe every record, real and synthetic. The demo set will not reseed itself.
            </span>
            <?php
            /*
             * The typed word is the gate, not the confirm(): this is the one
             * control here that destroys something no seed can rebuild, and it
             * has to hold for a browser where confirm() never runs.
             */
            ?>
            <form method="post" action="<?= e(url('admin/' . $slug . '/console/wipe')) ?>"
                  onsubmit="return confirm('Delete every record, real and synthetic? This cannot be undone.')"
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
      <p class="muted small">Downloading the dataset needs the <b>dsp.export</b> capability.</p>
    <?php else: ?>
      <div class="checks">
        <?php
        /*
         * The reference's exportFullPDF(), which report-pdf.js draws from the
         * figures at /report/data. The href is real and is the printable
         * report page: without scripting, that page is what "PDF" gives you.
         */
        ?>
        <div class="row">
          <span style="flex:1">Full research report · every scorecard, competency means, level distribution</span>
          <a class="btn gold sm" href="<?= e($export('report')) ?>"
             data-report-pdf="<?= e($export('report/data')) ?>"
             data-jspdf="<?= e(app_asset($slug, 'vendor/jspdf.umd.min.js')) ?>">PDF</a>
        </div>
        <div class="row">
          <span style="flex:1">Records with all 35 item marks</span>
          <a class="btn quiet sm" href="<?= e($export('export/csv')) ?>">CSV</a>
        </div>
        <div class="row">
          <span style="flex:1">Full structured dataset including the named records behind each mark</span>
          <a class="btn quiet sm" href="<?= e($export('export/json')) ?>">JSON</a>
        </div>
        <div class="row" style="border-bottom:1px solid var(--rule-soft)">
          <span style="flex:1">Codebook · instrument, ladder, scoring rules</span>
          <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/codebook')) ?>">JSON</a>
        </div>
      </div>

      <p class="small muted" style="margin-top:10px">
        Exports respect the “include synthetic” toggle on the Research data page
        (currently <b style="color:var(--paper)"><?= $includeSynthetic ? 'on' : 'off' ?></b>).
        The PDF is built in your browser and downloads; with scripting off the
        same report opens as a printable page instead.
      </p>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php
/*
 * data-close-modal is bound only inside an open dialog, so on the page this
 * stays an ordinary link back to the dataset.
 */
?>
<div class="nav-row">
  <span></span>
  <a class="btn sm" data-close-modal
     href="<?= e(url('admin/' . $slug . '/research-data') . ($includeSynthetic ? '?synthetic=1' : '')) ?>">Close</a>
</div>
