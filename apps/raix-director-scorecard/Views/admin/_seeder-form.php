<?php
/**
 * The synthetic dataset form.
 *
 * A partial because it is rendered twice: inside the Data seeder page, and
 * on its own into the dialog the nav opens — which is how the reference
 * presents it. One copy, so the two cannot drift.
 *
 * @var \Platform\Core\View $this
 */
?>
<h3>Generate synthetic dataset</h3>
  <div class="cap">
    Replaces existing synthetic records. Real scorecards are untouched. Everything
    generated is flagged and excluded from benchmarks.
  </div>

  <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder')) ?>" id="seedForm">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">

    <div class="form">
      <div>
        <label class="f" for="count">Boards</label>
        <input class="f" id="count" name="count" type="number" min="1" max="500" value="40" required>
      </div>

      <div>
        <label class="f" for="seed">Seed</label>
        <?php /* Random, so a fresh dataset each visit unless one is typed in. */ ?>
        <input class="f" id="seed" name="seed" type="number" min="1" max="2147483647"
               value="<?= e(random_int(1, 999999)) ?>" required>
      </div>

      <div>
        <label class="f" for="years_back">Years back (incl. this year)</label>
        <select class="f" id="years_back" name="years_back">
          <option value="1"><?= e($year) ?> only</option>
          <option value="2"><?= e($year - 1) ?>–<?= e($year) ?></option>
          <option value="3" selected><?= e($year - 2) ?>–<?= e($year) ?></option>
          <option value="4"><?= e($year - 3) ?>–<?= e($year) ?></option>
        </select>
      </div>

      <div>
        <label class="f" for="board_share">Share of boards with several directors scored</label>
        <select class="f" id="board_share" name="board_share">
          <option value="0">None</option>
          <option value="0.2">20%</option>
          <option value="0.35" selected>35%</option>
          <option value="0.6">60%</option>
          <option value="1">All</option>
        </select>
      </div>

      <?php
      /*
       * How many directors score each of the boards picked by the share above.
       * The generator has always accepted this; the form simply never asked,
       * so every multi-director board got the 3–5 default.
       */
      ?>
      <div>
        <label class="f" for="raters">Directors per board</label>
        <select class="f" id="raters" name="raters">
          <option value="2,3">2–3</option>
          <option value="3,5" selected>3–5</option>
          <option value="5,7">5–7</option>
        </select>
      </div>

      <div>
        <label class="f" for="spread">Variation between directors</label>
        <select class="f" id="spread" name="spread">
          <option value="0.25">Tight</option>
          <option value="0.5" selected>Realistic</option>
          <option value="0.85">Wide</option>
        </select>
      </div>

      <?php
      /*
       * Adjustable, as in the reference. These were printed as fixed
       * percentages before — the generator took weights all along, so the
       * screen was describing a mix it gave you no way to change.
       */
      ?>
      <div class="full">
        <label class="f">Archetype mix</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 14px">
          <?php foreach ($archetypes as $key => $archetype): ?>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px">
              <input type="range" name="archetypes[<?= e($key) ?>]" id="arch-<?= e($key) ?>"
                     min="0" max="100" value="<?= e((int) round($archetype['weight'] * 100)) ?>"
                     style="flex:1;accent-color:var(--gold)">
              <span style="min-width:96px;font-family:var(--mono);font-size:12px"><?= e($key) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="hint">
          <?php $notes = [];
          foreach ($archetypes as $key => $archetype) {
              $notes[] = '<b style="color:var(--paper)">' . e($key) . '</b>: ' . e($archetype['note']);
          } ?>
          <?= implode(' · ', $notes) ?>
        </div>
      </div>
    </div>

    <div class="err" id="seedError"><?= e($error ?? '') ?></div>

    <div class="nav-row" style="margin-top:18px">
      <?php
      /*
       * data-close-modal is bound only inside an open dialog, so here on the
       * page the attribute is inert and this stays an ordinary link.
       */
      ?>
      <a class="btn quiet sm" data-close-modal
         href="<?= e(url('admin/' . $slug . '/analytics')) ?>">Cancel</a>
      <?php
      /*
       * data-busy is read by admin-chrome.js: the button disables itself and
       * says this until the reply lands. Forty boards is a few seconds of
       * generating, and a button that looks idle for that long gets pressed
       * again — which would have run the whole thing twice.
       */
      ?>
      <button class="btn gold sm" type="submit" data-busy="Generating…">Generate</button>
    </div>
  </form>
