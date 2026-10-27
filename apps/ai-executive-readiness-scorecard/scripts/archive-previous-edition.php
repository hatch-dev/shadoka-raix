<?php

declare(strict_types=1);

/**
 * C5 of directive v4, at deployment: keep a dated copy of the edition being
 * replaced, and of the v4 file as supplied, in this server's notice archive.
 *
 *   php apps/ai-executive-readiness-scorecard/scripts/archive-previous-edition.php --base=https://shadoka.com/RAIX/dev/ai-executive-readiness-scorecard
 *
 * Run it BEFORE the v4 routes are deployed, while --base still serves the
 * previous edition: it saves that edition's overview page, questionnaire page
 * and /api/instrument payload (its consent text is the notice that edition
 * showed). It refuses to save a page that is already the v4 one. The supplied
 * v4 file is copied too, if it is on disk (page/AI_Executive_Readiness_Scorecard_v4.html).
 *
 * Nothing is overwritten; re-running adds only what is missing. CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../platform/bootstrap.php';

use Apps\AiExecutiveReadinessScorecard\Services\NoticeArchive;
use Apps\AiExecutiveReadinessScorecard\Services\ScorecardPage;
use Platform\Core\AppRegistry;

$base = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = rtrim(substr($arg, 7), '/');
    }
}

if ($base === null || !preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "Give --base=https://…/ai-executive-readiness-scorecard (the address that serves the edition being replaced).\n");
    exit(2);
}

$app   = AppRegistry::get('ai-executive-readiness-scorecard');
$dir   = NoticeArchive::dir();
$date  = gmdate('Y-m-d');
$index = NoticeArchive::index();
$have  = array_column($index, null, 'file');

if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$keep = static function (string $name, string $body, string $kind, string $notice) use (&$index, &$have, $dir): void {
    foreach ($have as $file => $entry) {
        if (str_ends_with($file, substr($name, 10)) || ($entry['md5'] ?? '') === md5($body)) {
            echo "already kept: {$file}\n";

            return;
        }
    }

    $handle = @fopen($dir . '/' . $name, 'xb');
    if ($handle === false) {
        echo "exists, left as is: {$name}\n";

        return;
    }
    fwrite($handle, $body);
    fclose($handle);

    $index[] = ['file' => $name, 'kind' => $kind, 'notice_version' => $notice, 'md5' => md5($body), 'bytes' => strlen($body)];
    $have[$name] = end($index);
    echo "kept: {$name} (" . strlen($body) . " bytes)\n";
};

$fetch = static function (string $url): string {
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 0, 'ignore_errors' => true]]));
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;

    if ($body === false || $status !== 200) {
        fwrite(STDERR, "Could not fetch {$url} (HTTP {$status}).\n");
        exit(1);
    }

    return $body;
};

/* The supplied v4 file, as received. */
$supplied = $app->path(ScorecardPage::FILE);
if (is_file($supplied) && md5_file($supplied) === ScorecardPage::MD5) {
    $keep($date . '_' . ScorecardPage::NOTICE . '_AI_Executive_Readiness_Scorecard_v4_supplied.html', (string) file_get_contents($supplied),
        'supplied file (as received from SHADOKA, before line ' . ScorecardPage::API_LINE . ' is set)', ScorecardPage::NOTICE);
}

/* The edition being replaced. */
$overview = $fetch($base . '/');

if (str_contains($overview, "const NOTICE_VERSION='")) {
    fwrite(STDERR, "{$base}/ already serves a versioned (v4) page: the previous edition is gone from there, nothing fetched.\n");
    NoticeArchive::writeIndex($index);
    exit(1);
}

$keep($date . '_unversioned_previous-edition_overview.html', $overview,
    'previous edition page, server-rendered (in use until ' . $date . ')', 'unversioned (pre-EXR-PN)');
$keep($date . '_unversioned_previous-edition_assess.html', $fetch($base . '/assess'),
    'previous edition questionnaire page, server-rendered (in use until ' . $date . ')', 'unversioned (pre-EXR-PN)');
$keep($date . '_unversioned_previous-edition_instrument-payload.json', $fetch($base . '/api/instrument'),
    'previous edition /api/instrument response; its consent block is the notice the questionnaire showed', 'unversioned (pre-EXR-PN)');

NoticeArchive::writeIndex($index);
echo "index: {$dir}/index.json\n";
