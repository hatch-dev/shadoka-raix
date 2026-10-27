<?php

declare(strict_types=1);

/**
 * The Enterprise Scorecard — app manifest.
 *
 * This file is the app's ONLY registration point. Nothing outside this folder
 * knows the app exists except the single line naming its slug in
 * config/apps.php, so anything the platform needs is declared here.
 *
 * The slug is FROZEN. It appears in es_* table data, published asset paths,
 * admin capabilities and public URLs; renaming it orphans all four at once.
 *
 * The namespace is StudlyCase of the slug, and the autoloader converts it back
 * by inserting a hyphen before every capital — so it must be
 * `EnterpriseAiScorecard` and never `EnterpriseAIScorecard`, which would
 * resolve to apps/enterprise-a-i-scorecard/ and find nothing.
 *
 * Where this instrument differs from the Director Scorecard, and why the two
 * are separate apps rather than two versions of one:
 *
 *  - It measures the ORGANISATION, not a person's own conduct. The respondent
 *    is a named executive reporting on their employer.
 *  - Six dimensions, five questions each, on a FIVE-point scale with the two
 *    ends written out per question. The Director Scorecard has seven
 *    competencies, five statements each, on four levels shared by every item.
 *  - The level is the band of the OVERALL AVERAGE. The floor — the weakest
 *    dimension — is reported alongside and in analytics, but it does not cap
 *    the level. That is the opposite of the Director Scorecard's floor rule,
 *    and it is why ScoringRule is an interface: the mechanics are the
 *    platform's, the rule is each instrument's own.
 */
return [
    'slug'         => 'enterprise-ai-scorecard',
    'name'         => 'The Enterprise Scorecard',
    'description'  => 'A self-assessment of an organisation\'s readiness for AI across six '
                    . 'dimensions. Thirty questions about what the organisation actually '
                    . 'does, each answered on a five-point scale.',
    'namespace'    => 'Apps\\EnterpriseAiScorecard',
    'table_prefix' => 'es_',

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
        ['section' => 'Console',       'route' => 'admin.es.console',   'capability' => 'es.view', 'modal' => true, 'wide' => true],
        ['section' => 'Analytics',     'route' => 'admin.es.analytics', 'capability' => 'es.view'],
        ['section' => 'Research data', 'route' => 'admin.es.dataset',   'capability' => 'es.view'],
        // Opens as a dialog, as it does in the reference; the page it points
        // at is still there and is what a browser without scripting gets.
        ['section' => 'Data seeder',   'route' => 'admin.es.seeder',    'capability' => 'es.seed', 'modal' => true],
        // Not in the reference: requests from individuals (directive v32, C4).
        ['section' => 'Requests',      'route' => 'admin.es.requests',  'capability' => 'es.view'],
    ],

    'capabilities' => [
        'es.view',              // read analytics and the dataset
        'es.export',            // download the dataset as CSV or JSON, and the codebook
        'es.submission.delete', // remove a submission
        'es.seed',              // generate synthetic demo records
    ],
];
