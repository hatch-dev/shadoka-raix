<?php

declare(strict_types=1);

/**
 * AI Executive Readiness Scorecard — admin screens.
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

use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\AnalyticsController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\DatasetController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\ExportController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\RequestsController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\SeederController;

/* Analytics — the reference's five panes, each at its own URL. */
$router->get('/analytics', [AnalyticsController::class, 'index'], 'admin.exr.analytics');

/* One organization, by the name its respondents typed (the reference's
   openOrg()), as a dialog over whatever linked to it, and its PDF's figures. */
$router->get('/analytics/organisation', [AnalyticsController::class, 'organisation'], 'admin.exr.organisation');
$router->get('/analytics/organisation/report-data', [AnalyticsController::class, 'organisationReportData'], 'admin.exr.organisation.data');

$router->get('/research-data', [DatasetController::class, 'index'], 'admin.exr.dataset');
$router->get('/research-data/{id}', [DatasetController::class, 'show'], 'admin.exr.record');
$router->get('/research-data/{id}/scorecard-data', [DatasetController::class, 'scorecardData'], 'admin.exr.record.data');
$router->post('/research-data/{id}/delete', [DatasetController::class, 'destroy'], 'admin.exr.record.delete');

/* The reference's admin console, opened as a dialog from the bar. */
$router->get('/console', [DatasetController::class, 'console'], 'admin.exr.console');
$router->post('/console/wipe', [DatasetController::class, 'wipe'], 'admin.exr.wipe');

/* Downloads, and the research report's figures (drawn to PDF in the browser). */
$router->get('/export/{format}', [ExportController::class, 'download'], 'admin.exr.export');
$router->get('/report/data', [ExportController::class, 'reportData'], 'admin.exr.report.data');

/* Demo data. Its own capability: these records look exactly like real ones. */
$router->get('/data-seeder', [SeederController::class, 'index'], 'admin.exr.seeder');
$router->post('/data-seeder', [SeederController::class, 'generate'], 'admin.exr.seeder.generate');
$router->post('/data-seeder/purge', [SeederController::class, 'purge'], 'admin.exr.seeder.purge');
$router->post('/data-seeder/default', [SeederController::class, 'restoreDefault'], 'admin.exr.seeder.default');

/* Requests from individuals (directive v4, C4) over exr_records: find by email,
   then export, correct, restrict or delete. Its nav section "Requests" derives
   /requests. Searching is a POST so no address ever lands in an access-log URL. */
$router->get('/requests', [RequestsController::class, 'index'], 'admin.exr.requests');
$router->post('/requests', [RequestsController::class, 'search'], 'admin.exr.requests.search');
$router->get('/requests/{id}', [RequestsController::class, 'show'], 'admin.exr.request');
$router->get('/requests/{id}/export', [RequestsController::class, 'export'], 'admin.exr.request.export');
$router->post('/requests/{id}/identity', [RequestsController::class, 'correctIdentity'], 'admin.exr.request.identity');
$router->post('/requests/{id}/json', [RequestsController::class, 'correctJson'], 'admin.exr.request.json');
$router->post('/requests/{id}/restrict', [RequestsController::class, 'restrict'], 'admin.exr.request.restrict');
$router->post('/requests/{id}/delete', [RequestsController::class, 'destroy'], 'admin.exr.request.delete');
