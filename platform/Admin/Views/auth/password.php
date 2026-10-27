<?php
/**
 * Change password.
 *
 * Reached two ways: chosen, or forced because this administrator is still on
 * the password the seeder printed. `$forced` decides which of those it says,
 * because "you must do this now" and "you asked to do this" are different
 * situations and a screen that cannot tell them apart reads as a lock-out.
 *
 * @var \Platform\Core\View $this
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Change password · RAI-X admin') ?></title>
<link rel="icon" href="<?= e(asset('platform/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('platform/css/admin.css')) ?>">
</head>
<body class="auth">

<div class="auth-wrap">
  <div class="admin-logo">RAI<span>—</span>X<small>admin</small></div>

  <h1 class="title" style="font-size:24px;margin-bottom:6px">Change password</h1>

  <p class="muted small" style="margin-bottom:22px">
    <?php if (!empty($forced)): ?>
      This account is still on the password the installer printed. Choose your
      own before going on.
    <?php else: ?>
      At least twelve characters, and not the one you are using now.
    <?php endif; ?>
  </p>

  <?php if (!empty($error)): ?>
    <div class="err" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= e(url('admin/password')) ?>">
    <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">

    <div class="field">
      <label class="f" for="password">New password</label>
      <input class="f" id="password" name="password" type="password"
             autocomplete="new-password" minlength="12" required autofocus>
      <div class="hint">Twelve characters or more.</div>
    </div>

    <div class="field">
      <label class="f" for="password_confirm">Type it again</label>
      <input class="f" id="password_confirm" name="password_confirm" type="password"
             autocomplete="new-password" required>
    </div>

    <button class="btn gold" type="submit">Save password</button>
  </form>

  <?php if (empty($forced)): ?>
    <p class="auth-note">
      <a href="<?= e(url('admin')) ?>">Back to the console</a>
    </p>
  <?php endif; ?>
</div>

</body>
</html>
