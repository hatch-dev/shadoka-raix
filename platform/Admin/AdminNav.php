<?php

declare(strict_types=1);

namespace Platform\Admin;

use Platform\Core\AppRegistry;
use Platform\Core\Auth;

/**
 * The admin sidebar, assembled from every enabled app's manifest.
 *
 * No app is named here. An app contributes its sections by declaring them in
 * apps/{slug}/app.php, and they appear for an admin only if their role holds
 * the capability each section declares — so granting one person a section and
 * withholding another is a row edit, not a deploy.
 */
final class AdminNav
{
    private function __construct()
    {
    }

    /**
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     sections: list<array{label:string, path:string, capability:?string}>
     * }>
     */
    public static function forCurrentUser(): array
    {
        if (!Auth::check()) {
            return [];
        }

        $groups = [];

        foreach (AppRegistry::all() as $app) {
            $sections = [];

            foreach ($app->adminNav as $entry) {
                $capability = $entry['capability'] ?? null;

                if ($capability !== null && !Auth::can($capability)) {
                    continue;
                }

                $sections[] = [
                    'label'      => (string) ($entry['section'] ?? ''),
                    'path'       => self::pathFor($app->slug, (string) ($entry['section'] ?? '')),
                    'capability' => $capability,
                    // A manifest may ask for a section to open as a dialog
                    // rather than a page; the shell decides how to honour it.
                    'modal'      => (bool) ($entry['modal'] ?? false),
                    // …and whether that dialog is the wide one.
                    'wide'       => (bool) ($entry['wide'] ?? false),
                ];
            }

            // An app whose every section is denied does not appear at all,
            // rather than showing an empty heading.
            if ($sections === []) {
                continue;
            }

            $groups[] = [
                'slug'     => $app->slug,
                'name'     => $app->name,
                'sections' => $sections,
            ];
        }

        return $groups;
    }

    /**
     * Section label to URL.
     *
     * Manifests name sections in prose ("Research data"); the route lives at a
     * slug of that. Keeping the mapping here means a manifest does not have to
     * know the platform's URL shape.
     */
    private static function pathFor(string $appSlug, string $section): string
    {
        $key = strtolower(trim($section));
        $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?? '';

        return 'admin/' . $appSlug . '/' . trim($key, '-');
    }

    /** True when $path is the section currently being viewed. */
    public static function isActive(string $currentPath, string $sectionPath): bool
    {
        return rtrim($currentPath, '/') === '/' . trim($sectionPath, '/');
    }
}
