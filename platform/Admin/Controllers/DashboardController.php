<?php

declare(strict_types=1);

namespace Platform\Admin\Controllers;

use Platform\Admin\AdminController;
use Platform\Core\AppRegistry;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\Response;

/**
 * The admin landing page.
 *
 * Platform-level: it counts what exists across every enabled app rather than
 * knowing any one of them, so a second app appears here the day it is enabled.
 */
final class DashboardController extends AdminController
{
    /** GET /admin */
    public function index(): Response
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return $this->view()->response('dashboard', [
            'title'         => 'Admin · RAI-X',
            'apps'          => $this->appSummaries(),
            'organisations' => (int) Database::scalar('SELECT COUNT(*) FROM `organisations` WHERE `is_synthetic` = 0'),
            'recent'        => $this->recentActivity(),
        ]);
    }

    /**
     * One row per app the acting identity may see.
     *
     * @return list<array<string, mixed>>
     */
    private function appSummaries(): array
    {
        $summaries = [];

        foreach (AppRegistry::all() as $app) {
            // Which capability gates seeing this app at all comes from its own
            // manifest — the platform must not name an app or its capabilities.
            // An app with no gated sections is visible to any identity,
            // which is the right default for one that declared no restriction.
            $viewCapability = null;

            foreach ($app->adminNav as $entry) {
                if (($entry['capability'] ?? null) !== null) {
                    $viewCapability = $entry['capability'];
                    break;
                }
            }

            if ($viewCapability !== null && !Auth::can($viewCapability)) {
                continue;
            }

            /*
             * An app that stores whole records as its page sent them (a
             * `records` table: synthetic, restricted, completed_at) is counted
             * from that. It has no drafts: a record exists once it is saved.
             */
            $records = $app->table('records');

            if (Database::hasTable($records)) {
                $summaries[] = [
                    'slug'      => $app->slug,
                    'name'      => $app->name,
                    'installed' => true,
                    'real'      => (int) Database::scalar("SELECT COUNT(*) FROM `{$records}` WHERE `synthetic` = 0"),
                    'synthetic' => (int) Database::scalar("SELECT COUNT(*) FROM `{$records}` WHERE `synthetic` = 1"),
                    'draft'     => 0,
                    'latest'    => Database::scalar("SELECT MAX(`completed_at`) FROM `{$records}` WHERE `synthetic` = 0"),
                ];
                continue;
            }

            $table = $app->table('submissions');

            // An app that has not been migrated yet must not break the page.
            if (!Database::hasTable($table)) {
                $summaries[] = [
                    'slug'      => $app->slug,
                    'name'      => $app->name,
                    'installed' => false,
                ];
                continue;
            }

            $summaries[] = [
                'slug'      => $app->slug,
                'name'      => $app->name,
                'installed' => true,
                'real'      => (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `is_synthetic` = 0 AND `status` = 'complete'"),
                'synthetic' => (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `is_synthetic` = 1"),
                'draft'     => (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'in_progress'"),
                'latest'    => Database::scalar("SELECT MAX(`submitted_at`) FROM `{$table}` WHERE `is_synthetic` = 0"),
            ];
        }

        return $summaries;
    }

    /** @return list<array<string, mixed>> */
    private function recentActivity(): array
    {
        return Database::select(
            'SELECT `actor_label`, `app`, `action`, `subject_type`, `created_at`
               FROM `audit_log`
           ORDER BY `id` DESC
              LIMIT 12'
        );
    }
}
