<?php
/**
 * Analytics → Year-over-year.
 *
 * The reference's pane, section for section and nothing else: the two lines
 * over time, the boards that have scored more than once, and what the floor did
 * between their first year and their latest.
 *
 * Two sections were here and were removed on request, to leave exactly the
 * reference's shape: a table of the per-year figures under the chart, and a
 * list of every board. The second was this screen's only link to the board
 * drilldown, so `analytics/board/{id}` is now reachable only for a board that
 * has scored in more than one year.
 *
 * @var \Platform\Core\View $this
 */
$q = static fn (bool $syn): string => $syn ? '?synthetic=1' : '';

/** Every year in the filtered set, oldest first — the re-scored table's columns. */
$columns = array_map(static fn (array $y): int => (int) $y['year'], $years);
?>
<?php if (count($years) < 2): ?>
  <div class="empty" style="margin-bottom:26px">
    <b>Not enough years yet</b>
    This appears once scorecards exist in more than one assessment year. The
    cadence is annual, alongside the board evaluation.
  </div>
<?php else: ?>
  <div class="chart-card" style="margin-bottom:22px">
    <h4>Floor and average over time</h4>
    <div class="cap">
      Mean across all records in each year. The two lines converging means the
      weakest competencies are catching up with the average.
    </div>
    <div style="height:280px"><canvas id="cYoY"></canvas></div>
  </div>

<?php endif; ?>

<h2 class="sub">Boards that re-scored</h2>
<?php if ($boardYears === []): ?>
  <div class="empty" style="margin-bottom:26px">
    <b>No board has scored twice yet</b>
    This appears once the directors of a board re-score. The cadence is annual,
    alongside the board evaluation.
  </div>
<?php else: ?>
  <table class="data" style="margin-bottom:8px">
    <thead>
      <tr>
        <th>Board</th><th>Sector</th>
        <?php foreach ($columns as $column): ?><th class="num"><?= e($column) ?></th><?php endforeach; ?>
        <th class="num">Change</th><th>Floor moved</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($boardYears as $board): ?>
        <?php
        $href = url('admin/' . $slug . '/analytics/board/' . $board['id'] . $q($includeSynthetic));
        /*
         * A row opens the board over the dashboard, as it did in the reference,
         * rather than navigating away from the table you are reading across.
         *
         * `data-modal-src` on the row and a real link in the first cell: the
         * dialog is what a plain click gets, and the page behind it is still
         * there for ctrl-click, for a middle click, and for no scripting at
         * all. The old row used `onclick` alone, which had none of those.
         */
        $fragment = url('admin/' . $slug . '/analytics/board/' . $board['id'])
            . '?fragment=1' . ($includeSynthetic ? '&synthetic=1' : '');
        ?>
        <tr class="clickable" data-modal-src="<?= e($fragment) ?>" data-modal-wide>
          <td><a href="<?= e($href) ?>"><?= e($board['name']) ?></a></td>
          <td class="muted small"><?= e($board['sector'] ?? '—') ?></td>
          <?php foreach ($columns as $column): ?>
            <?php $cell = $board['years'][$column] ?? null; ?>
            <?php if ($cell === null): ?>
              <td class="num muted">—</td>
            <?php else: ?>
              <td class="num" style="color:<?= e($bandOf($cell['mean_floor'])['colour_hex'] ?? 'inherit') ?>"
                  title="<?= e($cell['n']) ?> director<?= $cell['n'] === 1 ? '' : 's' ?>">
                <?= e($fmt($cell['mean_floor'])) ?>
              </td>
            <?php endif; ?>
          <?php endforeach; ?>
          <td class="num">
            <span class="delta <?= $board['change'] > 0 ? 'up' : ($board['change'] < 0 ? 'down' : '') ?>">
              <?= e($pct($board['change'])) ?>
            </span>
          </td>
          <td class="muted small">
            <?php if ($board['from_code'] === null || $board['to_code'] === null): ?>
              —
            <?php elseif ($board['from_code'] === $board['to_code']): ?>
              unchanged, <?= e($board['from_code']) ?>
            <?php else: ?>
              <?= e($board['from_code']) ?> to <?= e($board['to_code']) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint" style="margin-bottom:26px">
    “Floor moved” is the competency that caps the most directors on that board,
    in its first year and in its latest. A board whose floor moves has closed one
    weakness and found another; a board whose floor is unchanged has not.
  </p>
<?php endif; ?>
