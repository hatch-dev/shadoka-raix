<?php

declare(strict_types=1);

/**
 * Director Scorecard (Public) — the page.
 *
 * Since the v13 directive (29 Sep 2026) the whole public app is Shadoka's
 * supplied single file, served by ScorecardPageController. The previous
 * edition's URLs come back to it rather than 404.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\RaixDirectorScorecardPublic\Controllers\Public\ScorecardPageController;

$router->get('/', [ScorecardPageController::class, 'show'], 'dsp.home');
$router->get('/assess', [ScorecardPageController::class, 'toPage'], 'dsp.assess');
$router->get('/results/{token}', [ScorecardPageController::class, 'toPage'], 'dsp.results');
