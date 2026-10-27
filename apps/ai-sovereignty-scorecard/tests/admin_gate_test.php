<?php

declare(strict_types=1);

use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * The Admin gate's one rule: the PIN is never in anything the browser is served.
 *
 * The reference build held it as `const ADMIN_PIN = '…'` in the same script that
 * compared it, so it was readable by anyone who opened the page source — and
 * everything behind it, on this instrument, is every respondent's marks, name,
 * email address and phone number.
 *
 * The value now lives only in the database, hashed, and is compared in
 * Platform\Core\Auth. Since only the hash exists, this test cannot search the
 * assets for the value itself; it catches the SHAPE of the mistake instead. It
 * is what stops it being copied back into a file to "make the dialog work
 * offline", which is exactly the change that would look harmless in review.
 *
 * It also checks the app's asset tree for anything else that looks like a
 * secret, because the assets directory is published verbatim into the document
 * root: whatever is in there is downloadable.
 */
return static function (Assert $t): void {
    $app = AppRegistry::get('ai-sovereignty-scorecard');

    /* Everything a browser can fetch: the published asset tree's sources. */
    $files = [];

    $directory = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($app->assetsPath(), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($directory as $file) {
        if (!$file->isFile()) {
            continue;
        }

        // The two vendored libraries are third-party minified bundles; scanning
        // 570KB of Chart.js for the word "pin" finds only false positives.
        if (str_contains(str_replace('\\', '/', $file->getPathname()), '/vendor/')) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    $t->true('there are asset files to check', $files !== []);

    $leaked = [];

    foreach ($files as $path) {
        $contents = (string) file_get_contents($path);
        $name     = basename($path);

        /*
         * The shape of the reference's own mistake, whatever the value. A
         * file that declares something called ADMIN_PIN is the change this test
         * exists to catch, even if the value beside it is a placeholder today.
         */
        if (preg_match('/\bADMIN_PIN\b/', $contents)) {
            $leaked[] = $name . ' declares ADMIN_PIN';
        }
    }

    $t->same(
        'no published asset declares a PIN',
        [],
        $leaked
    );

    /*
     * The gate's own script must talk to the server rather than decide for
     * itself. Checking that it calls the endpoint is a weaker statement than the
     * scan above, but it is the one that says WHY the scan passes.
     */
    $gate = (string) file_get_contents($app->assetsPath() . '/js/app.js');

    $t->true('the dialog asks the server for a verdict', str_contains($gate, 'adminPin'));
    $t->true('and nothing in it compares a PIN itself', !preg_match('/===\s*[\'"][A-Z0-9]{6,}[\'"]/', $gate));

    /*
     * The reference's dialog told the reader its gate was "demo-grade; do not
     * treat it as a security control". It was — a constant in client JavaScript.
     * Here it is the only thing in front of real respondent data, and repeating
     * that line would be inviting people to disregard it.
     */
    $t->true('and it does not invite the reader to disregard the gate', !str_contains($gate, 'demo-grade'));

    /*
     * The public layout must not leak it either: it is rendered server-side, so
     * a value read from the settings table there ends up in the page source.
     */
    $layout = (string) file_get_contents($app->path('Views/layouts/public.php'));

    $t->true('the public layout does not read the PIN setting', !str_contains($layout, 'pinSetting'));

    $t->true(
        'and the Admin link is a real link, so the gate degrades without scripting',
        str_contains($layout, 'href=') && str_contains($layout, 'data-open-admin')
    );
};
