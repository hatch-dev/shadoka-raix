<?php

declare(strict_types=1);

/**
 * Run pending migrations: platform first, then each enabled app.
 *
 *   php scripts/migrate.php              apply everything pending
 *   php scripts/migrate.php --pending    list without applying
 *   php scripts/migrate.php --install    create the database first, then apply
 *
 * CLI only. Schema changes do not belong on a URL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../platform/bootstrap.php';

use Platform\Core\Database;
use Platform\Core\Migrator;

$args    = array_slice($argv, 1);
$install = in_array('--install', $args, true);
$dryRun  = in_array('--pending', $args, true);

try {
    if ($install) {
        $name      = (string) config('database.name');
        $charset   = (string) config('database.charset');
        $collation = (string) config('database.collation');

        // CREATE DATABASE cannot be parameterised, so the name is validated
        // rather than bound. Anything outside this character class is refused.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            fwrite(STDERR, "Refusing to create a database named '{$name}'.\n");
            exit(1);
        }

        Database::serverConnection()->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET %s COLLATE %s',
            $name,
            $charset,
            $collation
        ));

        echo "database  `{$name}` ready\n";
    }

    $migrator = new Migrator();

    if ($dryRun) {
        $pending = $migrator->pending();
        if ($pending === []) {
            echo "Nothing pending.\n";
        } else {
            echo "Pending:\n";
            foreach ($pending as $name) {
                echo "  {$name}\n";
            }
        }
        exit(0);
    }

    foreach ($migrator->migrate() as $line) {
        echo $line . "\n";
    }

    echo "done\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRATION FAILED: ' . $e->getMessage() . "\n");
    if (config('app.debug')) {
        fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    }
    exit(1);
}
