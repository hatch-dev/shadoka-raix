<?php
/**
 * The admin bar's brand — the platform default.
 *
 * RAI—X, then the name of the app whose console this is, so the bar says which
 * app you are in. The platform's own /admin pages belong to no app and show
 * the mark alone. It links to that app's public site.
 *
 * An app replaces this with its own admin/_shell-brand.php.
 *
 * @var \Platform\Core\AppManifest|null $currentApp
 */
?>
<a class="logo" href="<?= e(url($currentApp !== null ? $currentApp->slug : '')) ?>">RAI<span>—</span>X<?php if ($currentApp !== null): ?><span> &mdash; </span><?= e($currentApp->name) ?><?php endif; ?></a>
