<?php
/**
 * One scorecard, in full — every one of the thirty marks with the question it
 * answers and both of that question's written anchors.
 *
 * The anchors are what make this a research record rather than a row of numbers:
 * a 2 on "Decision rights" means "unclear for at least one of these", and without
 * that text beside it the mark is not interpretable by anyone who is not holding
 * the instrument. The reference's record dialog showed only the dimension scores.
 *
 * @var \Platform\Core\View $this
 * @var array $submission
 * @var array|null $result
 * @var list<array<string,mixed>> $items
 * @var array|null $organisation
 * @var \Platform\Assessment\Bander $bander
 * @var bool $canDelete
 */
$this->extend('layouts/admin');

$fmt = static fn (?float $v): string => $v === null ? '—' : number_format((float) $v, 2);

$bandOf = static function (?float $score) use ($bander): ?array {
    $band = $bander->band($score === null ? null : (float) $score);

    return $band === null ? null : $band + ['colour_hex' => $band['colour']];
};
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">
      <a href="<?= e(url('admin/' . $slug . '/research-data')) ?>">Research data</a> &rsaquo; scorecard
    </div>
    <h1 class="title"><?= e($submission['respondent_name'] ?: 'Unnamed respondent') ?></h1>
  </div>
</div>

<?= $this->partial('admin/_record-modal', [
    'submission'   => $submission,
    'result'       => $result,
    'items'        => $items,
    'organisation' => $organisation,
    'bander'       => $bander,
    'slug'         => $slug,
]) ?>

<hr class="rule">

<h2 class="sub">Every answer</h2>
<p class="muted small" style="margin-bottom:16px">
  The mark, and what that number means on that question. A dimension&rsquo;s score is
  the unweighted mean of its five marks.
</p>

<?php $currentCode = null; ?>
<?php foreach ($items as $item): ?>
  <?php if ($item['code'] !== $currentCode): ?>
    <?php $currentCode = $item['code']; ?>
    <h3 class="sub" style="margin-top:22px;font-size:19px">
      <span class="muted" style="font-weight:400"><?= e($item['code']) ?></span>
      <?= e($item['dimension']) ?>
    </h3>
  <?php endif; ?>

  <?php
  $mark = $item['mark'] === null ? null : (int) $item['mark'];
  $band = $bandOf($mark === null ? null : (float) $mark);

  /*
   * A 1 and a 5 have their own written meaning on this question; 2, 3 and 4 do
   * not, so the record shows the two ends and lets the reader place the mark
   * between them rather than inventing wording for the middle.
   */
  ?>
  <div class="qrow" style="--c:<?= e($band['colour_hex'] ?? 'var(--stone)') ?>;grid-template-columns:1fr 130px 54px">
    <div>
      <span class="small muted" style="font-weight:600"><?= e($item['code']) ?> <?= e($item['i_pos']) ?></span>
      <b style="font-weight:600"><?= e($item['label']) ?>.</b>
      <?= e($item['question']) ?>
      <div class="hint" style="margin-top:6px">
        <b style="color:var(--stone)">1, <?= e($item['anchor_low_label']) ?>:</b>
        <?= e($item['anchor_low_text']) ?>
        <br>
        <b style="color:var(--stone)">5, <?= e($item['anchor_high_label']) ?>:</b>
        <?= e($item['anchor_high_text']) ?>
      </div>
    </div>
    <div class="bar-track">
      <i style="width:<?= $mark === null ? 0 : e(round((($mark - 1) / 4) * 100, 2)) ?>%"></i>
    </div>
    <div class="mono small" style="text-align:right">
      <?= $mark === null ? '—' : e($mark) ?>
      <?php if ($band !== null): ?>
        <span class="muted" style="display:block;font-size:11px"><?= e($band['name']) ?></span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<hr class="rule">

<div class="nav-row">
  <a class="btn quiet" href="<?= e(url('admin/' . $slug . '/research-data')) ?>">&larr; Research data</a>

  <?php if ($canDelete): ?>
    <?php
    /*
     * Deletion is the one action on this screen that cannot be undone, and on
     * this instrument the row holds a named, contactable person — so it is also
     * how a request under the privacy notice is honoured. Audited before the row
     * goes, with the email address, because the request arrives as an address and
     * this is the only place that will still show it.
     */
    ?>
    <form method="post"
          action="<?= e(url('admin/' . $slug . '/research-data/' . $submission['id'] . '/delete')) ?>"
          onsubmit="return confirm('Delete this scorecard permanently? This cannot be undone.')">
      <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">
      <button class="btn danger sm" type="submit">Delete this scorecard</button>
    </form>
  <?php endif; ?>
</div>
<?php $this->stop(); ?>

<?php /* The PDF button's script loads once for every admin page, from
         admin/_shell-footer.php. */ ?>
