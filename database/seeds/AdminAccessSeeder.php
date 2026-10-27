<?php

declare(strict_types=1);

use Platform\Core\AppRegistry;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\Seeder;
use Platform\Core\Settings;

/**
 * The roles, and the one administrator record the console acts as.
 *
 * Capabilities are collected from the enabled apps' manifests, so adding an app
 * automatically widens the owner role without editing this file.
 *
 * Since the sign-in was removed on 22 Sep 2026 this record is an IDENTITY, not
 * a credential: it gives every audit row an actor and gives the console a role
 * to take its sections from. Nobody signs in as it. Its password column is
 * filled with an unusable random value because the schema requires one — never
 * with anything anybody is meant to type.
 */
return new class extends Seeder {
    public function name(): string
    {
        return 'Admin roles and first user';
    }

    public function run(): void
    {
        $this->seedRoles();
        $this->seedFirstUser();

        foreach (AppRegistry::all() as $app) {
            $this->seedAppAdmin($app);
        }
    }

    /**
     * Every app has its OWN administrator: a role holding only that app's
     * capabilities, one account in it, and the settings row that says the
     * app's password signs in as that account.
     *
     * The password itself is never seeded. It lives only in the database and
     * is set at that app's Settings, or with scripts/admin-password.php.
     */
    private function seedAppAdmin(\Platform\Core\AppManifest $app): void
    {
        $label        = sprintf('  %-18s ', $app->slug);
        $roleSlug     = $app->slug . '-admin';
        $capabilities = array_values(array_unique(array_merge(
            ['admin.access', 'admin.settings'],
            $app->capabilities
        )));

        $role = Database::selectOne('SELECT `id` FROM `admin_roles` WHERE `slug` = ?', [$roleSlug]);

        if ($role === null) {
            $roleId = Database::insert('admin_roles', [
                'slug'         => $roleSlug,
                'name'         => $app->name . ' admin',
                'description'  => 'Full access to ' . $app->name . ' only. Cannot open any other app.',
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
                'is_system'    => 1,
                'created_at'   => $this->now(),
            ]);
        } else {
            $roleId = (int) $role['id'];
            // Kept in step with the manifest as the app gains capabilities.
            Database::statement(
                'UPDATE `admin_roles` SET `capabilities` = ?, `updated_at` = ? WHERE `id` = ?',
                [json_encode($capabilities, JSON_THROW_ON_ERROR), $this->now(), $roleId]
            );
        }

        $existing = Auth::appAdmin($app->slug);

        if ($existing === null) {
            $email  = 'admin@' . $app->slug . '.raix.local';
            $userId = (int) (Database::scalar('SELECT `id` FROM `admin_users` WHERE `email` = ?', [$email]) ?? 0);

            if ($userId === 0) {
                $userId = Database::insert('admin_users', [
                    'role_id'              => $roleId,
                    'name'                 => $app->name . ' Administrator',
                    'email'                => $email,
                    // Unusable: this account is reached by the app's password
                    // (Auth::attemptPin), never by the email form.
                    'password_hash'        => password_hash(bin2hex(random_bytes(32)), (string) config('auth.password_algo')),
                    'is_active'            => 1,
                    'must_change_password' => 0,
                    'created_at'           => $this->now(),
                ]);
            }

            Settings::put(Auth::adminSetting($app->slug), (string) $userId);
            $this->say($label . 'admin account created');
        } else {
            $this->say($label . 'admin account exists — left untouched');
        }

        $this->say($label . (Auth::pinEnabled($app->slug)
            ? 'password set in the database — left untouched'
            : 'NO password yet — run: php scripts/admin-password.php --app=' . $app->slug));
    }

    private function seedRoles(): void
    {
        $all = AppRegistry::capabilities();

        $roles = [
            [
                'slug'         => 'owner',
                'name'         => 'Owner',
                'description'  => 'Full access, including instrument editing and the seeder.',
                'capabilities' => $all,
            ],
            [
                'slug'         => 'researcher',
                'name'         => 'Researcher',
                'description'  => 'Reads analytics and the dataset, and exports. Cannot edit the instrument or generate demo data.',
                'capabilities' => array_values(array_filter(
                    $all,
                    static fn (string $c): bool => str_ends_with($c, '.view')
                        || str_ends_with($c, '.export')
                        || $c === 'admin.access'
                )),
            ],
            [
                'slug'         => 'viewer',
                'name'         => 'Viewer',
                'description'  => 'Reads analytics only. No exports.',
                'capabilities' => array_values(array_filter(
                    $all,
                    static fn (string $c): bool => str_ends_with($c, '.view') || $c === 'admin.access'
                )),
            ],
        ];

        $created = 0;
        foreach ($roles as $role) {
            $exists = Database::selectOne('SELECT `id` FROM `admin_roles` WHERE `slug` = ?', [$role['slug']]);

            if ($exists !== null) {
                // Keep an existing role's capability list current as apps are
                // added, without touching its name or description.
                Database::statement(
                    'UPDATE `admin_roles` SET `capabilities` = ?, `updated_at` = ? WHERE `id` = ?',
                    [json_encode($role['capabilities'], JSON_THROW_ON_ERROR), $this->now(), $exists['id']]
                );
                continue;
            }

            Database::insert('admin_roles', [
                'slug'         => $role['slug'],
                'name'         => $role['name'],
                'description'  => $role['description'],
                'capabilities' => json_encode($role['capabilities'], JSON_THROW_ON_ERROR),
                'is_system'    => 1,
                'created_at'   => $this->now(),
            ]);
            $created++;
        }

        $this->say(sprintf('  roles              %d created, %d refreshed', $created, count($roles) - $created));
    }

    private function seedFirstUser(): void
    {
        $count = (int) Database::scalar('SELECT COUNT(*) FROM `admin_users`');

        if ($count > 0) {
            $this->say('  admin identity     already exists — left untouched');
            return;
        }

        $role = Database::selectOne('SELECT `id` FROM `admin_roles` WHERE `slug` = ?', ['owner']);

        if ($role === null) {
            $this->say('  admin identity     SKIPPED: the owner role is missing');
            return;
        }

        $email = 'admin@raix.local';

        Database::insert('admin_users', [
            'role_id'              => $role['id'],
            'name'                 => 'RAI-X Administrator',
            'email'                => $email,
            // Random and never shown: there is no sign-in to use it with, and
            // the column is NOT NULL. Nothing can be typed to match this.
            'password_hash'        => password_hash(bin2hex(random_bytes(32)), (string) config('auth.password_algo')),
            'is_active'            => 1,
            'must_change_password' => 0,
            'created_at'           => $this->now(),
        ]);

        $this->say('  admin identity     created');
        $this->say('');
        $this->say('  ┌─ THE ADMIN CONSOLE IS OPEN — there is no sign-in');
        $this->say('  │  /admin answers to anyone who can reach it');
        $this->say('  │  it acts as  ' . $email . '  (role: owner)');
        $this->say('  └─ restrict it outside the app, or point ADMIN_EMAIL at a narrower role');
    }
};
