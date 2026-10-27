<?php

declare(strict_types=1);

/**
 * TEMPLATE — copy this folder to apps/{your-slug}/ to start a new app.
 *
 * Folders beginning with an underscore are ignored by the registry, so this
 * skeleton is never loaded or migrated.
 *
 * To add an app:
 *   1. cp -r apps/_template apps/your-slug
 *   2. fill in this manifest (slug MUST equal the folder name)
 *   3. add 'your-slug' to config/apps.php
 *   4. php scripts/migrate.php && php scripts/seed.php
 *
 * The slug is FROZEN once any row references it — it appears in table names,
 * published asset paths, admin capabilities and public URLs at the same time.
 * Choose it as carefully as a database column name.
 */
return [
    'slug'         => 'your-slug',
    'name'         => 'Your App',
    'description'  => 'One sentence on what this app measures or does.',
    'namespace'    => 'Apps\\YourSlug',       // StudlyCase of the slug
    'table_prefix' => 'ys_',                  // short, unique, trailing underscore

    // Platform toolkits to opt into. Available: 'narrative', 'export'.
    // ('assessment' is promoted here once a second instrument proves its shape.)
    'uses' => [],

    'routes' => ['web.php', 'api.php', 'admin.php'],

    // Sections contributed to the shared admin console, each gated by a capability.
    'admin_nav' => [
        // ['section' => 'Overview', 'route' => 'admin.ys.overview', 'capability' => 'ys.view'],
    ],

    'capabilities' => [
        // 'ys.view',
        // 'ys.export',
    ],
];
