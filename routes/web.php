<?php

declare(strict_types=1);

/**
 * Platform-level routes.
 *
 * Loaded before any app's, so /admin and /admin/login belong to the platform
 * and cannot be shadowed by an app that happens to declare the same path.
 *
 * @var \Platform\Core\Router $router
 */

use Platform\Admin\Controllers\AuthController;
use Platform\Admin\Controllers\DashboardController;
use Platform\Admin\Controllers\SettingsController;

/* Sign-in is deliberately outside the guard — it is how you get past it. */
$router->get('/admin/login', [AuthController::class, 'showLogin'], 'admin.login');
$router->post('/admin/login', [AuthController::class, 'login'], 'admin.login.post');

/* The shared PIN behind the public site's Admin dialog. Same checks as above. */
$router->post('/admin/pin', [AuthController::class, 'pin'], 'admin.pin.post');

$router->post('/admin/logout', [AuthController::class, 'logout'], 'admin.logout');

/* Reachable while signed in but not yet past a forced password change. */
$router->get('/admin/password', [AuthController::class, 'showPassword'], 'admin.password');
$router->post('/admin/password', [AuthController::class, 'updatePassword'], 'admin.password.post');

$router->get('/admin', [DashboardController::class, 'index'], 'admin.dashboard');

/*
 * Each app's own admin password, inside that app's console. Registered under
 * every app's prefix with the app bound, so the screen and its guard always
 * know which app — and which app's administrator — they are for.
 */
foreach (\Platform\Core\AppRegistry::all() as $settingsApp) {
    $router->group('admin/' . $settingsApp->slug, static function ($router) use ($settingsApp): void {
        $router->get('/settings', [SettingsController::class, 'show'], 'admin.' . $settingsApp->slug . '.settings');
        $router->post('/settings/password', [SettingsController::class, 'updatePassword'], 'admin.' . $settingsApp->slug . '.settings.password');
    }, $settingsApp->slug);
}
