<?php

declare(strict_types=1);

/**
 * Run every *_test.php in tests/ and apps/{slug}/tests/.
 *
 *   php scripts/test.php
 *   php scripts/test.php --app=raix-director-scorecard
 *
 * A test file returns a callable taking a Platform\Core\Assert.
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../platform/bootstrap.php';

use Platform\Core\AppRegistry;
use Platform\Core\Assert;

$only = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app=')) {
        $only = substr($arg, strlen('--app='));
    }
}

$files = [];

if ($only === null) {
    foreach (glob(base_path('tests/*_test.php')) ?: [] as $file) {
        $files['platform/' . basename($file, '.php')] = $file;
    }
}

foreach (AppRegistry::all() as $app) {
    if ($only !== null && $app->slug !== $only) {
        continue;
    }
    foreach (glob($app->path('tests') . '/*_test.php') ?: [] as $file) {
        $files[$app->slug . '/' . basename($file, '.php')] = $file;
    }
}

if ($files === []) {
    echo "No tests found.\n";
    exit(0);
}

$totalPassed = 0;
$failedSuites = 0;

foreach ($files as $name => $file) {
    $test = require $file;

    if (!is_callable($test)) {
        fwrite(STDERR, "Test {$name} must return a callable.\n");
        exit(1);
    }

    $assert = new Assert($name);

    try {
        $test($assert);
    } catch (Throwable $e) {
        echo sprintf("FAIL  %s — threw %s: %s\n", $name, $e::class, $e->getMessage());
        if (config('app.debug')) {
            echo '      ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failedSuites++;
        continue;
    }

    $totalPassed += $assert->passedCount();

    if ($assert->ok()) {
        echo sprintf("PASS  %s  (%d assertions)\n", $name, $assert->passedCount());
        continue;
    }

    $failedSuites++;
    echo sprintf("FAIL  %s  (%d passed, %d failed)\n", $name, $assert->passedCount(), count($assert->failures()));
    foreach ($assert->failures() as $failure) {
        echo '      - ' . $failure . "\n";
    }
}

echo "\n";
echo $failedSuites === 0
    ? sprintf("all suites passed — %d assertions\n", $totalPassed)
    : sprintf("%d suite(s) failed — %d assertions passed\n", $failedSuites, $totalPassed);

exit($failedSuites === 0 ? 0 : 1);
