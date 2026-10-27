<?php

declare(strict_types=1);

/**
 * Enterprise Scorecard — public pages.
 *
 * Mounted under /{slug}, so '/' below is served at '/enterprise-ai-scorecard'.
 * The app never writes its own slug into a path, so moving it to the site root
 * or to a subdomain later is a config change rather than a find-and-replace.
 *
 * Since 30 Sep 2026 the public app is Shadoka's v32 page (directive
 * Directive_Privacy_DEV_Enterprise_Scorecard_v32_28Sep2026, Part A), served
 * by ScorecardPageController. The previous edition's addresses come back to
 * it. Its PageController and views are left on disk, unrouted; to go back,
 * restore the three PageController routes below.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\EnterpriseAiScorecard\Controllers\Public\ScorecardPageController;

$router->get('/', [ScorecardPageController::class, 'show'], 'es.home');
$router->get('/assess', [ScorecardPageController::class, 'toPage'], 'es.assess');
$router->get('/results/{token}', [ScorecardPageController::class, 'toPage'], 'es.results');

/* Previous edition, for reference:
   $router->get('/', [PageController::class, 'hero'], 'es.home');
   $router->get('/assess', [PageController::class, 'assess'], 'es.assess');
   $router->get('/results/{token}', [PageController::class, 'results'], 'es.results'); */
