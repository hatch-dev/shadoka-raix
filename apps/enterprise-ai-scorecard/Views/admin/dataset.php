<?php
/**
 * Research data — the dataset, with the newest real submission on top.
 *
 * The reference's screen, tab for tab: the stats, the include-synthetic toggle,
 * the most recent submission, the search box, and Records · Dimensions · Levels.
 *
 * Two differences, both because that build could not have done otherwise:
 *
 *  - The search runs in SQL over the whole dataset. Its `filterDataRecords()`
 *    filtered whatever array the browser was holding, which at any size worth
 *    searching is not all of it.
 *  - The tabs are URLs, so a pane can be linked to and reloaded.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');

$fmt = static fn (?float $v): string => $v === null ? '—' : number_format((float) $v, 2);

$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

/** A URL on this screen with one control changed and the rest kept. */
$link = static function (array $changes) use ($tab, $includeSynthetic, $search, $page, $slug): string {
    $query = array_merge([
        'tab'       => $tab,
        'synthetic' => $includeSynthetic ? '1' : '0',
        'q'         => $search,
        'page'      => (string) $page,
    ], $changes);

    $query = array_filter(
        $query,
        static fn (string $v, string $k): bool => $v !== '' && !($k === 'page' && $v === '1'),
        ARRAY_FILTER_USE_BOTH
    );

    return url('admin/' . $slug . '/research-data') . '?' . http_build_query($query);
};

/** How long ago, as the reference's `sinceLabel()`: minutes, hours, days, then the date. */
$since = static function (?string $at): string {
    if ($at === null || $at === '' || ($time = strtotime($at)) === false) {
        return '—';
    }

    $minutes = intdiv(time() - $time, 60);

    if ($minutes < 0) {
        return date((string) config('app.date_format'), $time);
    }

    $hours = intdiv($minutes, 60);
    $days  = intdiv($hours, 24);

    return match (true) {
        $minutes < 1  => 'just now',
        $minutes < 60 => $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago',
        $hours < 24   => $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago',
        $days < 45    => $days . ' day' . ($days === 1 ? '' : 's') . ' ago',
        default       => date((string) config('app.date_format'), $time),
    };
};

/** One record, as the reference's `dataRow()` drew it. */
$row = static function (array $record) use ($slug, $includeSynthetic, $fmt, $bandOf): string {
    $band = $bandOf($record['mean_score'] === null ? null : (float) $record['mean_score']);
    $href = url('admin/' . $slug . '/research-data/' . $record['id']);

    $contact = implode(' · ', array_filter([
        $record['respondent_email'] ?? null,
        $record['respondent_phone'] ?? null,
    ]));

    $context = implode(' · ', array_filter([
        $record['sector'] ?? null,
        $record['organisation_size'] ?? null,
        $record['role'] ?? null,
        $record['assessment_year'] ?? null,
    ]));

    // What the live search matches, as the reference's recHaystack(): name,
    // email, organization, role, sector and level, then the phone as digits.
    $hay   = implode(' ', array_filter([
        $record['respondent_name'] ?? null,
        $record['respondent_email'] ?? null,
        $record['organisation_name'] ?? null,
        $record['role'] ?? null,
        $record['sector'] ?? null,
        $record['level_name'] ?? null,
    ]));
    $phone = preg_replace('/\D/', '', (string) ($record['respondent_phone'] ?? ''));

    $html = '<a class="rec" style="--c:' . e($band['colour_hex'] ?? 'var(--stone)') . '"'
          . ' data-hay="' . e($hay) . '" data-phone="' . e((string) $phone) . '"'
          // Wide, as the reference's openRecord() made it.
          . ' href="' . e($href) . '" data-modal-src="' . e($href . '?fragment=1') . '" data-modal-wide>'
          . '<div><div class="t">' . e($record['respondent_name'] ?: 'Unnamed')
          . ' <span class="muted" style="font-weight:400">' . e($record['organisation_name']) . '</span>'
          . (!empty($record['is_synthetic']) ? ' <span class="tag syn">SYNTHETIC</span>' : '')
          . '</div>';

    $html .= '<div class="m">'
          . ($contact !== '' ? e($contact) : '<span class="muted">no contact details on this record</span>')
          . '</div>';

    $html .= '<div class="m">' . e($context);

    if (!empty($record['submitted_at'])) {
        $html .= ' · ' . e(date((string) config('app.date_format') . ', g:i A', strtotime((string) $record['submitted_at'])));
    }

    $html .= '</div>';

    // The six dimension scores, as the reference's row of pills.
    $html .= '<div class="pills">';
    foreach ($record['_scores'] ?? [] as $code => $score) {
        $html .= '<span class="pill">' . e($code) . ' ' . e($score === null ? '—' : number_format((float) $score, 2)) . '</span>';
    }
    $html .= '</div></div>';

    $html .= '<div class="s">' . e($record['level_value'] ?? '—')
          . '<small>Overall ' . e($fmt($record['mean_score']))
          . ', floor ' . e($fmt($record['floor_score']))
          . '</small></div></a>';

    return $html;
};
?>

<?php $this->start('content'); ?>
<?php
/*
 * The reference's head: the eyebrow, then the title with the dataset's tools on
 * the same row, then the newest real submission, then the lede. The toggle is a
 * checkbox there and here; ticking it reloads with the other setting, because
 * the list is the server's.
 */
?>
<div class="eyebrow">Research data</div>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap">
  <h1 class="title" style="margin-bottom:0">The dataset</h1>

  <div class="toolbar" style="margin:0">
    <label class="small muted" style="display:flex;gap:8px;align-items:center;cursor:pointer">
      <input type="checkbox" data-synthetic-toggle
             data-href="<?= e($link(['synthetic' => $includeSynthetic ? '0' : '1', 'page' => '1'])) ?>"
             <?= $includeSynthetic ? 'checked' : '' ?> style="accent-color:var(--r1)">
      include <?= e($counts['synthetic']) ?> synthetic demo records
    </label>
    <?php if ($canSeed): ?>
      <a class="btn gold sm" href="<?= e(url('admin/' . $slug . '/data-seeder')) ?>"
         data-modal-src="<?= e(url('admin/' . $slug . '/data-seeder') . '?fragment=1') ?>">Seed data</a>
    <?php endif; ?>
    <?php if ($canExport): ?>
      <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/json') . '?synthetic=' . ($includeSynthetic ? '1' : '0')) ?>">JSON</a>
      <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/export/csv') . '?synthetic=' . ($includeSynthetic ? '1' : '0')) ?>">CSV</a>
    <?php endif; ?>
    <a class="btn quiet sm" href="<?= e(url('admin/' . $slug . '/console') . '?synthetic=' . ($includeSynthetic ? '1' : '0')) ?>"
       data-modal-src="<?= e(url('admin/' . $slug . '/console') . '?synthetic=' . ($includeSynthetic ? '1' : '0') . '&fragment=1') ?>" data-modal-wide>Console</a>
  </div>
</div>

<?php if ($seeded > 0): ?>
  <div class="notice" style="margin-top:16px"><b><?= e($seeded) ?> synthetic scorecards generated.</b>
    They are flagged, and excluded from benchmarks.</div>
<?php endif; ?>
<?php if ($purged > 0): ?>
  <div class="notice" style="margin-top:16px"><b><?= e($purged) ?> synthetic records deleted.</b> Real submissions were untouched.</div>
<?php endif; ?>
<?php if ($wiped > 0): ?>
  <div class="notice" style="margin-top:16px"><b><?= e($wiped) ?> records deleted, real and synthetic.</b> This cannot be undone.</div>
<?php endif; ?>
<?php if ($deleted): ?>
  <div class="notice" style="margin-top:16px"><b>Scorecard deleted.</b> The deletion is in the audit log.</div>
<?php endif; ?>

<?php
/*
 * The newest REAL submission: the reference's `renderLatestStrip()`, drawn with
 * its `recRow()`. Synthetic rows never appear here however recent their
 * timestamp; a generated record is not something anybody took.
 */
?>
<?php if ($latest === null): ?>
  <div class="callout" style="margin-top:16px">
    <h4>No real submission yet</h4>
    <p>The newest scorecard appears here the moment someone completes one. Synthetic demo records are never shown as the latest.</p>
  </div>
<?php else: ?>
  <div class="eyebrow" style="margin-top:20px;margin-bottom:8px">
    Most recent submission &middot; <?= e($since($latest['submitted_at'] ?? null)) ?>
  </div>
  <?= $this->partial('admin/_rec-row', ['record' => $latest, 'slug' => $slug]) ?>
<?php endif; ?>

<p class="lede" style="margin-top:10px">
  <?= e($counts['real']) ?> real scorecard<?= $counts['real'] === 1 ? '' : 's' ?>.
  Synthetic records are flagged, excluded from every benchmark, and excluded from
  export unless you tick the box. Self-reported, unverified, and not yet public.
</p>

<div class="grid grid-3">
  <div class="stat">
    <div class="v"><?= e($overview['n']) ?></div>
    <div class="k">Records shown</div>
  </div>
  <div class="stat">
    <div class="v"><?= e($sectorCount) ?></div>
    <div class="k">Sectors</div>
  </div>
  <div class="stat">
    <div class="v"><?= e($fmt($overview['mean_floor'])) ?></div>
    <div class="k">Mean floor (weakest dimension)</div>
  </div>
</div>

<div class="tabs" style="margin-top:26px">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>" href="<?= e($link(['tab' => $key, 'page' => '1'])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'records'): ?>

  <?php
  /*
   * The reference filtered as you typed (filterDataRecords). Here the rows on
   * the page are filtered live by the same rule whenever the page holds the
   * whole list (one page). Past one page, and without scripting, the box
   * submits instead and the search runs over the whole dataset in SQL.
   */
  ?>
  <form method="get" action="<?= e(url('admin/' . $slug . '/research-data')) ?>" style="margin:4px 0 16px"
        <?= $pages === 1 ? 'data-live-search' : '' ?>>
    <input type="hidden" name="tab" value="records">
    <input type="hidden" name="synthetic" value="<?= $includeSynthetic ? '1' : '0' ?>">
    <input class="f" type="search" name="q" autocomplete="off"
           placeholder="Search by name, email, phone, or organization"
           value="<?= e($search) ?>">
  </form>
  <p class="muted small" id="liveCount" style="margin-bottom:10px" hidden></p>
  <div class="empty" id="liveEmpty" hidden>
    <b>No match for &ldquo;<span></span>&rdquo;</b>
    Names are stored as the respondent typed them, and nothing is verified, so
    check for a middle name or a work address.
  </div>

  <?php if ($records === []): ?>
    <div class="empty">
      <?php if ($search !== ''): ?>
        <b>No match for &ldquo;<?= e($search) ?>&rdquo;</b>
        Names are stored as the respondent typed them, and nothing is verified, so
        check for a middle name or a work address.
      <?php else: ?>
        <b>Nothing here yet</b>
        Complete and save a scorecard, or tick the box to inspect the synthetic
        demo set.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php if ($search !== ''): ?>
      <p class="muted small" style="margin-bottom:10px" id="serverCount">
        <?= e($total) ?> record<?= $total === 1 ? '' : 's' ?> match, newest first.
      </p>
    <?php endif; ?>

    <?php foreach ($records as $record): ?>
      <?= $row($record + ['_scores' => $scores[(int) $record['id']] ?? []]) ?>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
      <div class="nav-row">
        <?php if ($page > 1): ?>
          <a class="btn quiet sm" href="<?= e($link(['page' => (string) ($page - 1)])) ?>">&larr; Newer</a>
        <?php else: ?><span></span><?php endif; ?>

        <span class="muted small">
          Page <?= e($page) ?> of <?= e($pages) ?> &middot; <?= e($total) ?> records
        </span>

        <?php if ($page < $pages): ?>
          <a class="btn quiet sm" href="<?= e($link(['page' => (string) ($page + 1)])) ?>">Older &rarr;</a>
        <?php else: ?><span></span><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($tab === 'dims'): ?>

  <p class="muted small" style="margin-bottom:14px">
    Mean of each dimension across the scorecards shown, with min&ndash;max range. The
    Floor tag marks the weakest dimension across the set.
  </p>

  <?= $this->partial('admin/_bars', [
      'dimensions' => $dimensions,
      'floorCode'  => $aggregateFloor['code'],
      'bandOf'     => $bandOf,
      'fmt'        => $fmt,
  ]) ?>

  <div class="radar-wrap" style="margin-top:24px;max-width:520px"><canvas id="dataRadar" height="280"></canvas></div>

<?php else: ?>

  <div class="grid grid-2">
    <?php foreach ($levels as $level): ?>
      <div class="stat" style="--c:<?= e($level['colour']) ?>">
        <div class="v" style="color:var(--c)"><?= e($level['n']) ?></div>
        <div class="k">Level <?= e($level['value']) ?>, <?= e(strtolower((string) $level['name'])) ?></div>
        <div class="small muted" style="margin-top:6px"><?= e($level['description']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="small muted" style="margin-top:14px">
    Level follows each organization&rsquo;s overall score, the average of all its
    answers. The floor, its weakest dimension, is reported alongside.
  </p>

<?php endif; ?>
<?php $this->stop(); ?>

<?php /* The PDF buttons' scripts (the record dialog's among them) load once for
         every admin page, from admin/_shell-footer.php. */ ?>

<?php $this->start('scripts'); ?>
<?php if ($tab === 'dims'): ?>
  <script src="<?= e(app_asset($slug, 'vendor/chart.umd.min.js')) ?>"></script>
  <script type="application/json" id="radarData"><?= json_encode(
      array_map(static fn (array $d): array => ['code' => $d['code'], 'mean' => $d['mean']], $dimensions),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?></script>
<?php endif; ?>
<script>
  (function () {
    /* The include-synthetic box reloads the list with the other setting. */
    var box = document.querySelector('[data-synthetic-toggle]');
    if (box) box.addEventListener('change', function () { location.href = box.getAttribute('data-href'); });

    /*
     * Live search, the reference's matchRec(): lower-cased with accents folded,
     * and every word typed must appear in the row's text (or, if all digits, in
     * its phone number with the separators removed). The count line and the
     * empty state are the reference's wording.
     */
    var form = document.querySelector('[data-live-search]');
    if (form) {
      var input = form.querySelector('input[name="q"]');
      var rows = [].slice.call(document.querySelectorAll('.rec[data-hay]'));
      var count = document.getElementById('liveCount');
      var empty = document.getElementById('liveEmpty');
      var server = document.getElementById('serverCount');
      var norm = function (s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      };
      var hays = rows.map(function (r) { return norm(r.dataset.hay) + ' ' + (r.dataset.phone || ''); });

      var filter = function () {
        var needle = norm(input.value).trim();
        var words = needle ? needle.split(/\s+/) : [];
        var hits = 0;
        rows.forEach(function (row, i) {
          var ok = words.every(function (w) { return hays[i].indexOf(w) !== -1; });
          row.style.display = ok ? '' : 'none';
          if (ok) hits++;
        });
        if (server) server.hidden = true;
        count.hidden = !needle || hits === 0;
        count.textContent = hits + ' of ' + rows.length + ' record' + (rows.length === 1 ? '' : 's') + ' match, newest first.';
        empty.hidden = !needle || hits > 0;
        empty.querySelector('span').textContent = input.value;
      };

      input.addEventListener('input', filter);
      form.addEventListener('submit', function (event) { event.preventDefault(); filter(); });
    }

    var data = document.getElementById('radarData');
    var canvas = document.getElementById('dataRadar');
    if (!data || !canvas || !window.Chart) return;
    var dims = JSON.parse(data.textContent);
    new window.Chart(canvas, {
      type: 'radar',
      data: {
        labels: dims.map(function (d) { return d.code; }),
        datasets: [{
          label: 'Score',
          data: dims.map(function (d) { return d.mean; }),
          borderColor: 'rgba(232,147,63,1)',
          backgroundColor: 'rgba(232,147,63,.12)',
          pointBackgroundColor: 'rgba(232,147,63,1)',
          borderWidth: 1.5,
          pointRadius: 3,
        }],
      },
      options: {
        responsive: true,
        scales: {
          r: {
            min: 0,
            max: 5,
            ticks: { stepSize: 1, color: '#6F84A0', backdropColor: 'transparent', font: { family: 'Spline Sans Mono', size: 10 } },
            grid: { color: 'rgba(61,130,196,.18)' },
            angleLines: { color: 'rgba(61,130,196,.18)' },
            pointLabels: { color: '#A0B4CA', font: { family: 'Spline Sans Mono', size: 11 } },
          },
        },
        plugins: { legend: { display: false } },
      },
    });
  })();
</script>
<?php $this->stop(); ?>
