<?php

declare(strict_types=1);

/**
 * Reset one app's admin password — the way back in when it is forgotten.
 *
 *   php scripts/admin-password.php --app=raix-director-scorecard
 *   php scripts/admin-password.php --app=enterprise-ai-scorecard --password=NewSecret
 *   php scripts/admin-password.php --list
 *
 * Without --password a random one is generated and printed ONCE. It is stored
 * only as a hash, so it cannot be read back later. Each app's password opens
 * that app's admin and no other; changing one leaves the others alone.
 *
 * Also clears a lockout on that app's administrator account.
 * CLI only: there is no email reset, because this server sends no mail.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../platform/bootstrap.php';

use Platform\Core\AppRegistry;
use Platform\Core\Auth;
use Platform\Core\Database;

$slug     = null;
$password = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app=')) {
        $slug = substr($arg, strlen('--app='));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    } elseif ($arg === '--list') {
        foreach (AppRegistry::all() as $app) {
            printf("  %-26s %s\n", $app->slug, Auth::pinEnabled($app->slug) ? 'password set' : 'NO password');
        }
        exit(0);
    }
}

if ($slug === null || !AppRegistry::has($slug)) {
    fwrite(STDERR, "Name the app: --app=<slug>. Known apps:\n");
    foreach (AppRegistry::all() as $app) {
        fwrite(STDERR, '  ' . $app->slug . "\n");
    }
    exit(1);
}

$admin = Auth::appAdmin($slug);

if ($admin === null) {
    fwrite(STDERR, "{$slug} has no administrator account yet. Run: php scripts/seed.php\n");
    exit(1);
}

$generated = $password === null;

if ($generated) {
    /*
     * 14 characters with at least one of each class — more than the policy requires (an uppercase letter and a number), which costs nothing here.
     * Unambiguous characters only (no O/0, l/1/I): this is read off a
     * terminal and typed.
     */
    $classes = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnpqrstuvwxyz',
        '23456789',
        '!@#$%*?-+=',
    ];
    $all   = implode('', $classes);
    $chars = [];

    foreach ($classes as $class) {
        $chars[] = $class[random_int(0, strlen($class) - 1)];
    }
    while (count($chars) < 14) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    // Fisher–Yates with random_int, so the guaranteed characters are not
    // always in the first four places.
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    $password = implode('', $chars);
}

// The same strength rules as the Settings screen.
if ($problems = Auth::passwordProblems($password)) {
    fwrite(STDERR, "That password is not strong enough. Still needed:\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    exit(1);
}

Auth::setPin($slug, $password);

Database::statement(
    'UPDATE `admin_users` SET `failed_attempts` = 0, `locked_until` = NULL WHERE `id` = ?',
    [$admin['id']]
);

Auth::audit('admin.pin_reset_cli', $slug, 'setting', Auth::pinSetting($slug));

$app = AppRegistry::get($slug);
echo "\n  {$app->name}: admin password reset.\n";

if ($generated) {
    echo "  New password:  {$password}\n  Shown once. Change it at the app's Admin › Settings.\n";
}

echo "\n";
