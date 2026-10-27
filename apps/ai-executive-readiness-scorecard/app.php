<?php

declare(strict_types=1);

/**
 * The AI Executive Readiness Scorecard — app manifest.
 *
 * This file is the app's ONLY registration point. Nothing outside this folder
 * knows the app exists except the single line naming its slug in
 * config/apps.php, so anything the platform needs is declared here.
 *
 * The slug is FROZEN. It appears in exr_* table data, published asset paths,
 * admin capabilities and public URLs; renaming it orphans all four at once.
 *
 * The namespace is StudlyCase of the slug, and the autoloader converts it back
 * by inserting a hyphen before every capital — so it must be
 * `AiExecutiveReadinessScorecard` and never `AIExecutiveReadinessScorecard`,
 * which would resolve to apps/a-i-executive-readiness-scorecard/ and find
 * nothing.
 *
 * Ported from AI_Executive_Readiness_Scorecard/index.html, which shares the
 * Enterprise and Sovereignty builds' chrome, palette and hero image, so this
 * app began as a copy of apps/ai-sovereignty-scorecard. The instrument is what
 * is different, and it changes everything downstream of it:
 *
 *  - It is a PAIRED assessment. Twelve questions in four dimensions, and each
 *    is answered twice: once by the respondent about themselves and once about
 *    their organization's leaders collectively. Twenty-four ratings make one
 *    questionnaire.
 *  - The two sets of ratings are scored SEPARATELY into two profiles, each with
 *    its own dimension scores, overall mean, level and floor. The level is the
 *    band of that profile's overall mean of twelve ratings.
 *  - The difference between them (self minus leadership) is reported per
 *    question, per dimension and overall, and is the subject of its own
 *    Comparison pane in the analytics.
 */
return [
    'slug'         => 'ai-executive-readiness-scorecard',
    'name'         => 'The AI Executive Readiness Scorecard',
    'description'  => 'Assess your organization\'s readiness to lead AI-related change across four '
                    . 'dimensions. Answer 12 questions for both yourself and your organization\'s '
                    . 'leadership team, then compare the two profiles.',
    'namespace'    => 'Apps\\AiExecutiveReadinessScorecard',
    'table_prefix' => 'exr_',

    // Platform toolkits this app opts into.
    'uses' => ['export'],

    'routes' => ['web.php', 'api.php', 'admin.php'],

    /*
     * Sections contributed to the shared admin console. A section listed here
     * MUST have a matching route, because AdminNav derives its path from the
     * label — "Research data" becomes /admin/{slug}/research-data.
     *
     * The reference's admin nav, in its order: Console · Analytics · Research
     * data · Data seeder, then Exit, which is the platform's. Console and the
     * seeder open as dialogs, as they did there; their pages are what a browser
     * without scripting gets.
     */
    'admin_nav' => [
        ['section' => 'Console',       'route' => 'admin.exr.console',   'capability' => 'exr.view', 'modal' => true, 'wide' => true],
        ['section' => 'Analytics',     'route' => 'admin.exr.analytics', 'capability' => 'exr.view'],
        ['section' => 'Research data', 'route' => 'admin.exr.dataset',   'capability' => 'exr.view'],
        ['section' => 'Data seeder',   'route' => 'admin.exr.seeder',    'capability' => 'exr.seed', 'modal' => true],
        // Not in the reference: requests from individuals (directive v4, C4).
        ['section' => 'Requests',      'route' => 'admin.exr.requests',  'capability' => 'exr.view'],
    ],

    'capabilities' => [
        'exr.view',              // read analytics and the dataset
        'exr.export',            // download the dataset as CSV or JSON, the codebook and the PDFs
        'exr.submission.delete', // remove a submission, or empty the dataset
        'exr.seed',              // generate synthetic demo records
    ],
];
