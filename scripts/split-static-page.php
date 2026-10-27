<?php

declare(strict_types=1);

/**
 * Split a static HTML page the way the scorecard pages are split as they are
 * sent (Platform\Core\PageAssets): base64 fonts and images in its stylesheets
 * become content-named files in public/assets/page/, a wide background image
 * gets a phone-sized copy, and the page is rewritten to point at them.
 *
 *   php scripts/split-static-page.php RAIX_Index.html
 *
 * For a page Apache serves itself, which no PHP sees on the way out — today
 * that is RAIX_Index.html. The original is kept first in
 * storage/archive/static-pages/. A page with nothing left to split is left
 * alone, so running it twice is harmless; run it again whenever a new copy of
 * the page is uploaded, then upload the page and any new public/assets/page/
 * files.
 *
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../platform/bootstrap.php';

use Platform\Core\PageAssets;

$name = $argv[1] ?? '';
$path = base_path($name);

if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+\.html$/', $name) || !is_file($path)) {
    fwrite(STDERR, "usage: php scripts/split-static-page.php <page.html in the RAIX folder>\n");
    exit(1);
}

try {
    $html = (string) file_get_contents($path);

    if (!preg_match('#data:(font/woff2?|image/(webp|png|jpeg|avif));base64,[A-Za-z0-9+/=]{4096,}#', $html)) {
        echo "$name: nothing to split (already done?)\n";
        exit(0);
    }

    $out = PageAssets::splitStatic($html);

    $archive = storage_path('archive/static-pages');
    if (!is_dir($archive) && !mkdir($archive, 0775, true) && !is_dir($archive)) {
        throw new RuntimeException('cannot create ' . $archive);
    }
    $copy = $archive . '/' . date('Y-m-d_His') . '_' . $name;
    if (file_put_contents($copy, $html) === false) {
        throw new RuntimeException('cannot keep the original in ' . $copy);
    }
    if (file_put_contents($path, $out) === false) {
        throw new RuntimeException('cannot write ' . $path);
    }

    preg_match_all('#/assets/page/([a-f0-9]{16}\.[a-z0-9]+)#', $out, $m);
    printf("%s: %.0f KB -> %.0f KB, original kept in %s\nfiles it uses (public/assets/page/):\n  %s\n",
        $name, strlen($html) / 1024, strlen($out) / 1024, $copy, implode("\n  ", array_unique($m[1])));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'SPLIT FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
