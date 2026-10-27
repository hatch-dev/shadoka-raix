<?php

declare(strict_types=1);

/**
 * The Human Edge Scorecard — app manifest.
 *
 * This file is the app's ONLY registration point. Nothing outside this folder
 * knows the app exists except the single line naming its slug in
 * config/apps.php, so anything the platform needs is declared here.
 *
 * The slug is FROZEN. It appears in he_* table data, published asset paths,
 * admin capabilities and public URLs; renaming it orphans all four at once.
 *
 * The namespace is StudlyCase of the slug, and the autoloader converts it back
 * by inserting a hyphen before every capital — so it must be
 * `HumanEdgeScorecard`, which resolves to apps/human-edge-scorecard/.
 *
 * Built from Shadoka's supplied Human_Edge_Scorecard/index.html (notice
 * HE-PN-1) the way the other scorecards' latest editions are: the page is the
 * whole public app (overview, consent, questionnaire, results and its own
 * admin views), served as supplied apart from the patches listed in
 * Services/ScorecardPage, and it talks to four endpoints over he_records.
 * As on the other scorecards, the admin console keeps its own relational
 * copy (he_instruments … he_submission_scores, written by
 * Services/RecordMirror from he_records), and its screens — Console,
 * Analytics, Research data, Data seeder — are server-side ports of the page's
 * own admin views, computed by the page's functions (Services/Stats) over
 * that copy. Requests and Settings are the platform's per-app screens.
 *
 * Six skills (Judgment, Adaptability, Empathy, Conscience, Imagination,
 * Authenticity) on one five-level practice scale; the level is the band of
 * the overall average of all answers.
 */
return [
    'slug'         => 'human-edge-scorecard',
    'name'         => 'The Human Edge Scorecard',
    'description'  => 'How well are you practicing the skills that humans will need in an AI-driven world? '
                    . 'Six skills: judgment, adaptability, empathy, conscience, imagination and authenticity.',
    'namespace'    => 'Apps\\HumanEdgeScorecard',
    'table_prefix' => 'he_',

    'routes' => ['web.php', 'api.php', 'admin.php'],

    /*
     * Sections contributed to the shared admin console. A section listed here
     * MUST have a matching route, because AdminNav derives its path from the
     * label — "Requests" becomes /admin/{slug}/requests.
     *
     * The page's admin menu, in its order: Console · Analytics · Research data
     * · Data seeder, then Exit, which is the platform's. Console and the
     * seeder open as dialogs, as they do there; their pages are what a browser
     * without scripting gets.
     */
    'admin_nav' => [
        ['section' => 'Console',       'route' => 'admin.he.console',   'capability' => 'he.view', 'modal' => true, 'wide' => true],
        ['section' => 'Analytics',     'route' => 'admin.he.analytics', 'capability' => 'he.view'],
        ['section' => 'Research data', 'route' => 'admin.he.dataset',   'capability' => 'he.view'],
        ['section' => 'Data seeder',   'route' => 'admin.he.seeder',    'capability' => 'he.seed', 'modal' => true],
        // Not in the page's menu: requests from individuals (privacy directive, C4).
        ['section' => 'Requests',      'route' => 'admin.he.requests',  'capability' => 'he.view'],
    ],

    'capabilities' => [
        'he.view',              // read the records, analytics and requests
        'he.export',            // export a person's record (requests)
        'he.submission.delete', // correct, restrict or delete a person's record
        'he.seed',              // replace or delete the synthetic demo records
    ],
];
