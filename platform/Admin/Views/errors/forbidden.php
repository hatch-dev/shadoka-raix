<?php
/**
 * Shown when the identity the console runs as lacks the capability a section
 * requires. With no sign-in that means ADMIN_EMAIL points at a narrower role
 * than this screen needs — not that the wrong person is looking at it.
 *
 * Names the capability: this is an internal tool, and "ask for ds.export" is a
 * more useful thing to tell a colleague than "ask for access".
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">Not permitted</div>
    <h1 class="title">You do not have access to that.</h1>
  </div>
</div>

<div class="card" style="max-width:560px">
  <p class="muted">
    Your role is <b style="color:var(--paper)"><?= e($admin['role_name'] ?? 'unknown') ?></b>,
    which does not include the capability this section requires.
  </p>
  <?php if (!empty($capability)): ?>
    <p class="muted" style="margin-top:10px">
      Required: <span class="pill"><?= e($capability) ?></span>
    </p>
  <?php endif; ?>
  <p class="muted small" style="margin-top:14px">
    An administrator can grant it by changing your role.
  </p>
  <div style="margin-top:16px"><a class="btn sm" href="<?= e(url('admin')) ?>">Back to overview</a></div>
</div>
<?php $this->stop(); ?>
