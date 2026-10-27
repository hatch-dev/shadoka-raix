<?php
/** Platform-wide settings. Anything environment-specific comes from .env. */
declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'RAI-X'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => rtrim((string) env('APP_URL', ''), '/'),

    // The extra URL segment the platform is mounted under, when a rewrite maps
    // it onto public/ — '/RAIX/dev/...' is served from '/RAIX/public/...'.
    // SCRIPT_NAME shows only the second shape, so base_url_path() cannot infer
    // the segment and is told it here. Empty when public/ is the document root
    // or when the folder itself is the mount.
    'mount'    => trim((string) env('APP_MOUNT', ''), '/'),

    'key'      => env('APP_KEY', ''),
    'timezone' => 'UTC',
    'locale'   => 'en',

    // Every user-facing date prints through this format, so the whole platform
    // reads the same way: "Sep 22, 2026".
    'date_format' => 'M j, Y',

    'owner'     => 'SHADOKA, LLC',
    'copyright' => '© 2026 SHADOKA, LLC. All rights reserved.',
];
