<?php
/**
 * The admin shell.
 *
 * A top navigation bar over a centred column, matching the single-file build:
 * there, signing in swapped the same page's nav to Analytics · Research data ·
 * Data seeder · Exit, and the console kept the public site's chrome rather than
 * becoming a different-looking product.
 *
 * It was a left sidebar here until 22 Sep 2026. That was the single biggest
 * visual difference between the two admins, and it was mine, not the
 * reference's.
 *
 * The links are still assembled from every enabled app's manifest and filtered
 * by the acting identity's capabilities, so this file names no app and needs no
 * change when one is added.
 *
 * @var \Platform\Core\View $this
 * @var array $admin
 * @var list<array<string,mixed>> $nav
 * @var string $currentPath
 */

use Platform\Admin\AdminNav;
use Platform\Core\AppRegistry;

/*
 * Each app's admin is its own console. Inside /admin/{slug}/… the bar carries
 * that app's sections and nothing else, under that app's own brand, and Exit
 * returns to that app's public site. Two instruments sharing one bar read as
 * one product, and every link out of it led to the default app.
 *
 * Only a screen that belongs to no app (the platform's own /admin pages) still
 * lists every app's sections, labelled, because there is no single app to pick.
 *
 * The brand is the partial admin/_shell-brand.php, which an app overrides with
 * its own (the View looks in the app's Views first).
 */
$nav        = $nav ?? [];
$currentApp = (isset($slug) && is_string($slug) && AppRegistry::has($slug)) ? AppRegistry::get($slug) : null;

if ($currentApp !== null) {
    $own = array_values(array_filter($nav, static fn (array $g): bool => $g['slug'] === $currentApp->slug));

    if ($own !== []) {
        $nav = $own;
    }
}

?><!DOCTYPE html>
<html lang="en" data-admin-base="<?= e(url('admin')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Admin · RAI-X') ?></title>
<link rel="icon" href="<?= e(asset('platform/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('platform/css/admin.css')) ?>">
</head>
<body class="admin">

<nav aria-label="Admin sections">
  <?php
  /*
   * The logo leaves the console, as the reference's did: there `homeClick()`
   * ran `exitAdmin()` when the admin nav was showing. Here it simply returns to
   * the public site, which is now all Exit does too.
   */
  ?>
  <?= $this->partial('admin/_shell-brand', ['currentApp' => $currentApp]) ?>

  <div class="nav-links">
    <?php
    /*
     * With one app mounted this is the reference's four-item bar and nothing
     * more. With two, it was "Analytics · Research data · Data seeder ·
     * Analytics · Research data · Data seeder · Exit" — every app contributes
     * sections under the same prose names, and nothing on screen said which
     * "Analytics" was which or which one you were looking at.
     *
     * So each group is labelled, but ONLY when there is more than one: a single
     * app keeps the bar it was designed with.
     */
    /*
     * `?? []` because not every screen that extends this layout is rendered
     * through AdminController::view(): a controller that builds its own View
     * shares `slug` and nothing else, and the nav simply comes out empty. That
     * was silent while this was a bare foreach; count() on null is fatal, and
     * took a working report page down with it.
     */
    $labelGroups = count($nav) > 1;
    ?>
    <?php foreach ($nav as $group): ?>
      <?php if ($labelGroups): ?>
        <span class="nav-group"><?= e($group['name']) ?></span>
      <?php endif; ?>
      <?php foreach ($group['sections'] as $section): ?>
        <?php
        /*
         * A section the manifest marks as a dialog opens in place, as the
         * reference's Data seeder does. The href stays real: without scripting
         * the same screen opens as a page, which is where it lives anyway.
         */
        $asModal = !empty($section['modal']);
        ?>
        <a href="<?= e(url($section['path'])) ?>"
           <?= $asModal ? 'data-modal-src="' . e(url($section['path']) . '?fragment=1') . '"' : '' ?>
           <?= $asModal && !empty($section['wide']) ? 'data-modal-wide' : '' ?>
           class="<?= AdminNav::isActive($currentPath, $section['path']) ? 'active' : '' ?>">
          <?= e($section['label']) ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <?php
    /*
     * Exit, and nothing else. The reference's admin nav is four items, so this
     * shell adds one: the platform Overview and the account link were both
     * mine, and both are gone from here.
     *
     * A POST, because signing out changes state. It ends the session and then
     * leaves for the public site, which is what the reference's `exitAdmin()`
     * did — landing on a login form would imply the next step is to sign in
     * again, which is the opposite of what Exit is for.
     */
    ?>
    <?php
    /*
     * Settings (this app's own admin password) sits beside Exit inside each
     * app's console — only for a role holding admin.settings, so the
     * researcher and viewer bars are unchanged.
     */
    ?>
    <?php if ($currentApp !== null && \Platform\Core\Auth::can('admin.settings')): ?>
      <?php $settingsPath = 'admin/' . $currentApp->slug . '/settings'; ?>
      <a href="<?= e(url($settingsPath)) ?>"
         class="quiet-nav <?= AdminNav::isActive($currentPath, $settingsPath) ? 'active' : '' ?>">Settings</a>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/logout')) ?>" style="display:inline"
          title="Signed in as <?= e($admin['name'] ?? '') ?> · <?= e($admin['role_name'] ?? '') ?>">
      <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">
      <?php if ($currentApp !== null): ?>
        <input type="hidden" name="app" value="<?= e($currentApp->slug) ?>">
      <?php endif; ?>
      <button class="quiet-nav" type="submit">Exit</button>
    </form>
  </div>
</nav>

<main class="admin-main">
  <div class="wrap">
    <?= $this->section('content') ?>
  </div>
</main>

<?php /* The app's own footer (admin/_shell-footer.php); none on platform pages. */ ?>
<?= $this->partial('admin/_shell-footer') ?>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<div id="modalRoot"></div>
<script src="<?= e(asset('platform/js/admin-chrome.js')) ?>"></script>
<script src="<?= e(asset('platform/js/admin-modal.js')) ?>"></script>
<?= $this->section('scripts') ?>
</body>
</html>
