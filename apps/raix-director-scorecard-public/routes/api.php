<?php

declare(strict_types=1);

/**
 * Director Scorecard (Public) — the five endpoints of directive v13, Part B.
 *
 * Mounted under /{slug}, so '/api/scorecards' is served at
 * '/raix-director-scorecard-public/api/scorecards'. The page's line 922 is
 * rewritten to that /api when it is served (Services/ScorecardPage).
 *
 *   B1  POST   /api/scorecards        public   one record in, {} out
 *   B2  GET    /api/benchmark         public   {n, median, below} only
 *   B3  GET    /api/records           admin    synthetic + unrestricted real
 *   B4  PUT    /api/admin/synthetic   admin    replace the demonstration set
 *   B5  DELETE /api/admin/synthetic   admin    delete the demonstration set
 *
 * There is no endpoint that deletes real records in bulk, and there must not
 * be one. Single real records are deleted only from the admin console's
 * Requests screen (C4) and by the retention job (C3).
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\RaixDirectorScorecardPublic\Controllers\Admin\RecordsApiController;
use Apps\RaixDirectorScorecardPublic\Controllers\Admin\RequestsApiController;
use Apps\RaixDirectorScorecardPublic\Controllers\Public\ScorecardApiController;

$router->post('/api/scorecards', [ScorecardApiController::class, 'save'], 'dsp.api.scorecards');
$router->get('/api/benchmark', [ScorecardApiController::class, 'benchmark'], 'dsp.api.benchmark');

$router->get('/api/records', [RecordsApiController::class, 'index'], 'dsp.api.records');
$router->put('/api/admin/synthetic', [RecordsApiController::class, 'replaceSynthetic'], 'dsp.api.synthetic.replace');
$router->delete('/api/admin/synthetic', [RecordsApiController::class, 'deleteSynthetic'], 'dsp.api.synthetic.delete');

/*
 * Requests from individuals (C4) for the Requests view inside the page (a
 * served-page patch; see Services/ScorecardPage). Admin only, same gate as B3.
 * Not part of the directive's five: they serve staff, never a respondent.
 */
$router->post('/api/admin/requests/search', [RequestsApiController::class, 'search'], 'dsp.api.requests.search');
$router->get('/api/admin/requests/{id}', [RequestsApiController::class, 'show'], 'dsp.api.requests.show');
$router->get('/api/admin/requests/{id}/export', [RequestsApiController::class, 'export'], 'dsp.api.requests.export');
$router->post('/api/admin/requests/{id}/identity', [RequestsApiController::class, 'correctIdentity'], 'dsp.api.requests.identity');
$router->post('/api/admin/requests/{id}/record', [RequestsApiController::class, 'correctRecord'], 'dsp.api.requests.record');
$router->post('/api/admin/requests/{id}/restrict', [RequestsApiController::class, 'restrict'], 'dsp.api.requests.restrict');
$router->post('/api/admin/requests/{id}/delete', [RequestsApiController::class, 'destroy'], 'dsp.api.requests.delete');
