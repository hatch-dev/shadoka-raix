<?php
/**
 * The app registry.
 *
 * One line per app. The slug MUST match the folder under apps/ and is FROZEN
 * once any row in the database references it — renaming a slug orphans data,
 * published assets and admin capabilities at the same time.
 *
 * Order matters only for migrations, which run in the order listed here.
 */
declare(strict_types=1);

return [
    'enabled' => [
        'raix-director-scorecard',
        'enterprise-ai-scorecard',
        'raix-director-scorecard-public',
        'ai-sovereignty-scorecard',
        'ai-executive-readiness-scorecard',
        'human-edge-scorecard',
    ],

    /*
     * Slug of the app served at the site root. null = show the platform index.
     *
     * Only ONE app can hold the root. The Director Scorecard keeps it because
     * its links were published that way; the Enterprise Scorecard answers at
     * /enterprise-ai-scorecard, which is the URL every one of its own links is
     * generated from. Nothing in either app writes its own slug into a path, so
     * swapping which one holds the root is this one line.
     */
    'default' => 'raix-director-scorecard',

    /*
     * Apps served WITHOUT the /dev mount: /RAIX/{slug} and /RAIX/admin/{slug}.
     * Their old /dev addresses answer with a 301 to the new ones.
     *
     * To move an app: add its slug here. That is all — nothing to run; the
     * front controller reads this list on every request. A 301 is cached by
     * browsers, so treat a move as permanent. The default app cannot be
     * listed — it also answers unslugged paths, which live under the mount.
     */
    'unmounted' => [
        'raix-director-scorecard-public',
        'enterprise-ai-scorecard',
        'ai-sovereignty-scorecard',
        'ai-executive-readiness-scorecard',
    ],
];
