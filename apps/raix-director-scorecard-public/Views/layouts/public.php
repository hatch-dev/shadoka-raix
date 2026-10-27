<?php
/**
 * Public layout for the Director Scorecard.
 *
 * @var \Platform\Core\View $this
 * @var string $title
 * @var string $slug
 */
$slug = $slug ?? 'raix-director-scorecard-public';
?><!DOCTYPE html>
<?php
/*
 * The scripts read their base URLs from here rather than guessing, so the app
 * works at a vhost root, in a subfolder like /RAIX/public, or behind a
 * different slug, with no build step and nothing hard-coded.
 */
?>
<html lang="en"
      data-api-base="<?= e(url($slug)) ?>"
      data-admin-base="<?= e(url('admin')) ?>"
      data-home-base="<?= e(url($slug)) ?>"
      data-results-base="<?= e(url($slug . '/results')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'RAI-X · The Director Scorecard') ?></title>
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
<?php /* The PDF route: the browser's own "Save as PDF", which needs no library
         on the server and no headless Chrome. */ ?>
<link rel="stylesheet" media="print" href="<?= e(app_asset($slug, 'css/print.css')) ?>">
</head>
<body>

<nav aria-label="Primary">
  <a class="logo" href="<?= e(url($slug)) ?>">RAI<span>—</span>X</a>
  <div class="nav-links" id="navLinks">
    <a href="<?= e(url($slug)) ?>"<?= ($active ?? '') === 'hero' ? ' class="active"' : '' ?>>Overview</a>
    <?php
    /*
     * Opens the sign-in dialog in place, as the single-file build's Admin gate
     * did. What it asks for is the same shared PIN; where it is compared is
     * not. That build held the PIN in its own JavaScript because everything
     * behind the gate was the viewer's own browser-tab data. These screens read
     * every director's marks out of a shared database, so the server decides.
     *
     * Still a plain link underneath: with scripting off, or before app.js has
     * run, clicking it opens the sign-in page rather than doing nothing.
     */
    ?>
    <a href="<?= e(url('admin/' . $slug . '/analytics')) ?>" data-open-admin
       data-admin-next="/admin/<?= e($slug) ?>/analytics">Admin</a>
    <button class="boxed" type="button" data-open-help>How scoring works</button>
    <a class="primary" href="<?= e(url($slug . '/assess')) ?>" data-start-assessment>Score yourself</a>
  </div>
</nav>

<?= $this->section('content') ?>

<footer>
  <div>RAI-X, the Responsible AI Index · Director Scorecard. A <?= e($owner ?? 'SHADOKA, LLC') ?> research edition.</div>
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

<script type="module" src="<?= e(app_asset($slug, 'js/app.js')) ?>"></script>
<?= $this->section('scripts') ?>
</body>
</html>
