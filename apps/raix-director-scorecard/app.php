<?php

declare(strict_types=1);

/**
 * The Director Scorecard — app manifest.
 *
 * This file is the app's ONLY registration point. Nothing outside this folder
 * knows the app exists except the single line naming its slug in
 * config/apps.php, so anything the platform needs is declared here.
 *
 * The slug is FROZEN. It appears in ds_* table data, published asset paths,
 * admin capabilities and public URLs; renaming it orphans all four at once.
 */
return [
    'slug'         => 'raix-director-scorecard',
    'name'         => 'The Director Scorecard',
    'description'  => 'A self-assessment for an individual board member across seven '
                    . 'Responsible AI competencies. The director\'s level is set by '
                    . 'their weakest competency, not their average.',
    'namespace'    => 'Apps\\RaixDirectorScorecard',
    'table_prefix' => 'ds_',

    // Platform toolkits this app opts into.
    'uses' => ['narrative', 'export'],

    'routes' => ['web.php', 'api.php', 'admin.php'],

    // Sections this app contributes to the shared admin console. Each is gated
    // by its capability, so a role can hold one section and not another.
    /*
     * Sections contributed to the shared admin console. A section listed here
     * MUST have a matching route, because AdminNav derives its path from the
     * label — "Research data" becomes /admin/{slug}/research-data. Listing one
     * that does not exist puts a dead link in everyone's sidebar.
     *
     */
    /*
     * Exactly the three sections the single-file build's admin nav carried,
     * in its order. Exit is the platform's and is added by the shell.
     *
     * The instrument editor is deliberately NOT listed. Its routes, capability
     * and screens are all still here and working — `admin.ds.instrument` —
     * it simply has no nav entry, because the reference's admin has four items
     * and this is meant to match it. Putting it back is this one line.
     */
    'admin_nav' => [
        ['section' => 'Analytics',     'route' => 'admin.ds.analytics',  'capability' => 'ds.view'],
        ['section' => 'Research data', 'route' => 'admin.ds.dataset',    'capability' => 'ds.view'],
        // Opens as a dialog, as it does in the reference; the page it points
        // at is still there and is what a browser without scripting gets.
        ['section' => 'Data seeder',   'route' => 'admin.ds.seeder',     'capability' => 'ds.seed', 'modal' => true],
    ],

    'capabilities' => [
        'ds.view',              // read analytics and the dataset
        'ds.export',            // download the dataset as CSV or JSON, and the codebook
        'ds.submission.delete', // remove a submission
        'ds.instrument.edit',   // edit competencies and items, publish a version
        'ds.seed',              // generate synthetic demo records
    ],
];
