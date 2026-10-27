<?php

declare(strict_types=1);

/**
 * Director Scorecard (Public) — admin console.
 *
 * Mounted under /admin/{slug}. Since the v13 directive scorecards are kept in
 * dsp_records, and the console's screens — Analytics, Research data, exports,
 * the board view and the Data seeder — read Services/RecordMirror's copy of
 * them in dsp_submissions, as the AI Sovereignty and Enterprise Scorecards'
 * consoles do. Requests (C4) works on dsp_records itself. The instrument
 * editor stays unrouted: the v14 page carries its own questions.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\RaixDirectorScorecardPublic\Controllers\Admin\AnalyticsController;
use Apps\RaixDirectorScorecardPublic\Controllers\Admin\DatasetController;
use Apps\RaixDirectorScorecardPublic\Controllers\Admin\ExportController;
use Apps\RaixDirectorScorecardPublic\Controllers\Admin\RequestsController;
use Apps\RaixDirectorScorecardPublic\Controllers\Admin\SeederController;

$router->get('/analytics', [AnalyticsController::class, 'index'], 'admin.dsp.analytics');
$router->get('/analytics/board/{id}', [AnalyticsController::class, 'board'], 'admin.dsp.board');

/* The figures behind the board dialog's PDF. Drawn in the browser by jsPDF,
   as the reference drew it, from numbers this application computed. */
$router->get('/analytics/board/{id}/report-data', [AnalyticsController::class, 'boardReportData'], 'admin.dsp.board.data');

$router->get('/research-data', [DatasetController::class, 'index'], 'admin.dsp.dataset');
$router->get('/research-data/{id}', [DatasetController::class, 'show'], 'admin.dsp.record');
$router->post('/research-data/{id}/delete', [DatasetController::class, 'destroy'], 'admin.dsp.record.delete');

/* The dataset's own tools, as a dialog over it — the reference's Console.
   Not a nav section: it belongs to that screen, not beside it. */
$router->get('/console', [DatasetController::class, 'console'], 'admin.dsp.console');

/* Emptying the dataset: the demo set and the previous edition's rows, never a
   scorecard the page saved. Behind dsp.submission.delete and a typed word. */
$router->post('/console/wipe', [DatasetController::class, 'wipe'], 'admin.dsp.wipe');

/* Downloads and the report. Not nav sections — actions on the dataset page.
   The report is a page rather than a file: the browser prints it to PDF. */
$router->get('/export/{format}', [ExportController::class, 'download'], 'admin.dsp.export');
$router->get('/report', [ExportController::class, 'report'], 'admin.dsp.report');

/* The same report as figures, for the console's PDF button. The document is
   drawn in the browser by jsPDF, as the reference drew it; every number in it
   is computed here, so the PDF cannot state one this application would not. */
$router->get('/report/data', [ExportController::class, 'reportData'], 'admin.dsp.report.data');

/* Demo data. Its own capability: this is the one screen that writes records
   which look exactly like real ones. */
$router->get('/data-seeder', [SeederController::class, 'index'], 'admin.dsp.seeder');
$router->post('/data-seeder', [SeederController::class, 'generate'], 'admin.dsp.seeder.generate');
$router->post('/data-seeder/purge', [SeederController::class, 'purge'], 'admin.dsp.seeder.purge');

/* Requests from individuals (C4), over dsp_records. */
$router->get('/requests', [RequestsController::class, 'index'], 'admin.dsp.requests');

/* A POST, so the address searched for never appears in a URL or an access log (C6). */
$router->post('/requests', [RequestsController::class, 'search'], 'admin.dsp.requests.search');
$router->get('/requests/{id}', [RequestsController::class, 'show'], 'admin.dsp.request');
$router->get('/requests/{id}/export', [RequestsController::class, 'export'], 'admin.dsp.request.export');
$router->post('/requests/{id}/identity', [RequestsController::class, 'correctIdentity'], 'admin.dsp.request.identity');
$router->post('/requests/{id}/json', [RequestsController::class, 'correctJson'], 'admin.dsp.request.json');
$router->post('/requests/{id}/restrict', [RequestsController::class, 'restrict'], 'admin.dsp.request.restrict');
$router->post('/requests/{id}/delete', [RequestsController::class, 'destroy'], 'admin.dsp.request.delete');
