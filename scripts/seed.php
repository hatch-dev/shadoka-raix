<?php

declare(strict_types=1);

/**
 * Run seeders: platform first, then each enabled app.
 *
 *   php scripts/seed.php                       run every seeder
 *   php scripts/seed.php --app=raix-director-scorecard   one app only
 *   php scripts/seed.php --list                show what would run
 *
 * Seeders are idempotent by contract, so this is safe to re-run.
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../platform/bootstrap.php';

use Platform\Core\AppManifest;
use Platform\Core\AppRegistry;
use Platform\Core\Seeder;

$args = array_slice($argv, 1);
$list = in_array('--list', $args, true);

$only = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--app=')) {
        $only = substr($arg, strlen('--app='));
    }
}

/** @return list<array{owner:string,file:string,app:?AppManifest}> */
$collect = static function (?string $only): array {
    $sets = [];

    if ($only === null) {
        foreach (glob(base_path('database/seeds/*.php')) ?: [] as $file) {
            $sets[] = ['owner' => '_platform', 'file' => $file, 'app' => null];
        }
    }

    foreach (AppRegistry::all() as $app) {
        if ($only !== null && $app->slug !== $only) {
            continue;
        }
        foreach (glob($app->seedsPath() . '/*.php') ?: [] as $file) {
            $sets[] = ['owner' => $app->slug, 'file' => $file, 'app' => $app];
        }
    }

    return $sets;
};

try {
    $sets = $collect($only);

    if ($sets === []) {
        echo "No seeders found.\n";
        exit(0);
    }

    if ($list) {
        foreach ($sets as $set) {
            echo sprintf("  %s/%s\n", $set['owner'], basename($set['file'], '.php'));
        }
        exit(0);
    }

    foreach ($sets as $set) {
        $seeder = require $set['file'];

        if (!$seeder instanceof Seeder) {
            fwrite(STDERR, sprintf(
                "Seeder %s must return an instance of Platform\\Core\\Seeder.\n",
                $set['file']
            ));
            exit(1);
        }

        $seeder->bindApp($set['app']);
        echo sprintf("seeding  %s — %s\n", $set['owner'], $seeder->name());
        $seeder->run();

        foreach ($seeder->output() as $line) {
            echo $line === '' ? "\n" : '  ' . $line . "\n";
        }
    }

    echo "done\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'SEED FAILED: ' . $e->getMessage() . "\n");
    if (config('app.debug')) {
        fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    }
    exit(1);
}
