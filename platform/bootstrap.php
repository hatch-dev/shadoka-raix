<?php

declare(strict_types=1);

/**
 * Shared bootstrap for both entry points: public/index.php and scripts/*.php.
 *
 * Order matters — helpers first, because the autoloader and every config file
 * call env() and config() from their first line.
 */

require_once __DIR__ . '/Support/helpers.php';

/*
 * Autoloader.
 *
 *   Platform\Core\Database              -> platform/Core/Database.php
 *   Apps\RaixDirectorScorecard\Models\Item  -> apps/raix-director-scorecard/Models/Item.php
 *
 * The Apps\ branch converts StudlyCase back to the kebab-case folder name, so
 * an app is reachable by namespace without the registry having loaded yet —
 * which matters, because the registry itself is a Platform\ class.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Platform\\')) {
        $relative = substr($class, strlen('Platform\\'));
        $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }

    if (str_starts_with($class, 'Apps\\')) {
        $relative = substr($class, strlen('Apps\\'));
        $parts    = explode('\\', $relative);
        $studly   = array_shift($parts);

        // RaixDirectorScorecard -> raix-director-scorecard
        $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));

        $file = base_path('apps/' . $slug . '/' . implode('/', $parts) . '.php');
        if (is_file($file)) {
            require $file;
        }
    }
});

/* Composer, if the platform ever grows a third-party dependency. Optional by
   design: nothing in the kernel requires it, so a deploy without `composer
   install` still boots. */
$composer = base_path('vendor/autoload.php');
if (is_file($composer)) {
    require_once $composer;
}

date_default_timezone_set((string) config('app.timezone', 'UTC'));

/* In local/dev, surface everything. In production, log and stay quiet — an
   uncaught notice must never print into a JSON response or a public page. */
if (config('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', storage_path('logs/php.log'));
}
