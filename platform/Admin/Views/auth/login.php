<?php
/**
 * Sign in.
 *
 * Deliberately says nothing about whether an email address is known — the
 * message is identical for an unknown account and a wrong password, because
 * distinguishing them tells an attacker which addresses are worth attacking.
 *
 * Its own document, with no admin shell: there is no session yet, so there is
 * no nav to draw and nobody to draw it for.
 *
 * @var \Platform\Core\View $this
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Sign in · RAI-X admin') ?></title>
<link rel="icon" href="<?= e(asset('platform/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('platform/css/admin.css')) ?>">
</head>
<body class="auth">

<div class="auth-wrap">
  <div class="admin-logo">RAI<span>—</span>X<small>admin</small></div>

  <h1 class="title" style="font-size:24px;margin-bottom:6px">Sign in</h1>
  <p class="muted small" style="margin-bottom:22px">
    <?php if (!empty($appName)): ?>
      <?= e($appName) ?> admin. This app has its own password; another app's will not open it.
    <?php else: ?>
      Analytics and the research dataset sit behind this.
    <?php endif; ?>
  </p>

  <?php if (!empty($error)): ?>
    <div class="err" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if (!empty($appSlug) && !empty($pinEnabled)): ?>
  <?php /* The app's own password, as the public Admin dialog asks for it —
           this is where that dialog's link lands without scripting. */ ?>
  <form method="post" action="<?= e(url('admin/pin')) ?>">
    <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">
    <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
    <input type="hidden" name="app" value="<?= e($appSlug) ?>">

    <div class="field">
      <label class="f" for="pin">Password</label>
      <input class="f" id="pin" name="pin" type="password" autocomplete="current-password" required autofocus>
    </div>

    <button class="btn gold" type="submit">Sign in</button>
  </form>
  <?php else: ?>
  <form method="post" action="<?= e(url('admin/login')) ?>">
    <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">
    <input type="hidden" name="next" value="<?= e($next ?? '') ?>">

    <div class="field">
      <label class="f" for="email">Email address</label>
      <input class="f" id="email" name="email" type="email" autocomplete="username"
             required autofocus value="<?= e($email ?? '') ?>">
    </div>

    <div class="field">
      <label class="f" for="password">Password</label>
      <input class="f" id="password" name="password" type="password"
             autocomplete="current-password" required>
    </div>

    <button class="btn gold" type="submit">Sign in</button>
  </form>
  <?php endif; ?>

  <p class="auth-note">
    There is no password reset by email. A forgotten app password is reset on
    the server with <code>php scripts/admin-password.php --app=…</code>.
  </p>
</div>

</body>
</html>
