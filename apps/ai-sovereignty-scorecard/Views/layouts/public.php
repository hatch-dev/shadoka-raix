<?php
/**
 * Public layout for the AI Sovereignty Scorecard.
 *
 * @var \Platform\Core\View $this
 * @var string $title
 * @var string $slug
 */
$slug = $slug ?? 'ai-sovereignty-scorecard';
?><!DOCTYPE html>
<?php
/*
 * The scripts read their base URLs from here rather than guessing, so the app
 * works at a vhost root, in a subfolder like /RAIX/dev, or behind a different
 * slug, with no build step and nothing hard-coded.
 */
?>
<html lang="en-US"
      data-api-base="<?= e(url($slug)) ?>"
      data-admin-base="<?= e(url('admin')) ?>"
      data-home-base="<?= e(url($slug)) ?>"
      data-results-base="<?= e(url($slug . '/results')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'The AI Sovereignty Scorecard') ?></title>
<?php if (!empty($description)): ?>
<meta name="description" content="<?= e($description) ?>">
<?php endif; ?>
<?php /* Share card. The banner is apps/{slug}/assets/social/banner.jpg; see social_meta(). */ ?>
<?= social_meta($slug, $title ?? null, $description ?? null) ?>
<link rel="icon" href="<?= e(asset('platform/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<?php
/* Order matters: tokens define the custom properties everything else reads. */
foreach (['tokens', 'base', 'components', 'scorecard'] as $sheet): ?>
<link rel="stylesheet" href="<?= e(app_asset($slug, 'css/' . $sheet . '.css')) ?>">
<?php endforeach; ?>
<?php /* The browser's own "Save as PDF", alongside the drawn document the
         results page's button produces. Needs no library on the server. */ ?>
<link rel="stylesheet" media="print" href="<?= e(app_asset($slug, 'css/print.css')) ?>">
</head>
<body>

<nav aria-label="Primary">
  <a class="logo" href="<?= e(url($slug)) ?>">AI Sovereignty<span> &mdash; </span>The Enterprise Scorecard</a>
  <div class="nav-links" id="navLinks">
    <?php
    /*
     * No active state on Overview.
     *
     * The reference marks a nav item with `.active` only from inside `go()`,
     * which nothing calls at boot — so on the page as it is first served,
     * nothing is highlighted, and the Overview link sits in the same stone as
     * Admin beside it. Highlighting it here was a page-indicator this bar does
     * not otherwise have: none of the other public destinations has one either,
     * because only Overview carries a `data-v` in that build.
     *
     * The `.nav-links a.active` rule is still in components.css, and the admin
     * shell uses it. Put ` class="active"` back on this one line to restore the
     * indicator.
     */
    ?>
    <a href="<?= e(url($slug)) ?>">Overview</a>
    <?php
    /*
     * Opens the sign-in dialog in place, as the reference build's Admin gate
     * did. What it asks for is the same shared PIN; where it is compared is not.
     * That build held the PIN in its own JavaScript because everything behind
     * the gate was the viewer's own browser-tab data. These screens read every
     * respondent's marks, name, email address and phone number out of a shared
     * database, so the server decides.
     *
     * Still a plain link underneath: with scripting off, or before app.js has
     * run, clicking it opens the sign-in page rather than doing nothing.
     */
    ?>
    <?php
    /*
     * data-admin-next says where signing in from HERE should land. Without it
     * the console's own rule applies — the first section of the default app —
     * and an admin who signed in from this instrument's site would arrive at the
     * Director Scorecard's analytics, looking at a different dataset. The
     * platform validates the value before using it.
     */
    ?>
    <?php
    /*
     * The value is the APPLICATION path, with no base prefix — the same shape as
     * Request::path, which is what the console's own ?next= carries. url() would
     * prepend /RAIX/dev here and safeNext() rejects anything not starting
     * "/admin/", so the link gets url() and the parameter does not.
     */
    ?>
    <a href="<?= e(url('admin/' . $slug . '/analytics')) ?>" data-open-admin
       data-admin-next="/admin/<?= e($slug) ?>/analytics">Admin</a>
    <button class="boxed" type="button" data-open-help>How scoring works</button>
    <?php
    /*
     * A real link to /assess, intercepted by app.js so the terms open over the
     * page you are on — the reference's `startAssessment()` — and the
     * navigation happens on Continue. With scripting off it is an ordinary
     * link, and /assess shows the same gate itself.
     */
    ?>
    <a class="primary" href="<?= e(url($slug . '/assess')) ?>" data-start-assessment>Score your organization</a>
  </div>
</nav>

<?= $this->section('content') ?>

<footer>
  <div>The AI Sovereignty Scorecard. A <?= e($owner ?? 'SHADOKA, LLC') ?> research edition.</div>
  <div class="legal">
    <span class="copy"><?= e($copyright ?? '') ?></span>
    <span>
      <a href="https://shadoka.com/terms/" target="_blank" rel="noopener noreferrer">Terms of Use</a>
      <span class="sep"> &nbsp;|&nbsp; </span>
      <a href="https://shadoka.com/privacy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
    </span>
  </div>
</footer>

<div id="modalRoot"></div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<div class="cursor" id="cur"></div><div class="cursor-ring" id="curRing"></div>

<?php
/*
 * app.js imports these, and the browser cannot discover them until it has
 * fetched and parsed app.js first. The URLs must be the bare, un-fingerprinted
 * ones the imports resolve to, or the browser preloads one file and then
 * fetches another — app_module() exists for exactly that.
 */
?>
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/api.js')) ?>">
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/ui.js')) ?>">
<link rel="modulepreload" href="<?= e(app_module($slug, 'js/consent.js')) ?>">
<script type="module" src="<?= e(app_asset($slug, 'js/app.js')) ?>"></script>
<?= $this->section('scripts') ?>
</body>
</html>
