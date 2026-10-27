<?php

declare(strict_types=1);

/**
 * AI Executive Readiness Scorecard — public pages.
 *
 * Mounted under /{slug}, so '/' below is served at '/ai-executive-readiness-scorecard'.
 * The app never writes its own slug into a path, so moving it to the site root
 * or to a subdomain later is a config change rather than a find-and-replace.
 *
 * Since directive v4 (1 Oct 2026) the public app is ONE page: Shadoka's
 * AI_Executive_Readiness_Scorecard_v4.html, served by ScorecardPageController
 * (line 1090 pointed at this app's /api, plus the patches listed in
 * Services/ScorecardPage). The previous edition's URLs come back to it.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\AiExecutiveReadinessScorecard\Controllers\Public\ScorecardPageController;

$router->get('/', [ScorecardPageController::class, 'show'], 'exr.home');
$router->get('/assess', [ScorecardPageController::class, 'toPage'], 'exr.assess');
$router->get('/results/{token}', [ScorecardPageController::class, 'toPage'], 'exr.results');

/* Previous edition, for reference (PageController is kept on disk, unrouted):
   $router->get('/', [PageController::class, 'hero'], 'exr.home');
   $router->get('/assess', [PageController::class, 'assess'], 'exr.assess');
   $router->get('/results/{token}', [PageController::class, 'results'], 'exr.results'); */
