<?php
/**
 * The admin bar's brand: this app's own name, as its page carries it, linking
 * back to that page.
 *
 * It also carries the app's admin stylesheet — the page's own rules for the
 * analytics tables, level bars and records — linked here, the first thing in
 * <body>, so it is in place before any of the content it styles is painted.
 *
 * @var \Platform\Core\AppManifest|null $currentApp
 */
?>
<link rel="stylesheet" href="<?= e(app_asset('human-edge-scorecard', 'css/admin.css')) ?>">
<a class="logo" href="<?= e(url('human-edge-scorecard')) ?>">The Human Edge Scorecard</a>
