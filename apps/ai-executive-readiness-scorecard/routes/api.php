<?php

declare(strict_types=1);

/**
 * AI Executive Readiness Scorecard — JSON API.
 *
 * Mounted by the platform under /{slug}, so '/api/instrument' below is served at
 * '/ai-executive-readiness-scorecard/api/instrument'.
 *
 * The contract with the browser: it sends RATINGS — a question, a scope (self or
 * leadership) and a number. Both profiles, the levels and the differences are
 * derived on the server. The reference build let the client compute and store
 * its own result.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\RecordsApiController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\RequestsApiController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Admin\SettingsApiController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Public\InstrumentController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Public\ScorecardApiController;
use Apps\AiExecutiveReadinessScorecard\Controllers\Public\SubmissionController;

/* The live instrument — replaces the hard-coded DIMS/RUNGS in the reference. */
$router->get('/api/instrument', [InstrumentController::class, 'show'], 'exr.api.instrument');

/* A scorecard's life. The token is the only credential; there are no accounts. */
$router->post('/api/submissions', [SubmissionController::class, 'start'], 'exr.api.start');
$router->get('/api/submissions/{token}', [SubmissionController::class, 'show'], 'exr.api.show');
$router->patch('/api/submissions/{token}', [SubmissionController::class, 'save'], 'exr.api.save');
/* "Start over": an unfinished draft only; a finished scorecard is refused. */
$router->delete('/api/submissions/{token}', [SubmissionController::class, 'discard'], 'exr.api.discard');
$router->post('/api/submissions/{token}/submit', [SubmissionController::class, 'submit'], 'exr.api.submit');

/*
 * Directive v4 (Directive_Privacy_DEV_AI_Executive_Readiness_Scorecard_v4_28Sep2026),
 * Part B — the four endpoints the v4 page calls at API_BASE, over exr_records.
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
$router->post('/api/scorecards', [ScorecardApiController::class, 'save'], 'exr.api.scorecards');
$router->get('/api/records', [RecordsApiController::class, 'index'], 'exr.api.records');
$router->put('/api/admin/synthetic', [RecordsApiController::class, 'replaceSynthetic'], 'exr.api.synthetic.replace');
$router->delete('/api/admin/synthetic', [RecordsApiController::class, 'deleteSynthetic'], 'exr.api.synthetic.delete');

/*
 * The v4 page's own Requests and Settings views (served-page patches, see
 * Services/ScorecardPage). Admin only, same gate as B2–B4. Not part of the
 * directive's four: they serve the admin, never a respondent.
 */
$router->post('/api/admin/requests/search', [RequestsApiController::class, 'search'], 'exr.api.requests.search');
$router->get('/api/admin/requests/{id}', [RequestsApiController::class, 'show'], 'exr.api.requests.show');
$router->get('/api/admin/requests/{id}/export', [RequestsApiController::class, 'export'], 'exr.api.requests.export');
$router->post('/api/admin/requests/{id}/identity', [RequestsApiController::class, 'correctIdentity'], 'exr.api.requests.identity');
$router->post('/api/admin/requests/{id}/record', [RequestsApiController::class, 'correctRecord'], 'exr.api.requests.record');
$router->post('/api/admin/requests/{id}/restrict', [RequestsApiController::class, 'restrict'], 'exr.api.requests.restrict');
$router->post('/api/admin/requests/{id}/delete', [RequestsApiController::class, 'destroy'], 'exr.api.requests.delete');
$router->get('/api/admin/settings', [SettingsApiController::class, 'show'], 'exr.api.settings');
$router->post('/api/admin/settings/password', [SettingsApiController::class, 'updatePassword'], 'exr.api.settings.password');
