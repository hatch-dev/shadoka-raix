<?php

declare(strict_types=1);

/**
 * RAIX — favicon change, applied in place on the live server.
 *
 *   php apply-favicon.php /var/www/shadoka.com/public_html/RAIX            report only, change nothing
 *   php apply-favicon.php /var/www/shadoka.com/public_html/RAIX --apply    make the changes
 *
 * It EDITS the live files rather than replacing them, because live
 * helpers.php and the scorecards' ScorecardPage.php carry code this copy does
 * not have (the link-preview code of 5 Oct). It:
 *
 *   1. adds favicon_data_link() to platform/Support/helpers.php, just above
 *      social_meta(), if it is not there yet;
 *   2. in each scorecard's Services/ScorecardPage.php, appends
 *      `. favicon_data_link()` to the share-card line in withShareCard().
 *
 * Safe to re-run: anything already changed is reported and left alone. Before
 * changing a file it writes a copy beside it named *.bak-favicon. If a line it
 * looks for is not found, that file is left untouched and named in the report.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root  = rtrim((string) ($argv[1] ?? ''), '/\\');
$apply = in_array('--apply', $argv, true);

if ($root === '' || !is_file($root . '/platform/Support/helpers.php')) {
    fwrite(STDERR, "Give the RAIX folder, e.g.: php apply-favicon.php /var/www/shadoka.com/public_html/RAIX [--apply]\n");
    exit(1);
}

$problems = 0;

/*
 * Returns true when the file was written (or nothing was asked for). A file
 * this user cannot write is reported and left exactly as it was: no backup is
 * attempted unless the file itself is writable.
 */
$write = static function (string $file, string $contents) use ($apply, &$problems): bool {
    if (!$apply) {
        return true;
    }
    if (!is_writable($file) || !is_writable(dirname($file))) {
        echo "PROBLEM  no permission to write $file — run this with sudo (see README.txt)\n";
        $problems++;
        return false;
    }
    if (!is_file($file . '.bak-favicon') && !@copy($file, $file . '.bak-favicon')) {
        echo "PROBLEM  could not back up $file — nothing changed in it\n";
        $problems++;
        return false;
    }
    if (@file_put_contents($file, $contents) === false) {
        echo "PROBLEM  could not write $file — nothing changed in it\n";
        $problems++;
        return false;
    }
    return true;
};

/* 1. The helper. */

$helpers = $root . '/platform/Support/helpers.php';
$source  = (string) file_get_contents($helpers);

$function = <<<'PHP'
if (!function_exists('favicon_data_link')) {
    /**
     * The site icon as one <link>, its SVG inlined as a data: URI.
     *
     * For the supplied scorecard pages: they declare no icon, and their CSP
     * allows images from data: only, so a link to the file could be refused.
     * The icon is platform/assets/img/favicon.svg — Shadoka's, the one
     * shadoka.com serves (the earlier RAI-X mark is kept as favicon-raix.svg).
     * Empty if the file is missing, so a page is never broken by it.
     */
    function favicon_data_link(): string
    {
        $svg = @file_get_contents(base_path('platform/assets/img/favicon.svg'));

        return $svg === false || $svg === ''
            ? ''
            : '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,' . base64_encode($svg) . '">';
    }
}


PHP;

$anchor = "if (!function_exists('social_meta')) {";

if (str_contains($source, "function favicon_data_link(")) {
    echo "ok       platform/Support/helpers.php already has favicon_data_link()\n";
} elseif (substr_count($source, $anchor) !== 1) {
    echo "PROBLEM  platform/Support/helpers.php: social_meta() not found — add the function by hand\n";
    $problems++;
} else {
    if ($write($helpers, str_replace($anchor, $function . $anchor, $source))) {
        echo ($apply ? 'changed  ' : 'would    ') . "platform/Support/helpers.php: add favicon_data_link()\n";
    }
}

/* 2. Each scorecard page. */

$line = "\$tags  = str_replace(\"\\n\", '', social_meta(\$this->app->slug, \$title, self::SHARE_DESCRIPTION));";
$with = "\$tags  = str_replace(\"\\n\", '', social_meta(\$this->app->slug, \$title, self::SHARE_DESCRIPTION)) . favicon_data_link();";

$apps = [
    'ai-executive-readiness-scorecard',
    'ai-sovereignty-scorecard',
    'enterprise-ai-scorecard',
    'raix-director-scorecard-public',
    'human-edge-scorecard',
];

foreach ($apps as $app) {
    $file = $root . '/apps/' . $app . '/Services/ScorecardPage.php';
    $name = 'apps/' . $app . '/Services/ScorecardPage.php';

    if (!is_file($file)) {
        echo "skip     $name — not on this server (upload the app first)\n";
        continue;
    }

    $page = (string) file_get_contents($file);

    if (str_contains($page, 'favicon_data_link()')) {
        echo "ok       $name already adds the icon\n";
        continue;
    }

    if (substr_count($page, $line) !== 1) {
        echo "PROBLEM  $name: share-card line not found — add `. favicon_data_link()` to the \$tags line by hand\n";
        $problems++;
        continue;
    }

    if ($write($file, str_replace($line, $with, $page))) {
        echo ($apply ? 'changed  ' : 'would    ') . "$name: add the icon to the share-card line\n";
    }
}

/* Check the result parses. */

if ($apply) {
    foreach (array_merge([$helpers], array_map(static fn ($a) => $root . '/apps/' . $a . '/Services/ScorecardPage.php', $apps)) as $file) {
        if (!is_file($file)) {
            continue;
        }
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            echo "PROBLEM  syntax error in $file — restore it from $file.bak-favicon\n";
            $problems++;
        }
    }
}

echo $apply ? "\ndone" : "\nreport only — run again with --apply to make the changes";
echo $problems ? " ($problems problem(s) above)\n" : "\n";
exit($problems ? 1 : 0);
