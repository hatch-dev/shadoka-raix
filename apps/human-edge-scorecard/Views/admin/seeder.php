<?php
/**
 * The Data seeder as a page: the dialog's form, then the recorded runs.
 *
 * @var \Platform\Core\View $this
 * @var list<array<string, mixed>> $runs
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="card">
  <?= $this->partial('admin/_seeder-form', ['slug' => $slug, 'csrf' => $csrf, 'error' => $error, 'seed' => $seed, 'archetypes' => $archetypes]) ?>
</div>

<h3 class="sub" style="margin-top:28px">Generated sets</h3>
<p class="muted small" style="margin-bottom:12px"><?= e($real) ?> real · <?= e($synthetic) ?> synthetic scorecards now. Each generation replaces the synthetic set, so the newest run is the one on screen.</p>
<?php if ($runs === []): ?>
  <div class="empty"><b>Nothing generated yet</b>Generate a set above, or restore the default demo set from the console.</div>
<?php else: ?>
  <div class="tscroll"><table class="an-table"><thead><tr><th>When</th><th class="r">Seed</th><th class="r">Scorecards</th><th class="r">Organizations</th><th>By</th></tr></thead><tbody>
  <?php foreach ($runs as $run): ?>
    <tr><td><?= e(date('M j, Y, g:i A', (int) strtotime((string) $run['created_at']))) ?></td><td class="r"><?= e($run['seed']) ?></td><td class="r"><?= e($run['records_created']) ?></td><td class="r"><?= e($run['organisations_created']) ?></td><td><?= e($run['created_by_name'] ?? 'Command line') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
<?php $this->stop(); ?>
