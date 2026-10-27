<?php

declare(strict_types=1);

/**
 * AI Sovereignty Scorecard — JSON API.
 *
 * Mounted by the platform under /{slug}, so '/api/instrument' below is served at
 * '/ai-sovereignty-scorecard/api/instrument'.
 *
 * The contract with the browser: it sends MARKS. Dimension scores, the overall
 * average, the floor and the level are all derived on the server. The reference
 * build let the client compute and store its own result.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\AiSovereigntyScorecard\Controllers\Admin\RecordsApiController;
use Apps\AiSovereigntyScorecard\Controllers\Admin\RequestsApiController;
use Apps\AiSovereigntyScorecard\Controllers\Admin\SettingsApiController;
use Apps\AiSovereigntyScorecard\Controllers\Public\InstrumentController;
use Apps\AiSovereigntyScorecard\Controllers\Public\ScorecardApiController;
use Apps\AiSovereigntyScorecard\Controllers\Public\SubmissionController;

/* The live instrument — replaces the hard-coded DIMS/RUNGS in the reference. */
$router->get('/api/instrument', [InstrumentController::class, 'show'], 'sov.api.instrument');

/* A scorecard's life. The token is the only credential; there are no accounts. */
$router->post('/api/submissions', [SubmissionController::class, 'start'], 'sov.api.start');
$router->get('/api/submissions/{token}', [SubmissionController::class, 'show'], 'sov.api.show');
$router->patch('/api/submissions/{token}', [SubmissionController::class, 'save'], 'sov.api.save');
/* "Start over": an unfinished draft only; a finished scorecard is refused. */
$router->delete('/api/submissions/{token}', [SubmissionController::class, 'discard'], 'sov.api.discard');
$router->post('/api/submissions/{token}/submit', [SubmissionController::class, 'submit'], 'sov.api.submit');
$router->post('/api/submissions/{token}/confirm', [SubmissionController::class, 'confirm'], 'sov.api.confirm');

/*
 * Directive v6 (Directive_Privacy_DEV_AI_Sovereignty_Scorecard_v6_28Sep2026),
 * Part B — the four endpoints the v6 page calls at API_BASE, over sov_records.
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
$router->post('/api/scorecards', [ScorecardApiController::class, 'save'], 'sov.api.scorecards');
$router->get('/api/records', [RecordsApiController::class, 'index'], 'sov.api.records');
$router->put('/api/admin/synthetic', [RecordsApiController::class, 'replaceSynthetic'], 'sov.api.synthetic.replace');
$router->delete('/api/admin/synthetic', [RecordsApiController::class, 'deleteSynthetic'], 'sov.api.synthetic.delete');

/*
 * The v6 page's own Requests and Settings views (served-page patches, see
 * Services/ScorecardPage). Admin only, same gate as B2–B4. Not part of the
 * directive's four: they serve the admin, never a respondent.
 */
$router->post('/api/admin/requests/search', [RequestsApiController::class, 'search'], 'sov.api.requests.search');
$router->get('/api/admin/requests/{id}', [RequestsApiController::class, 'show'], 'sov.api.requests.show');
$router->get('/api/admin/requests/{id}/export', [RequestsApiController::class, 'export'], 'sov.api.requests.export');
$router->post('/api/admin/requests/{id}/identity', [RequestsApiController::class, 'correctIdentity'], 'sov.api.requests.identity');
$router->post('/api/admin/requests/{id}/record', [RequestsApiController::class, 'correctRecord'], 'sov.api.requests.record');
$router->post('/api/admin/requests/{id}/restrict', [RequestsApiController::class, 'restrict'], 'sov.api.requests.restrict');
$router->post('/api/admin/requests/{id}/delete', [RequestsApiController::class, 'destroy'], 'sov.api.requests.delete');
$router->get('/api/admin/settings', [SettingsApiController::class, 'show'], 'sov.api.settings');
$router->post('/api/admin/settings/password', [SettingsApiController::class, 'updatePassword'], 'sov.api.settings.password');
