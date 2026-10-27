<?php

declare(strict_types=1);

/**
 * Human Edge Scorecard — public pages.
 *
 * Mounted under /{slug}, so '/' below is served at '/human-edge-scorecard'.
 * The app never writes its own slug into a path, so moving it to the site root
 * or to a subdomain later is a config change rather than a find-and-replace.
 *
 * The public app is ONE page: Shadoka's Human_Edge_Scorecard.html, served by
 * ScorecardPageController (API_BASE pointed at this app's /api, plus the
 * patches listed in Services/ScorecardPage).
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\HumanEdgeScorecard\Controllers\Public\ScorecardPageController;

$router->get('/', [ScorecardPageController::class, 'show'], 'he.home');
