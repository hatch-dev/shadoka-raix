<?php

declare(strict_types=1);

/**
 * The Director Scorecard, public edition — app manifest.
 *
 * SINCE 29 SEP 2026 (directive v13, reference/Directive_Privacy_DEV_Public_
 * Director_Scorecard_v13_28Sep2026.md) the public app is Shadoka's supplied
 * page, page/RAIX_Director_Scorecard_public_v14.html (v13 plus the automatic-
 * saving directive of 28 Sep 2026), served verbatim except
 * line 922 (API_BASE), plus the directive's five endpoints over dsp_records
 * (routes/api.php) and a Requests screen in the console. The code below the
 * next paragraph describes the edition it replaced. Since 5 Oct 2026 its
 * console screens are routed again, over dsp_submissions rows that
 * Services/RecordMirror keeps in step with dsp_records (as the AI Sovereignty
 * and Enterprise Scorecards do); its public flow stays unrouted. A copy of
 * that edition is in storage/archive/2026-09-29_dsp-pre-v13-rebuild/.
 *
 * Ported from RAIX_Director_Scorecard_Public/index.html, which stays beside the
 * platform as a standalone build Apache serves directly.
 *
 * It uses the same instrument as raix-director-scorecard (seven competencies,
 * five statements each, four rungs, the floor rule). What makes it a separate
 * app rather than a setting on that one is who it is for and what it collects:
 * it opens on a consent gate and REQUIRES the director's name, email address,
 * phone number, board and company size, so its records carry personal data the
 * other app's anonymous records never do. Keeping them in their own tables
 * (`dsp_`), behind their own admin account, is what keeps the two datasets from
 * mixing.
 *
 * The slug is FROZEN. It appears in dsp_* table data, published asset paths,
 * admin capabilities and public URLs; renaming it orphans all four at once.
 */
return [
    'slug'         => 'raix-director-scorecard-public',
    'name'         => 'The Director Scorecard (Public)',
    'description'  => 'The public edition of the Director Scorecard: a consent gate and '
                    . 'required contact details in front of the same seven-competency '
                    . 'self-assessment, where a director\'s level is set by their weakest '
                    . 'competency, not their average.',
    'namespace'    => 'Apps\\RaixDirectorScorecardPublic',
    'table_prefix' => 'dsp_',

    'uses' => ['narrative', 'export'],

    'routes' => ['web.php', 'api.php', 'admin.php'],

    /*
     * The reference's admin nav, in its order. Exit is the platform's and is
     * added by the shell. A section listed here MUST have a matching route:
     * AdminNav derives the path from the label ("Research data" becomes
     * /admin/{slug}/research-data).
     *
     * The instrument editor has routes and screens but no nav entry, as in the
     * sibling app — the reference's admin has none.
     */
    /*
     * Since the v13 directive (29 Sep 2026) scorecards are kept in
     * dsp_records. Since 5 Oct 2026 the console's Analytics, Research data
     * and Data seeder read Services/RecordMirror's copy of them, as the AI
     * Sovereignty and Enterprise Scorecards do; Requests (C4) works on
     * dsp_records itself. The instrument editor stays unrouted.
     */
    'admin_nav' => [
        ['section' => 'Analytics',     'route' => 'admin.dsp.analytics', 'capability' => 'dsp.view'],
        ['section' => 'Research data', 'route' => 'admin.dsp.dataset',   'capability' => 'dsp.view'],
        ['section' => 'Data seeder',   'route' => 'admin.dsp.seeder',    'capability' => 'dsp.seed', 'modal' => true],
        ['section' => 'Requests',      'route' => 'admin.dsp.requests',  'capability' => 'dsp.view'],
    ],

    'capabilities' => [
        'dsp.view',              // read analytics and the dataset, GET /api/records, find a person's record on Requests
        'dsp.export',            // download the dataset; export one person's record on Requests
        'dsp.submission.delete', // remove a scorecard; correct, restrict or delete one real record on Requests
        'dsp.instrument.edit',   // unused since v13 (the instrument is inside the page); kept so roles stay valid
        'dsp.seed',              // generate demo records; PUT / DELETE /api/admin/synthetic
    ],
];
