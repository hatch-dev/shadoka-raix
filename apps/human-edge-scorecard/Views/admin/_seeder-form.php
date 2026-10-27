<?php
/**
 * The page's seederForm(), field for field: Boards (organizations), Seed,
 * share of organizations with several respondents, respondents per
 * organization, variation, and the archetype mix sliders.
 *
 * Rendered into the dialog the bar, Research data and the console open, and on
 * the seeder page for a browser without scripting.
 *
 * @var string $slug
 * @var string $csrf
 * @var string $error
 * @var int $seed
 * @var array<string, array<string, mixed>> $archetypes
 */
?>
<h3>Generate synthetic dataset</h3>
<p class="muted small" style="margin-bottom:14px">Replaces existing synthetic records. Real scorecards are untouched. Everything generated is flagged and excluded from benchmarks.</p>

<form method="post" action="<?= e(url('admin/' . $slug . '/data-seeder')) ?>">
  <input type="hidden" name="_token" value="<?= e($csrf) ?>">
  <div class="form">
    <div><label class="f" for="sd-count">Boards</label><input class="f" id="sd-count" name="count" type="number" min="1" max="500" value="40"></div>
    <div><label class="f" for="sd-seed">Seed</label><input class="f" id="sd-seed" name="seed" type="number" value="<?= e($seed) ?>"></div>
    <div><label class="f" for="sd-ws">Share of organizations with several respondents</label><select class="f" id="sd-ws" name="board_share"><option value="0">None</option><option value=".2">20%</option><option value=".35" selected>35%</option><option value=".6">60%</option><option value="1">All</option></select></div>
    <div><label class="f" for="sd-raters">Respondents per organization</label><select class="f" id="sd-raters" name="raters"><option value="2,3">2–3</option><option value="3,5" selected>3–5</option><option value="5,7">5–7</option></select></div>
    <div><label class="f" for="sd-spread">Variation between respondents</label><select class="f" id="sd-spread" name="spread"><option value=".25">Tight</option><option value=".5" selected>Realistic</option><option value=".85">Wide</option></select></div>
    <div class="full"><label class="f">Archetype mix</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 14px">
        <?php foreach ($archetypes as $key => $archetype): ?>
          <label style="display:flex;gap:8px;align-items:center;font-size:13px"><input type="range" id="sd-a-<?= e($key) ?>" name="archetypes[<?= e($key) ?>]" min="0" max="100" value="<?= e((int) round($archetype['weight'] * 100)) ?>" style="flex:1;accent-color:var(--gold)"><span style="min-width:96px;font-family:var(--mono);font-size:12px"><?= e($key) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="hint"><?= implode(' · ', array_map(
          static fn (string $key, array $a): string => '<b style="color:var(--paper)">' . e($key) . '</b>: ' . e($a['note']),
          array_keys($archetypes),
          $archetypes
      )) ?></div></div>
  </div>
  <div class="err" id="sd-err"><?= e($error) ?></div>
  <div class="nav-row">
    <a class="btn quiet sm" data-close-modal href="<?= e(url('admin/' . $slug . '/research-data')) ?>">Cancel</a>
    <button class="btn gold sm" type="submit" data-busy="Generating…">Generate</button>
  </div>
</form>
