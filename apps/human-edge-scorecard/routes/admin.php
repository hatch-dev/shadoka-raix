<?php

declare(strict_types=1);

/**
 * Human Edge Scorecard — admin screens.
 *
 * Mounted under /admin/{slug}. Every action opens with a guard() call naming the
 * capability it needs.
 *
 * The paths must match the section slugs AdminNav derives from the manifest's
 * admin_nav labels: "Research data" becomes /research-data. A section in the
 * manifest without a route here is a dead link in everyone's bar.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\HumanEdgeScorecard\Controllers\Admin\AnalyticsController;
use Apps\HumanEdgeScorecard\Controllers\Admin\DatasetController;
use Apps\HumanEdgeScorecard\Controllers\Admin\ExportController;
use Apps\HumanEdgeScorecard\Controllers\Admin\RequestsController;
use Apps\HumanEdgeScorecard\Controllers\Admin\SeederController;

/* Analytics — the page's six panes, each at its own URL. */
$router->get('/analytics', [AnalyticsController::class, 'index'], 'admin.he.analytics');

/* One organization, by the name its respondents typed (the page's openOrg()),
   as a dialog over whatever linked to it, and its PDF's records. */
$router->get('/analytics/organisation', [AnalyticsController::class, 'organisation'], 'admin.he.organisation');
$router->get('/analytics/organisation/report-data', [AnalyticsController::class, 'organisationReportData'], 'admin.he.organisation.data');

$router->get('/research-data', [DatasetController::class, 'index'], 'admin.he.dataset');
$router->get('/research-data/{id}', [DatasetController::class, 'show'], 'admin.he.record');
$router->get('/research-data/{id}/scorecard-data', [DatasetController::class, 'scorecardData'], 'admin.he.record.data');

/* The page's admin console, opened as a dialog from the bar. */
$router->get('/console', [DatasetController::class, 'console'], 'admin.he.console');

/* Downloads, and the research report's records (drawn to PDF in the browser). */
$router->get('/export/{format}', [ExportController::class, 'download'], 'admin.he.export');
$router->get('/report/data', [ExportController::class, 'reportData'], 'admin.he.report.data');

/* Demo data. Its own capability: these records look exactly like real ones. */
$router->get('/data-seeder', [SeederController::class, 'index'], 'admin.he.seeder');
$router->post('/data-seeder', [SeederController::class, 'generate'], 'admin.he.seeder.generate');
$router->post('/data-seeder/purge', [SeederController::class, 'purge'], 'admin.he.seeder.purge');
$router->post('/data-seeder/default', [SeederController::class, 'restoreDefault'], 'admin.he.seeder.default');

/* Requests from individuals (privacy directive, C4) over he_records: find by
   email, then export, correct, restrict or delete. Its nav section "Requests"
   derives /requests. Searching is a POST so no address ever lands in an
   access-log URL. */
$router->get('/requests', [RequestsController::class, 'index'], 'admin.he.requests');
$router->post('/requests', [RequestsController::class, 'search'], 'admin.he.requests.search');
$router->get('/requests/{id}', [RequestsController::class, 'show'], 'admin.he.request');
$router->get('/requests/{id}/export', [RequestsController::class, 'export'], 'admin.he.request.export');
$router->post('/requests/{id}/identity', [RequestsController::class, 'correctIdentity'], 'admin.he.request.identity');
$router->post('/requests/{id}/json', [RequestsController::class, 'correctJson'], 'admin.he.request.json');
$router->post('/requests/{id}/restrict', [RequestsController::class, 'restrict'], 'admin.he.request.restrict');
$router->post('/requests/{id}/delete', [RequestsController::class, 'destroy'], 'admin.he.request.delete');
