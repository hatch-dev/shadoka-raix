<?php
/**
 * One scorecard, as the dialog the dataset list opens over itself.
 *
 * Every other row on that list opens the profile of the BOARD it belongs to.
 * A scorecard with no board — anonymous, or a director who named none — has no
 * profile to open, and until this fragment existed its row was the only one on
 * the screen that navigated away instead of opening a dialog.
 *
 * So this is the board dialog's counterpart for a single director: the same
 * shape, the same bars, and the full page still a click away at the top and
 * the foot. The item-level marks are here rather than only on that page,
 * because they are what makes this a research record rather than a score.
 *
 * Served as a fragment, so there is no layout here.
 *
 * @var \Platform\Core\View $this
 * @var array<string,mixed> $submission
 * @var array<string,mixed>|null $result        null while the scorecard is in progress
 * @var list<array<string,mixed>> $items
 * @var array<string,mixed>|null $organisation
 * @var \Platform\Assessment\Bander $bander
 */
$fmt  = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
$date = static fn (?string $d): string => $d === null
    ? '—'
    : date((string) config('app.date_format'), strtotime($d));

$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};

$markColours = [1 => 'var(--r1)', 2 => 'var(--r2)', 3 => 'var(--r3)', 4 => 'var(--r4)'];
$currentCode = null;

$href = url('admin/' . $slug . '/research-data/' . (int) $submission['id']);

$meta = array_filter([
    $submission['director_name'],
    $submission['seat_role'],
    $submission['tenure'],
    $submission['sector'] ?? $organisation['sector'] ?? null,
    $submission['assessment_year'],
], static fn ($v): bool => $v !== null && $v !== '');
?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div>
    <h3>
      <a href="<?= e($href) ?>" style="color:inherit;text-decoration:none"
         title="Open the full scorecard">
        <?= e($result['board'] ?? $organisation['name'] ?? 'Anonymous') ?>
      </a>
      <?php if ((int) $submission['is_synthetic'] === 1): ?><span class="tag syn">Synthetic</span><?php endif; ?>
    </h3>
    <p class="muted small">
      <?= $meta === [] ? 'No context reported.' : e(implode(' · ', $meta)) ?>
    </p>
    <p class="muted small">
      <?= e(implode(' · ', array_filter([$submission['director_email'] ?? null, $submission['director_phone'] ?? null]))) ?>
    </p>
  </div>

  <div style="text-align:right">
    <div style="font-family:var(--num);font-size:40px;font-weight:700;letter-spacing:-.03em;line-height:1;
                color:<?= e($result['level']['colour'] ?? 'var(--stone)') ?>">
      <?= e($result['level']['value'] ?? '—') ?>
    </div>
    <div class="small muted" style="margin-top:4px">
      <?= isset($result['level']['name']) ? e($result['level']['name']) . ', ' : '' ?>floor
      <?= e($fmt($result['floor'] ?? null)) ?>
    </div>
  </div>
</div>

<?php if ($result === null): ?>
  <div class="notice" style="margin-top:18px">
    <b>This scorecard is still in progress.</b>
    It has not been submitted, so it has no score and appears in no analytics.
  </div>
<?php else: ?>

  <?php
  /*
   * The bars partial reads a board's aggregate — a mean over n directors, with
   * a min/max spread drawn behind it. One director is one mark per competency,
   * so the spread is left off rather than drawn as a zero-width band that
   * would read as agreement between people who were never asked.
   */
  $bars = array_map(static fn (array $competency): array => [
      'code' => $competency['code'],
      'name' => $competency['name'],
      'mean' => $competency['score'],
      'n'    => 1,
      'min'  => null,
      'max'  => null,
  ], $result['competencies']);
  ?>
  <div class="cap" style="margin:16px 0 0">
    This director's marks, averaged per competency. The level is the floor —
    the weakest of the seven.
  </div>

  <div style="margin:10px 0 0">
    <?= $this->partial('admin/_bars', [
        'competencies' => $bars,
        'bandOf'       => $bandOf,
        'fmt'          => $fmt,
    ]) ?>
  </div>

  <table class="data" style="margin-top:18px;max-width:420px">
    <tr><td>Floor</td><td class="num"><b><?= e($fmt($result['floor'])) ?></b></td></tr>
    <tr><td>Unweighted average</td><td class="num"><b><?= e($fmt($result['mean'])) ?></b></td></tr>
    <tr><td>Average above floor</td><td class="num">
      <?= $result['spread'] === null ? '—' : e('+' . number_format($result['spread'], 2)) ?></td></tr>
    <tr><td>Submitted</td><td class="num"><?= e($date($submission['submitted_at'])) ?></td></tr>
  </table>
<?php endif; ?>

<h4 style="font-family:var(--serif);font-weight:600;font-size:19px;margin:22px 0 8px">Every mark</h4>
<table class="data">
  <thead>
    <tr><th style="width:70px">Item</th><th>Statement</th><th class="num" style="width:70px">Mark</th></tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <?php if ($item['code'] !== $currentCode): ?>
        <?php $currentCode = $item['code']; ?>
        <tr><td colspan="3" style="background:rgba(61,130,196,.07);font-weight:600;font-size:12.5px">
          <?= e($item['code']) ?>
        </td></tr>
      <?php endif; ?>
      <tr>
        <td class="mono small muted"><?= e($item['code']) ?> <?= e($item['i_pos']) ?></td>
        <td class="small"><?= e($item['statement']) ?></td>
        <td class="num">
          <?php if ($item['mark'] === null): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <b style="color:<?= e($markColours[(int) $item['mark']] ?? 'var(--paper)') ?>"><?= e($item['mark']) ?></b>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php
/*
 * The full page carries what this dialog leaves out: the "when and where" note
 * against each mark, the whole context table, and the delete.
 */
?>
<div class="nav-row">
  <a class="btn quiet sm" href="<?= e($href) ?>">Open the full scorecard</a>
  <button class="btn sm" data-close-modal>Close</button>
</div>
