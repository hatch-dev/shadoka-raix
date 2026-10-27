<?php
/**
 * Analytics → Year-over-year.
 *
 * The reference's pane: the two lines over time, then the table of organisations
 * that have scored more than once.
 *
 * Its empty state is kept word for word, because it says something true and
 * useful — this screen cannot exist until an organisation re-scores, and that is
 * a property of the dataset rather than a fault.
 *
 * One correction. The reference grouped by the typed organisation name
 * (`byOrgYear[r.org?.org]`), so "Meridian Capital Group" and "Meridian Capital
 * Group." were two organisations that had each scored once, and neither appeared
 * here. This groups by the platform organisation row, which is what deduplicates
 * a typed name — and is the reason that table lives at platform level.
 *
 * @var \Platform\Core\View $this
 */
?>
<?php /* As the reference: until an organization re-scores, the empty state IS
         the pane, with no chart above it. */ ?>
<?php if ($rescored === []): ?>
  <div class="empty">
    <b>No organization has scored twice yet</b>
    Year-over-year appears once an organization re-scores. Annual is the cadence,
    alongside strategic evaluation.
  </div>
<?php else: ?>
<div class="chart-card" style="margin-bottom:22px">
  <h4>Floor and average over time</h4>
  <div class="cap">
    Mean across all records in each year. The two lines converging means practice
    is catching up with paperwork.
  </div>
  <div style="height:280px"><canvas id="cYoY"></canvas></div>
</div>

<h2 class="sub">Organizations that re-scored</h2>

  <table class="data">
    <thead>
      <tr>
        <th>Organization</th>
        <th>Sector</th>
        <?php foreach ($years as $year): ?>
          <th class="num"><?= e($year) ?></th>
        <?php endforeach; ?>
        <th class="num">Change</th>
        <th>Weakest dimension</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rescored as $organisation): ?>
        <?php
        $href = url('admin/' . $slug . '/analytics/organisation/' . $organisation['organisation_id'])
              . '?synthetic=' . ($includeSynthetic ? '1' : '0');
        ?>
        <?php /* Wide, as the reference's openOrg() made it (.modal.wide). */ ?>
        <tr class="clickable" data-href="<?= e($href) ?>"
            data-modal-src="<?= e($href . '&fragment=1') ?>" data-modal-wide>
          <td><a href="<?= e($href) ?>"><?= e($organisation['name']) ?></a></td>
          <td class="muted"><?= e($organisation['sector'] ?? '') ?></td>

          <?php foreach ($years as $year): ?>
            <?php
            $cell = $organisation['years'][$year] ?? null;
            $band = $cell === null ? null : $bandOf($cell['mean']);
            ?>
            <?php if ($cell === null): ?>
              <td class="num muted">—</td>
            <?php else: ?>
              <td class="num" style="color:<?= e($band['colour_hex'] ?? 'inherit') ?>">
                <?= e($fmt($cell['mean'])) ?>
              </td>
            <?php endif; ?>
          <?php endforeach; ?>

          <td class="num">
            <?php $change = $organisation['change']; ?>
            <span class="delta <?= $change === null ? '' : ($change > 0 ? 'up' : ($change < 0 ? 'down' : '')) ?>">
              <?= $change === null ? '—' : e(($change > 0 ? '+' : '') . number_format($change, 2)) ?>
            </span>
          </td>

          <td class="muted small">
            <?php
            $from = $organisation['floor_from'];
            $to   = $organisation['floor_to'];
            ?>
            <?php if ($from === null && $to === null): ?>
              —
            <?php elseif ($from === $to): ?>
              unchanged, <?= e($to) ?>
            <?php else: ?>
              <?= e($from ?? '—') ?> to <?= e($to ?? '—') ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="small muted" style="margin-top:12px">
    Scores shown are each organization&rsquo;s overall score by year. Where the overall
    score rises but the weakest dimension does not change, the improvement is concentrated in
    the dimensions the organization was already strong in.
  </p>
<?php endif; ?>
