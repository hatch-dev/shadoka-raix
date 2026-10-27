<?php

declare(strict_types=1);

/**
 * Copy each app's assets into public/assets/apps/{slug}/.
 *
 *   php scripts/publish-assets.php
 *   php scripts/publish-assets.php --app=raix-director-scorecard
 *   php scripts/publish-assets.php --clean     remove published files first
 *
 * Source assets live inside the app (apps/{slug}/assets) so they sit next to
 * the code that uses them and are version-controlled with it. public/ holds
 * only the published copy, which is why public/assets/apps is gitignored.
 *
 * In development the front controller republishes stale files on each page
 * render, so this is mainly a deploy step — and the way to force a clean copy.
 *
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../platform/bootstrap.php';

use Platform\Core\Assets;

$only  = null;
$clean = in_array('--clean', array_slice($argv, 1), true);

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app=')) {
        $only = substr($arg, strlen('--app='));
    }
}

try {
    $result = Assets::publishAll($clean, $only);

    foreach ($result['apps'] as $slug => $published) {
        printf(
            "published  %-24s %d files, %.1f KB\n",
            $slug === '_platform' ? 'platform' : $slug,
            $published['files'],
            $published['bytes'] / 1024
        );
    }

    printf("\n%d files, %.1f KB published\n", $result['files'], $result['bytes'] / 1024);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'PUBLISH FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
