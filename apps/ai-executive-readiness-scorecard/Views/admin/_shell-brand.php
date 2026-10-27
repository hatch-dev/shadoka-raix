<?php
/**
 * The admin bar's brand: this app's own logo, the one its public site carries,
 * so its console reads as the same product. Links back to that public site.
 *
 * It also carries the app's admin stylesheet. The platform's admin.css holds
 * the chrome every app shares; the paired-assessment pieces this instrument's
 * screens are built from (the two score cards, the paired bars, the
 * side-by-side tables) are this app's, and a <link> here — the first thing in
 * <body> — is in place before any of the content it styles is painted.
 *
 * @var \Platform\Core\AppManifest|null $currentApp
 */
?>
<link rel="stylesheet" href="<?= e(app_asset('ai-executive-readiness-scorecard', 'css/admin.css')) ?>">
<a class="logo" href="<?= e(url('ai-executive-readiness-scorecard')) ?>">AI Executive Readiness<span> &mdash; </span>The Enterprise Scorecard</a>
