<?php

declare(strict_types=1);

/**
 * AI Sovereignty Scorecard — public pages.
 *
 * Mounted under /{slug}, so '/' below is served at '/ai-sovereignty-scorecard'.
 * The app never writes its own slug into a path, so moving it to the site root
 * or to a subdomain later is a config change rather than a find-and-replace.
 *
 * Since 1 Oct 2026 the public app is Shadoka's v6 page (directive
 * Directive_Privacy_DEV_AI_Sovereignty_Scorecard_v6_28Sep2026, Part A), served
 * by ScorecardPageController. The previous edition's addresses come back to
 * it. Its PageController and views are left on disk, unrouted; to go back,
 * restore the three PageController routes below.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\AiSovereigntyScorecard\Controllers\Public\ScorecardPageController;

$router->get('/', [ScorecardPageController::class, 'show'], 'sov.home');
$router->get('/assess', [ScorecardPageController::class, 'toPage'], 'sov.assess');
$router->get('/results/{token}', [ScorecardPageController::class, 'toPage'], 'sov.results');

/* Previous edition, for reference:
   $router->get('/', [PageController::class, 'hero'], 'sov.home');
   $router->get('/assess', [PageController::class, 'assess'], 'sov.assess');
   $router->get('/results/{token}', [PageController::class, 'results'], 'sov.results'); */
