<?php
/**
 * The research dataset.
 *
 * Built to the single-file build's Research data screen, which is the design
 * being matched: the same eyebrow and title, the same toolbar on the title
 * row, the same three stat cards, the same three tabs, and records as rows
 * rather than as a table — board, a line of context, a row of competency
 * pills, and the level at the right in the rung's own colour.
 *
 * The deliberate differences, all of them things a research tool needs and a
 * single HTML file could not do:
 *
 *  - The tabs are URLs, not `dataTab` in a closure, so a pane can be linked,
 *    bookmarked and reloaded. Same reasoning as the analytics screen.
 *  - Pagination. It rendered every record into the document; 40 to a page
 *    here, so the screen does not grow with the corpus.
 *  - A record opens its own scorecard, with the item-level marks. There the
 *    row opened the board it belonged to.
 *  - Console opens a fetched dialog rather than a built one, and its own page
 *    exists behind it. The rows inside are that build's, less the wipe.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$fmt  = static fn (?string $v): string => $v === null ? '—' : number_format((float) $v, 2);
$date = static fn (?string $d): string => $d === null
    ? '—'
    : date((string) config('app.date_format'), strtotime($d));

/* The band a score falls in, by the instrument's cut points rather than by a
   comparison against rung VALUES — 1.80 is Aware, not Unaware. The shared
   competency bars read this. */
$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

/** This screen's URL with some controls changed and the rest kept. */
$query = static function (array $overrides) use ($includeSynthetic, $tab, $slug): string {
    $params = array_filter(
        $overrides + [
            'tab'       => $tab === 'records' ? null : $tab,
            'synthetic' => $includeSynthetic ? '1' : null,
        ],
        static fn ($v): bool => $v !== null && $v !== ''
    );

    return url('admin/' . $slug . '/research-data') . ($params ? '?' . http_build_query($params) : '');
};
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Research data</div>
    <h1 class="title" style="margin-bottom:0">The dataset</h1>
  </div>

  <div class="toolbar" style="margin:0">
    <?php
    /*
     * The reference's checkbox, and it navigates: the setting belongs in the
     * URL so a link to this screen carries what it is showing. It is rendered
     * whatever the synthetic count is, as there — a control that appears and
     * disappears under you is worse than one reading zero.
     */
    ?>
    <label class="small muted" style="display:flex;gap:8px;align-items:center;cursor:pointer">
      <input type="checkbox"<?= $includeSynthetic ? ' checked' : '' ?>
             onchange="location.href=this.checked
                 ? '<?= e($query(['synthetic' => '1', 'page' => null])) ?>'
                 : '<?= e($query(['synthetic' => null, 'page' => null])) ?>'"
             style="accent-color:var(--r1)">
      include <?= e($counts['synthetic']) ?> synthetic demo record<?= $counts['synthetic'] === 1 ? '' : 's' ?>
    </label>

    <?php if ($canSeed): ?>
      <?php /* Opens as a dialog, as the reference's seederForm() does. The href
               is real: without scripting the same form opens as a page. */ ?>
      <a class="btn gold sm" href="<?= e(url('admin/' . $slug . '/data-seeder')) ?>"
         data-modal-src="<?= e(url('admin/' . $slug . '/data-seeder') . '?fragment=1') ?>">Seed data</a>
    <?php endif; ?>

    <?php if ($canExport): ?>
      <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/json' . ($includeSynthetic ? '?synthetic=1' : ''))) ?>">JSON</a>
      <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/csv' . ($includeSynthetic ? '?synthetic=1' : ''))) ?>">CSV</a>
    <?php endif; ?>

    <?php
    /*
     * The codebook and the research report moved into here, which is where
     * that build kept them: Console → Export. The href is the same panel as a
     * page, so this works without scripting.
     */
    ?>
    <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/console') . ($includeSynthetic ? '?synthetic=1' : '')) ?>"
       data-modal-src="<?= e(url('admin/' . $slug . '/console')
            . '?fragment=1' . ($includeSynthetic ? '&synthetic=1' : '')) ?>" data-modal-wide>Console</a>
  </div>
</div>

<p class="lede" style="margin-top:10px">
  <?= e($counts['real']) ?> real scorecard<?= $counts['real'] === 1 ? '' : 's' ?>.
  Synthetic records are flagged, excluded from every benchmark, and excluded
  from export unless you tick the box. The data is self-reported, unverified,
  and not yet public.
</p>

<?php if ($canExport): ?>
  <p class="hint" style="margin:-14px 0 22px">
    CSV and JSON carry every one of the <?= e($itemCount) ?> item-level marks,
    not just competency scores, and follow the
    <b<?= $includeSynthetic ? ' style="color:var(--r1)"' : '' ?>>include-synthetic</b>
    setting above — which is stated inside the file. Correlations exclude
    synthetic records whatever this setting says: the seeder derives a
    synthetic director's context from their own score, so any relationship
    there was put in by hand.
  </p>
<?php endif; ?>

<?php if (($this->section('flash') === '') && !empty($_GET['deleted'])): ?>
  <div class="notice"><b>Scorecard deleted.</b> The action is recorded in the audit log.</div>
<?php endif; ?>

<div class="grid grid-3">
  <div class="stat">
    <div class="v"><?= e($total) ?></div>
    <div class="k">Records shown</div>
  </div>
  <div class="stat">
    <div class="v"><?= e($sectorCount) ?></div>
    <div class="k">Sectors</div>
  </div>
  <div class="stat">
    <div class="v"><?= e($fmt($overview['mean_floor'] === null ? null : (string) $overview['mean_floor'])) ?></div>
    <div class="k">Mean floor</div>
  </div>
</div>

<div class="tabs" style="margin-top:26px">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>"
       href="<?= e($query(['tab' => $key === 'records' ? null : $key, 'page' => null])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($total === 0): ?>

  <div class="empty">
    <b>Nothing here yet</b>
    Complete and save a scorecard, or tick the box to inspect the synthetic demo set.
    <?php if ($counts['in_progress'] > 0): ?>
      <br><?= e($counts['in_progress']) ?> scorecard<?= $counts['in_progress'] === 1 ? ' is' : 's are' ?> in progress.
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'records'): ?>

  <?php foreach ($records as $record): ?>
    <?php
    $marks = $scores[(int) $record['id']] ?? [];
    $meta  = array_filter([
        $record['director_name'],
        $record['sector'],
        $record['size_band'],
        $record['seat_role'],
        $record['assessment_year'],
        $date($record['submitted_at']),
    ], static fn ($v): bool => $v !== null && $v !== '');
    ?>
    <?php
    /*
     * A record row opens the profile of the BOARD it belongs to, as it did in
     * the reference — where the row's onclick was openOrg(), not a link to the
     * scorecard. The dialog is the same fragment the dashboard opens, so the
     * two screens cannot show a board differently.
     *
     * The href behind it is the board page, so ctrl-click and no-JS still land
     * somewhere useful — and that page lists this board's directors, each
     * linking to their own scorecard, which is how a single record is still
     * reached.
     *
     * A scorecard with no board (anonymous, or a director who named none) has
     * no profile to open, so that row points at the scorecard itself — and
     * opens it in the same dialog rather than navigating, which is what it did
     * before the scorecard had a fragment of its own: one row on a list of
     * dialogs that left the screen.
     */
    $organisationId = $record['organisation_id'] === null ? null : (int) $record['organisation_id'];
    $rowHref = $organisationId === null
        ? url('admin/' . $slug . '/research-data/' . $record['id'])
        : url('admin/' . $slug . '/analytics/board/' . $organisationId)
          . ($includeSynthetic ? '?synthetic=1' : '');
    $rowModal = $organisationId === null
        ? url('admin/' . $slug . '/research-data/' . $record['id']) . '?fragment=1'
        : url('admin/' . $slug . '/analytics/board/' . $organisationId)
          . '?fragment=1' . ($includeSynthetic ? '&synthetic=1' : '');
    ?>
    <a class="rec" href="<?= e($rowHref) ?>"
       data-modal-src="<?= e($rowModal) ?>" data-modal-wide
       style="--c:<?= e($record['level_colour'] ?? 'var(--stone)') ?>">
      <div>
        <div class="t">
          <?= e($record['board'] ?? 'Anonymous') ?>
          <?php if ((int) $record['is_synthetic'] === 1): ?>
            <span class="tag syn">SYNTHETIC</span>
          <?php endif; ?>
        </div>
        <div class="m"><?= e(implode(' · ', $meta)) ?></div>
        <div class="pills">
          <?php foreach ($competencies as $competency): ?>
            <span class="pill">
              <?= e($competency['code']) ?>
              <?= isset($marks[$competency['code']]) ? e(number_format($marks[$competency['code']], 2)) : '—' ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="s">
        <?= e($record['level_value'] ?? '—') ?>
        <small>Floor <?= e($fmt($record['floor_score'])) ?>, average <?= e($fmt($record['mean_score'])) ?></small>
      </div>
    </a>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
    <div style="display:flex;gap:8px;margin-top:18px;align-items:center">
      <?php if ($page > 1): ?>
        <a class="btn sm" href="<?= e($query(['page' => $page - 1])) ?>">← Previous</a>
      <?php endif; ?>
      <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
      <?php if ($page < $pages): ?>
        <a class="btn sm" href="<?= e($query(['page' => $page + 1])) ?>">Next →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'dims'): ?>

  <p class="muted small" style="margin-bottom:14px">
    Mean of each competency across the directors shown, with min–max range. The
    Floor tag marks the weakest competency across the set.
  </p>

  <?= $this->partial('admin/_bars', [
      'competencies' => $competencies,
      'bandOf'       => $bandOf,
      'fmt'          => static fn (?float $v): string => $v === null ? '—' : number_format($v, 2),
  ]) ?>

  <div class="chart-card" style="margin-top:24px;max-width:520px">
    <div style="height:280px"><canvas id="cRadarAn"></canvas></div>
  </div>

<?php else: ?>

  <div class="grid grid-2">
    <?php foreach ($levels as $rung): ?>
      <div class="stat" style="--c:<?= e($rung['colour']) ?>">
        <div class="v" style="color:var(--c)"><?= e($rung['n']) ?></div>
        <div class="k">Level <?= e($rung['value']) ?>, <?= e($rung['name']) ?></div>
        <div class="small muted" style="margin-top:6px"><?= e($rung['description']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin-top:14px">
    Level is set by each director’s weakest competency. A director can average
    Practiced and still sit here at Aware.
  </p>

<?php endif; ?>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<?php /* The console opens over this screen, and its PDF button is drawn here.
         jsPDF itself is fetched only when that button is pressed. */ ?>
<script src="<?= e(app_asset($slug, 'js/report-pdf.js')) ?>"></script>
<?php if ($total > 0 && $tab === 'dims'): ?>
  <script src="<?= e(app_asset($slug, 'vendor/chart.umd.min.js')) ?>"></script>
  <?php /* The same payload and the same drawing code the dashboard uses, so
           the two radars cannot be drawn from different figures. */ ?>
  <script type="application/json" id="chartData"><?= json_encode([
      'competencies' => $competencies,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="<?= e(asset('platform/js/dashboard-charts.js')) ?>"></script>
<?php endif; ?>
<?php $this->stop(); ?>
