<?php

declare(strict_types=1);

/**
 * Enterprise Scorecard — admin screens.
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

use Apps\EnterpriseAiScorecard\Controllers\Admin\AnalyticsController;
use Apps\EnterpriseAiScorecard\Controllers\Admin\DatasetController;
use Apps\EnterpriseAiScorecard\Controllers\Admin\ExportController;
use Apps\EnterpriseAiScorecard\Controllers\Admin\RequestsController;
use Apps\EnterpriseAiScorecard\Controllers\Admin\SeederController;

/* Analytics — the reference's four panes, each at its own URL rather than in a
   JavaScript variable, so a pane can be linked to and reloaded. */
$router->get('/analytics', [AnalyticsController::class, 'index'], 'admin.es.analytics');

/* The organisation drilldown. Its own URL, and a dialog over the screen that
   linked to it, as the reference's openOrg() was. */
$router->get('/analytics/organisation/{id}', [AnalyticsController::class, 'organisation'], 'admin.es.organisation');
$router->get(
    '/analytics/organisation/{id}/report-data',
    [AnalyticsController::class, 'organisationReportData'],
    'admin.es.organisation.data'
);

$router->get('/research-data', [DatasetController::class, 'index'], 'admin.es.dataset');
$router->get('/research-data/{id}', [DatasetController::class, 'show'], 'admin.es.record');

/* The figures behind the record dialog's PDF — the same result payload the
   respondent's own results page is rendered from, so the copy an admin
   downloads is the document the respondent got and not a second rendering of
   it. Behind es.export, because that document names them. */
$router->get(
    '/research-data/{id}/scorecard-data',
    [DatasetController::class, 'scorecardData'],
    'admin.es.record.data'
);
$router->post('/research-data/{id}/delete', [DatasetController::class, 'destroy'], 'admin.es.record.delete');

/* The dataset's own tools, as a dialog over it — the reference's Console.
   Not a nav section: it belongs to that screen, not beside it. */
$router->get('/console', [DatasetController::class, 'console'], 'admin.es.console');

/* Emptying the dataset. Behind es.submission.delete — the capability that
   already means "remove a scorecard" — and behind a typed word, because this one
   removes REAL research data and no undo exists anywhere in the app. */
$router->post('/console/wipe', [DatasetController::class, 'wipe'], 'admin.es.wipe');

/* Downloads and the report. Not nav sections — actions on the dataset page.
   The report is a page rather than a file: the browser prints it to PDF. */
$router->get('/export/{format}', [ExportController::class, 'download'], 'admin.es.export');
$router->get('/report', [ExportController::class, 'report'], 'admin.es.report');

/* The same report as figures, for the console's PDF button. The document is
   drawn in the browser by jsPDF, as the reference drew it; every number in it is
   computed here, so the PDF cannot state one this application would not. */
$router->get('/report/data', [ExportController::class, 'reportData'], 'admin.es.report.data');

/* Demo data. Its own capability: this is the one screen that writes records which
   look exactly like real ones. */
$router->get('/data-seeder', [SeederController::class, 'index'], 'admin.es.seeder');
$router->post('/data-seeder', [SeederController::class, 'generate'], 'admin.es.seeder.generate');
$router->post('/data-seeder/purge', [SeederController::class, 'purge'], 'admin.es.seeder.purge');
$router->post('/data-seeder/default', [SeederController::class, 'restoreDefault'], 'admin.es.seeder.default');

/* Requests from individuals (directive v32, C4) over es_records: find by email,
   then export, correct, restrict or delete. Its nav section "Requests" derives
   /requests. Searching is a POST so no address ever lands in an access-log URL. */
$router->get('/requests', [RequestsController::class, 'index'], 'admin.es.requests');
$router->post('/requests', [RequestsController::class, 'search'], 'admin.es.requests.search');
$router->get('/requests/{id}', [RequestsController::class, 'show'], 'admin.es.request');
$router->get('/requests/{id}/export', [RequestsController::class, 'export'], 'admin.es.request.export');
$router->post('/requests/{id}/identity', [RequestsController::class, 'correctIdentity'], 'admin.es.request.identity');
$router->post('/requests/{id}/json', [RequestsController::class, 'correctJson'], 'admin.es.request.json');
$router->post('/requests/{id}/restrict', [RequestsController::class, 'restrict'], 'admin.es.request.restrict');
$router->post('/requests/{id}/delete', [RequestsController::class, 'destroy'], 'admin.es.request.delete');
