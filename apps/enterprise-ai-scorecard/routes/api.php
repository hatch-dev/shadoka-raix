<?php

declare(strict_types=1);

/**
 * Enterprise Scorecard — JSON API.
 *
 * Mounted by the platform under /{slug}, so '/api/instrument' below is served at
 * '/enterprise-ai-scorecard/api/instrument'.
 *
 * The contract with the browser: it sends MARKS. Dimension scores, the overall
 * average, the floor and the level are all derived on the server. The reference
 * build let the client compute and store its own result.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\EnterpriseAiScorecard\Controllers\Admin\RecordsApiController;
use Apps\EnterpriseAiScorecard\Controllers\Admin\RequestsApiController;
use Apps\EnterpriseAiScorecard\Controllers\Admin\SettingsApiController;
use Apps\EnterpriseAiScorecard\Controllers\Public\InstrumentController;
use Apps\EnterpriseAiScorecard\Controllers\Public\ScorecardApiController;
use Apps\EnterpriseAiScorecard\Controllers\Public\SubmissionController;

/* The live instrument — replaces the hard-coded DIMS/RUNGS in the reference. */
$router->get('/api/instrument', [InstrumentController::class, 'show'], 'es.api.instrument');

/* A scorecard's life. The token is the only credential; there are no accounts. */
$router->post('/api/submissions', [SubmissionController::class, 'start'], 'es.api.start');
$router->get('/api/submissions/{token}', [SubmissionController::class, 'show'], 'es.api.show');
$router->patch('/api/submissions/{token}', [SubmissionController::class, 'save'], 'es.api.save');
$router->post('/api/submissions/{token}/submit', [SubmissionController::class, 'submit'], 'es.api.submit');
$router->post('/api/submissions/{token}/confirm', [SubmissionController::class, 'confirm'], 'es.api.confirm');

/*
 * Directive v32 (Directive_Privacy_DEV_Enterprise_Scorecard_v32_28Sep2026),
 * Part B — the four endpoints the v32 page calls at API_BASE, over es_records.
 * They run beside the submission API above, which they do not touch.
 *
 *   B1  POST   /api/scorecards        public   one record in, {} out
 *   B2  GET    /api/records           admin    synthetic + unrestricted real
 *   B3  PUT    /api/admin/synthetic   admin    replace the demonstration set
 *   B4  DELETE /api/admin/synthetic   admin    delete the demonstration set
 *
 * "Admin" is this app's own PIN sign-in. There is no endpoint that deletes
 * real records in bulk, and there must not be one.
 */
$router->post('/api/scorecards', [ScorecardApiController::class, 'save'], 'es.api.scorecards');
$router->get('/api/records', [RecordsApiController::class, 'index'], 'es.api.records');
$router->put('/api/admin/synthetic', [RecordsApiController::class, 'replaceSynthetic'], 'es.api.synthetic.replace');
$router->delete('/api/admin/synthetic', [RecordsApiController::class, 'deleteSynthetic'], 'es.api.synthetic.delete');

/*
 * The v32 page's own Requests and Settings views (served-page patches, see
 * Services/ScorecardPage). Admin only, same gate as B2–B4. Not part of the
 * directive's four: they serve the admin, never a respondent.
 */
$router->post('/api/admin/requests/search', [RequestsApiController::class, 'search'], 'es.api.requests.search');
$router->get('/api/admin/requests/{id}', [RequestsApiController::class, 'show'], 'es.api.requests.show');
$router->get('/api/admin/requests/{id}/export', [RequestsApiController::class, 'export'], 'es.api.requests.export');
$router->post('/api/admin/requests/{id}/identity', [RequestsApiController::class, 'correctIdentity'], 'es.api.requests.identity');
$router->post('/api/admin/requests/{id}/record', [RequestsApiController::class, 'correctRecord'], 'es.api.requests.record');
$router->post('/api/admin/requests/{id}/restrict', [RequestsApiController::class, 'restrict'], 'es.api.requests.restrict');
$router->post('/api/admin/requests/{id}/delete', [RequestsApiController::class, 'destroy'], 'es.api.requests.delete');
$router->get('/api/admin/settings', [SettingsApiController::class, 'show'], 'es.api.settings');
$router->post('/api/admin/settings/password', [SettingsApiController::class, 'updatePassword'], 'es.api.settings.password');
