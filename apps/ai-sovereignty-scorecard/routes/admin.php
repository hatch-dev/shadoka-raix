<?php

declare(strict_types=1);

/**
 * AI Sovereignty Scorecard — admin screens.
 *
 * Mounted under /admin/{slug}. Every action here opens with a guard() call naming
 * the capability it needs.
 *
 * The paths must match the section slugs AdminNav derives from the manifest's
 * admin_nav labels: "Research data" becomes /research-data. A section in the
 * manifest without a route here is a dead link in everyone's sidebar.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\AiSovereigntyScorecard\Controllers\Admin\AnalyticsController;
use Apps\AiSovereigntyScorecard\Controllers\Admin\DatasetController;
use Apps\AiSovereigntyScorecard\Controllers\Admin\ExportController;
use Apps\AiSovereigntyScorecard\Controllers\Admin\RequestsController;
use Apps\AiSovereigntyScorecard\Controllers\Admin\SeederController;

/* Analytics — the reference's four panes, each at its own URL rather than in a
   JavaScript variable, so a pane can be linked to and reloaded. */
$router->get('/analytics', [AnalyticsController::class, 'index'], 'admin.sov.analytics');

/* The organisation drilldown. Its own URL, and a dialog over the screen that
   linked to it, as the reference's openOrg() was. */
$router->get('/analytics/organisation/{id}', [AnalyticsController::class, 'organisation'], 'admin.sov.organisation');
$router->get(
    '/analytics/organisation/{id}/report-data',
    [AnalyticsController::class, 'organisationReportData'],
    'admin.sov.organisation.data'
);

$router->get('/research-data', [DatasetController::class, 'index'], 'admin.sov.dataset');
$router->get('/research-data/{id}', [DatasetController::class, 'show'], 'admin.sov.record');

/* The figures behind the record dialog's PDF — the same result payload the
   respondent's own results page is rendered from, so the copy an admin
   downloads is the document the respondent got and not a second rendering of
   it. Behind sov.export, because that document names them. */
$router->get(
    '/research-data/{id}/scorecard-data',
    [DatasetController::class, 'scorecardData'],
    'admin.sov.record.data'
);
$router->post('/research-data/{id}/delete', [DatasetController::class, 'destroy'], 'admin.sov.record.delete');

/* The dataset's own tools, as a dialog over it — the reference's Console.
   Not a nav section: it belongs to that screen, not beside it. */
$router->get('/console', [DatasetController::class, 'console'], 'admin.sov.console');

/* Emptying the dataset. Behind sov.submission.delete — the capability that
   already means "remove a scorecard" — and behind a typed word, because this one
   removes REAL research data and no undo exists anywhere in the app. */
$router->post('/console/wipe', [DatasetController::class, 'wipe'], 'admin.sov.wipe');

/* Downloads and the report. Not nav sections — actions on the dataset page.
   The report is a page rather than a file: the browser prints it to PDF. */
$router->get('/export/{format}', [ExportController::class, 'download'], 'admin.sov.export');
$router->get('/report', [ExportController::class, 'report'], 'admin.sov.report');

/* The same report as figures, for the console's PDF button. The document is
   drawn in the browser by jsPDF, as the reference drew it; every number in it is
   computed here, so the PDF cannot state one this application would not. */
$router->get('/report/data', [ExportController::class, 'reportData'], 'admin.sov.report.data');

/* Demo data. Its own capability: this is the one screen that writes records which
   look exactly like real ones. */
$router->get('/data-seeder', [SeederController::class, 'index'], 'admin.sov.seeder');
$router->post('/data-seeder', [SeederController::class, 'generate'], 'admin.sov.seeder.generate');
$router->post('/data-seeder/purge', [SeederController::class, 'purge'], 'admin.sov.seeder.purge');
$router->post('/data-seeder/default', [SeederController::class, 'restoreDefault'], 'admin.sov.seeder.default');

/* Requests from individuals (directive v6, C4) over sov_records: find by email,
   then export, correct, restrict or delete. Its nav section "Requests" derives
   /requests. Searching is a POST so no address ever lands in an access-log URL. */
$router->get('/requests', [RequestsController::class, 'index'], 'admin.sov.requests');
$router->post('/requests', [RequestsController::class, 'search'], 'admin.sov.requests.search');
$router->get('/requests/{id}', [RequestsController::class, 'show'], 'admin.sov.request');
$router->get('/requests/{id}/export', [RequestsController::class, 'export'], 'admin.sov.request.export');
$router->post('/requests/{id}/identity', [RequestsController::class, 'correctIdentity'], 'admin.sov.request.identity');
$router->post('/requests/{id}/json', [RequestsController::class, 'correctJson'], 'admin.sov.request.json');
$router->post('/requests/{id}/restrict', [RequestsController::class, 'restrict'], 'admin.sov.request.restrict');
$router->post('/requests/{id}/delete', [RequestsController::class, 'destroy'], 'admin.sov.request.delete');
