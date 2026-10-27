<?php

declare(strict_types=1);

/**
 * Human Edge Scorecard — JSON API.
 *
 * Mounted by the platform under /{slug}, so '/api/scorecards' below is served
 * at '/human-edge-scorecard/api/scorecards'.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\HumanEdgeScorecard\Controllers\Admin\RecordsApiController;
use Apps\HumanEdgeScorecard\Controllers\Admin\RequestsApiController;
use Apps\HumanEdgeScorecard\Controllers\Admin\SettingsApiController;
use Apps\HumanEdgeScorecard\Controllers\Public\ScorecardApiController;

/*
 * The privacy directive the RAIX scorecards share, Part B — the four endpoints
 * the Human Edge page calls at API_BASE, over he_records.
 *
 *   B1  POST   /api/scorecards        public   one record in, {} out
 *   B2  GET    /api/records           admin    synthetic + unrestricted real
 *   B3  PUT    /api/admin/synthetic   admin    replace the demonstration set
 *   B4  DELETE /api/admin/synthetic   admin    delete the demonstration set
 *
 * "Admin" is this app's own password sign-in. There is no endpoint that
 * deletes real records in bulk, and there must not be one.
 */
$router->post('/api/scorecards', [ScorecardApiController::class, 'save'], 'he.api.scorecards');
$router->get('/api/records', [RecordsApiController::class, 'index'], 'he.api.records');
$router->put('/api/admin/synthetic', [RecordsApiController::class, 'replaceSynthetic'], 'he.api.synthetic.replace');
$router->delete('/api/admin/synthetic', [RecordsApiController::class, 'deleteSynthetic'], 'he.api.synthetic.delete');

/*
 * The page's own Requests and Settings views (served-page patches, see
 * Services/ScorecardPage). Admin only, same gate as B2–B4. Not part of the
 * directive's four: they serve the admin, never a respondent.
 */
$router->post('/api/admin/requests/search', [RequestsApiController::class, 'search'], 'he.api.requests.search');
$router->get('/api/admin/requests/{id}', [RequestsApiController::class, 'show'], 'he.api.requests.show');
$router->get('/api/admin/requests/{id}/export', [RequestsApiController::class, 'export'], 'he.api.requests.export');
$router->post('/api/admin/requests/{id}/identity', [RequestsApiController::class, 'correctIdentity'], 'he.api.requests.identity');
$router->post('/api/admin/requests/{id}/record', [RequestsApiController::class, 'correctRecord'], 'he.api.requests.record');
$router->post('/api/admin/requests/{id}/restrict', [RequestsApiController::class, 'restrict'], 'he.api.requests.restrict');
$router->post('/api/admin/requests/{id}/delete', [RequestsApiController::class, 'destroy'], 'he.api.requests.delete');
$router->get('/api/admin/settings', [SettingsApiController::class, 'show'], 'he.api.settings');
$router->post('/api/admin/settings/password', [SettingsApiController::class, 'updatePassword'], 'he.api.settings.password');
