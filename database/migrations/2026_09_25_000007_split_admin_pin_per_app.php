<?php

declare(strict_types=1);

use Platform\Core\AppRegistry;
use Platform\Core\Database;
use Platform\Core\Migration;

/**
 * One admin password per app, instead of one shared by every app.
 *
 * The single `auth.pin_hash` row is copied to `app.{slug}.pin_hash` for each
 * enabled app that does not have one yet, so both consoles keep opening with
 * the password people already use — until an owner changes each one at that
 * app's Settings. Then the shared row, and the shared-account row that went
 * with it, are removed: nothing reads them any more.
 *
 * Which account each app's password signs in as is the seeder's job, because
 * it creates those accounts.
 */
return new class extends Migration {
    public function up(): void
    {
        $shared = Database::selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', ['auth.pin_hash']);
        $hash   = $shared === null ? '' : (string) $shared['value'];

        if ($hash !== '') {
            foreach (AppRegistry::all() as $app) {
                Database::statement(
                    'INSERT IGNORE INTO `settings` (`key`, `value`, `updated_by`, `updated_at`) VALUES (?, ?, NULL, ?)',
                    ['app.' . $app->slug . '.pin_hash', $hash, date('Y-m-d H:i:s')]
                );
            }
        }

        Database::statement('DELETE FROM `settings` WHERE `key` IN (?, ?)', ['auth.pin_hash', 'auth.pin_user_id']);
    }
};
