<?php

declare(strict_types=1);

/**
 * Director Scorecard — JSON API.
 *
 * Mounted by the platform under /{slug}, so '/api/instrument' below is served
 * at '/raix-director-scorecard/api/instrument'. The app never writes its own slug
 * into a path; that is what keeps the slug a configuration decision.
 *
 * The contract with the browser: it sends MARKS. Scores, floors and levels are
 * derived on the server. The legacy build let the client store its own result.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\RaixDirectorScorecard\Controllers\Public\InstrumentController;
use Apps\RaixDirectorScorecard\Controllers\Public\SubmissionController;

/* The live instrument — replaces the hard-coded DIMS/RUNGS in the legacy build. */
$router->get('/api/instrument', [InstrumentController::class, 'show'], 'ds.api.instrument');

/* A scorecard's life. The token is the only credential; there are no accounts. */
$router->post('/api/submissions', [SubmissionController::class, 'start'], 'ds.api.start');
$router->get('/api/submissions/{token}', [SubmissionController::class, 'show'], 'ds.api.show');
$router->patch('/api/submissions/{token}', [SubmissionController::class, 'save'], 'ds.api.save');
$router->post('/api/submissions/{token}/submit', [SubmissionController::class, 'submit'], 'ds.api.submit');
$router->post('/api/submissions/{token}/confirm', [SubmissionController::class, 'confirm'], 'ds.api.confirm');
$router->patch('/api/submissions/{token}/commitments', [SubmissionController::class, 'commitments'], 'ds.api.commitments');
