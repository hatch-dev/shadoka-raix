<?php

declare(strict_types=1);

/**
 * Director Scorecard — public pages.
 *
 * Mounted under /{slug}, and also answered at the site root because this is the
 * default app in config/apps.php. The app never writes its own slug into a
 * path, so moving it to a subdomain later is a config change.
 *
 * @var \Platform\Core\Router      $router
 * @var \Platform\Core\AppManifest $app
 */

use Apps\RaixDirectorScorecard\Controllers\Public\PageController;

$router->get('/', [PageController::class, 'hero'], 'ds.home');
$router->get('/assess', [PageController::class, 'assess'], 'ds.assess');

/* A scorecard is reached by its own token. There is no listing and no account. */
$router->get('/results/{token}', [PageController::class, 'results'], 'ds.results');
