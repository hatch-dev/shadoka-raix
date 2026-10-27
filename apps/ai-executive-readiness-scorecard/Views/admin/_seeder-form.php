<?php
/**
 * The synthetic dataset form — the reference's `seederForm()` dialog.
 *
 * A partial because it is rendered twice: inside the Data seeder page, and on its
 * own into the dialog the nav opens, which is how the reference presents it. One
 * copy, so the two cannot drift.
 *
 * Every control is the reference's, with its own wording and its own options. The
 * archetype sliders start at the weights the instrument JSON carries, so the form
 * opens on the mix the generator would use if nobody touched it.
 *
 * @var \Platform\Core\View $this
 * @var array<string, array{weight:float, targets:list<float>, emerging:bool, note:string}> $archetypes
 */
?>
<h3>Generate synthetic dataset</h3>
  <p class="muted small" style="margin-bottom:14px">
    Replaces existing synthetic records. Real scorecards are untouched. Everything
    generated is flagged and excluded from benchmarks.
  </p>

  <form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder')) ?>" id="seedForm">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">

    <div class="form">
      <div>
                <label class="f" for="count">Organizations</label>
        <input class="f" id="count" name="count" type="number" min="1" max="500" value="40" required>
      </div>

      <div>
        <label class="f" for="seed">Seed</label>
        <?php /* Random, so a fresh dataset each visit unless one is typed in. The
                 same seed always produces the same dataset. */ ?>
        <input class="f" id="seed" name="seed" type="number" min="1" max="2147483647"
               value="<?= e(random_int(1, 999999)) ?>" required>
      </div>

      <div>
        <label class="f" for="years_back">Years back (incl. this year)</label>
        <select class="f" id="years_back" name="years_back">
          <option value="1"><?= e($year) ?> only</option>
          <option value="2"><?= e($year - 1) ?>&ndash;<?= e($year) ?></option>
          <option value="3" selected><?= e($year - 2) ?>&ndash;<?= e($year) ?></option>
          <option value="4"><?= e($year - 3) ?>&ndash;<?= e($year) ?></option>
        </select>
      </div>

      <div>
        <label class="f" for="multi_share">Share of organizations with several respondents</label>
        <select class="f" id="multi_share" name="multi_share">
          <option value="0">None</option>
          <option value="0.2">20%</option>
          <option value="0.35" selected>35%</option>
          <option value="0.6">60%</option>
          <option value="1">All</option>
        </select>
      </div>

      <div>
        <label class="f" for="respondents">Respondents per organization</label>
        <select class="f" id="respondents" name="respondents">
          <option value="2,3">2&ndash;3</option>
          <option value="3,5" selected>3&ndash;5</option>
          <option value="5,7">5&ndash;7</option>
        </select>
      </div>

      <div>
        <label class="f" for="spread">Variation between respondents</label>
        <select class="f" id="spread" name="spread">
          <option value="0.25">Tight</option>
          <option value="0.5" selected>Moderate</option>
          <option value="0.85">Wide</option>
        </select>
      </div>

      <div class="full">
        <label class="f">Archetype mix</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 14px">
          <?php foreach ($archetypes as $key => $archetype): ?>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px">
              <input type="range" name="archetypes[<?= e($key) ?>]" id="arch-<?= e($key) ?>"
                     min="0" max="100" value="<?= e((int) round($archetype['weight'] * 100)) ?>"
                     style="flex:1;accent-color:var(--gold)">
              <span style="min-width:104px;font-family:var(--mono);font-size:12px"><?= e($key) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="hint">
          <?php
          $notes = [];
          foreach ($archetypes as $key => $archetype) {
              $notes[] = '<b style="color:var(--paper)">' . e($key) . '</b>: ' . e($archetype['note']);
          }
          ?>
          <?= implode(' &middot; ', $notes) ?>
        </div>
      </div>
    </div>

    <div class="err" id="seedError"><?= e($error ?? '') ?></div>

    <div class="nav-row" style="margin-top:18px">
      <?php
      /*
       * data-close-modal is bound only inside an open dialog, so here on the page
       * the attribute is inert and this stays an ordinary link.
       */
      ?>
      <a class="btn quiet sm" data-close-modal
         href="<?= e(url('admin/' . $slug . '/research-data')) ?>">Cancel</a>
      <?php
      /*
       * data-busy is read by admin-chrome.js: the button disables itself and says
       * this until the reply lands. Forty organisations with several respondents
       * each is a few seconds of generating, and a button that looks idle for that
       * long gets pressed again — which would run the whole thing twice.
       */
      ?>
      <button class="btn gold sm" type="submit" data-busy="Generating…">Generate</button>
    </div>
  </form>
