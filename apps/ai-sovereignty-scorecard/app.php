<?php

declare(strict_types=1);

/**
 * The AI Sovereignty Scorecard — app manifest.
 *
 * This file is the app's ONLY registration point. Nothing outside this folder
 * knows the app exists except the single line naming its slug in
 * config/apps.php, so anything the platform needs is declared here.
 *
 * The slug is FROZEN. It appears in sov_* table data, published asset paths,
 * admin capabilities and public URLs; renaming it orphans all four at once.
 *
 * The namespace is StudlyCase of the slug, and the autoloader converts it back
 * by inserting a hyphen before every capital — so it must be
 * `AiSovereigntyScorecard` and never `AISovereigntyScorecard`, which would
 * resolve to apps/a-i-sovereignty-scorecard/ and find nothing.
 *
 * Ported from AI_Sovereignty_Scorecard/index.html, which is the Enterprise
 * Scorecard's build with a different instrument, so this app began as a copy of
 * apps/enterprise-ai-scorecard and keeps its mechanics:
 *
 *  - It measures the ORGANISATION, not a person's own conduct. The respondent
 *    is a named executive reporting on their employer.
 *  - Four dimensions (data, infrastructure, decisional, adaptive), four
 *    questions each, on a FIVE-point scale with the two ends written out per
 *    question.
 *  - The level is the band of the OVERALL AVERAGE. The floor — the weakest
 *    dimension — is reported in analytics, but it does not cap the level and
 *    the respondent's own results page does not name it.
 */
return [
    'slug'         => 'ai-sovereignty-scorecard',
    'name'         => 'The AI Sovereignty Scorecard',
    'description'  => 'A self-assessment of an organization\'s AI sovereignty across four '
                    . 'dimensions. Sixteen questions about its current practices, each '
                    . 'answered on a scale from 1 to 5.',
    'namespace'    => 'Apps\\AiSovereigntyScorecard',
    'table_prefix' => 'sov_',

    // Platform toolkits this app opts into.
    'uses' => ['export'],

    'routes' => ['web.php', 'api.php', 'admin.php'],

    /*
     * Sections contributed to the shared admin console. A section listed here
     * MUST have a matching route, because AdminNav derives its path from the
     * label — "Research data" becomes /admin/{slug}/research-data. Listing one
     * that does not exist puts a dead link in everyone's sidebar.
     *
     * The reference's admin nav, in its order: Console · Analytics · Research
     * data · Data seeder, then Exit, which is the platform's. Console opens as
     * a wide dialog, as it did there; its page is what a browser without
     * scripting gets.
     */
    'admin_nav' => [
        ['section' => 'Console',       'route' => 'admin.sov.console',   'capability' => 'sov.view', 'modal' => true, 'wide' => true],
        ['section' => 'Analytics',     'route' => 'admin.sov.analytics', 'capability' => 'sov.view'],
        ['section' => 'Research data', 'route' => 'admin.sov.dataset',   'capability' => 'sov.view'],
        // Opens as a dialog, as it does in the reference; the page it points
        // at is still there and is what a browser without scripting gets.
        ['section' => 'Data seeder',   'route' => 'admin.sov.seeder',    'capability' => 'sov.seed', 'modal' => true],
        // Not in the reference: requests from individuals (directive v6, C4).
        ['section' => 'Requests',      'route' => 'admin.sov.requests',  'capability' => 'sov.view'],
    ],

    'capabilities' => [
        'sov.view',              // read analytics and the dataset
        'sov.export',            // download the dataset as CSV or JSON, and the codebook
        'sov.submission.delete', // remove a submission
        'sov.seed',              // generate synthetic demo records
    ],
];
