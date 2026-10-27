<?php

declare(strict_types=1);

/**
 * Director Scorecard — admin screens.
 *
 * Mounted under /admin/{slug}. Every action here opens with a guard() call
 * naming the capability it needs.
 *
 * The paths must match the section slugs AdminNav derives from the manifest's
 * admin_nav labels: "Research data" becomes /research-data. A section in the
 * manifest without a route here is a dead link in everyone's sidebar.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\RaixDirectorScorecard\Controllers\Admin\AnalyticsController;
use Apps\RaixDirectorScorecard\Controllers\Admin\DatasetController;
use Apps\RaixDirectorScorecard\Controllers\Admin\ExportController;
use Apps\RaixDirectorScorecard\Controllers\Admin\InstrumentController;
use Apps\RaixDirectorScorecard\Controllers\Admin\SeederController;

$router->get('/analytics', [AnalyticsController::class, 'index'], 'admin.ds.analytics');
$router->get('/analytics/board/{id}', [AnalyticsController::class, 'board'], 'admin.ds.board');

/* The figures behind the board dialog's PDF. Drawn in the browser by jsPDF,
   as the reference drew it, from numbers this application computed. */
$router->get('/analytics/board/{id}/report-data', [AnalyticsController::class, 'boardReportData'], 'admin.ds.board.data');

$router->get('/research-data', [DatasetController::class, 'index'], 'admin.ds.dataset');
$router->get('/research-data/{id}', [DatasetController::class, 'show'], 'admin.ds.record');
$router->post('/research-data/{id}/delete', [DatasetController::class, 'destroy'], 'admin.ds.record.delete');

/* The dataset's own tools, as a dialog over it — the reference's Console.
   Not a nav section: it belongs to that screen, not beside it. */
$router->get('/console', [DatasetController::class, 'console'], 'admin.ds.console');

/* Emptying the dataset. Behind ds.submission.delete — the capability that
   already means "remove a scorecard" — and behind a typed word, because this
   one removes REAL research data and no undo exists anywhere in the app. */
$router->post('/console/wipe', [DatasetController::class, 'wipe'], 'admin.ds.wipe');

/* The instrument. Reading is ds.view; every write is ds.instrument.edit, and
   the service refuses a published version whatever the route allows. */
$router->get('/instrument', [InstrumentController::class, 'index'], 'admin.ds.instrument');
$router->post('/instrument/draft', [InstrumentController::class, 'createDraft'], 'admin.ds.instrument.draft');
$router->get('/instrument/{id}/edit', [InstrumentController::class, 'edit'], 'admin.ds.instrument.edit');
$router->post('/instrument/{id}/competency/{competencyId}', [InstrumentController::class, 'updateCompetency'], 'admin.ds.instrument.competency');
$router->post('/instrument/{id}/publish', [InstrumentController::class, 'publish'], 'admin.ds.instrument.publish');
$router->post('/instrument/{id}/discard', [InstrumentController::class, 'discard'], 'admin.ds.instrument.discard');

/* Downloads and the report. Not nav sections — actions on the dataset page.
   The report is a page rather than a file: the browser prints it to PDF. */
$router->get('/export/{format}', [ExportController::class, 'download'], 'admin.ds.export');
$router->get('/report', [ExportController::class, 'report'], 'admin.ds.report');

/* The same report as figures, for the console's PDF button. The document is
   drawn in the browser by jsPDF, as the reference drew it; every number in it
   is computed here, so the PDF cannot state one this application would not. */
$router->get('/report/data', [ExportController::class, 'reportData'], 'admin.ds.report.data');

/* Demo data. Its own capability: this is the one screen that writes records
   which look exactly like real ones. */
$router->get('/data-seeder', [SeederController::class, 'index'], 'admin.ds.seeder');
$router->post('/data-seeder', [SeederController::class, 'generate'], 'admin.ds.seeder.generate');
$router->post('/data-seeder/purge', [SeederController::class, 'purge'], 'admin.ds.seeder.purge');
