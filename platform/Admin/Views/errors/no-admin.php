<?php
/**
 * Shown when the console has no administrator identity to act as.
 *
 * With the sign-in removed there is no such thing as a signed-out visitor, so
 * the only way to reach this page is an `admin_users` table with no active row
 * — a database that has not been seeded. It says exactly that, and how to fix
 * it, rather than implying somebody needs to log in.
 *
 * The shell is deliberately not extended: the nav is assembled from the
 * identity this page is reporting the absence of, so it would render empty.
 *
 * @var \Platform\Core\View $this
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'No administrator · RAI-X admin') ?></title>
<link rel="icon" href="<?= e(asset('platform/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('platform/css/admin.css')) ?>">
</head>
<body class="admin">

<nav aria-label="Admin sections">
  <a class="logo" href="<?= e(url('')) ?>">RAI<span>&mdash;</span>X</a>
</nav>

<main class="admin-main">
  <div class="wrap">
    <div class="admin-head">
      <div>
        <div class="eyebrow">Admin</div>
        <h1 class="title">This deployment has no administrator.</h1>
      </div>
    </div>

    <div class="card" style="max-width:560px">
      <p class="muted">
        The console runs as an administrator record: it needs one for the audit
        trail, and its role decides which sections appear. There is no active
        row in <span class="pill">admin_users</span> to use.
      </p>
      <p class="muted" style="margin-top:10px">
        Create one by running the seeders:
      </p>
      <p class="mono" style="margin-top:10px;padding:10px 12px;border:1px solid var(--rule-soft);border-radius:3px">php scripts/seed.php</p>
    </div>
  </div>
</main>

</body>
</html>
