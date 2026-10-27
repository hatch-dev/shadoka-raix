<?php

declare(strict_types=1);

use Apps\AiExecutiveReadinessScorecard\Services\Export\Exports;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * The three downloads, in the reference's shapes: CSV columns and cell rules,
 * JSON written two-space like JSON.stringify(…, null, 2), and the codebook.
 */
return static function (Assert $t): void {
    $app     = AppRegistry::get('ai-executive-readiness-scorecard');
    $exports = new Exports($app);

    /* ---------- CSV cells ---------- */

    $t->same('cells are quoted', '"Dana"', Exports::cell('Dana'));
    $t->same('quotes are doubled', '"say ""hi"""', Exports::cell('say "hi"'));
    $t->same('a formula is neutralised', "\"'=SUM(A1)\"", Exports::cell('=SUM(A1)'));
    $t->same('a leading plus too', "\"'+1 555\"", Exports::cell('+1 555'));
    $t->same('null is empty', '""', Exports::cell(null));
    $t->same('a whole float prints as JavaScript does', '"3"', Exports::cell(3.0));
    $t->same('a fraction prints in full', '"3.4166666666666665"', Exports::cell(41 / 12));

    /* ---------- CSV columns ---------- */

    $header = str_getcsv(explode("\n", $exports->csv([]))[0]);
    $t->same('73 columns', 73, count($header));
    $t->same('context first', ['id', 'timestamp', 'year', 'synthetic'], array_slice($header, 0, 4));
    $t->same('then each scope: summary, dimension, items', ['self_mean', 'self_level', 'self_floor', 'self_floorCode', 'self_JDG', 'self_JDG1'], array_slice($header, 16, 6));
    $t->same('leadership after self', 'leadership_mean', $header[36]);
    $t->same('differences last', ['difference_overall', 'difference_JDG', 'difference_JDG1'], array_slice($header, 56, 3));
    $t->same('final column', 'difference_CHG3', end($header));

    /* ---------- JSON ---------- */

    $json = $exports->json([], false);
    $t->true('two-space indentation', str_contains($json, "\n  \"instrument\": "));
    $t->true('no four-space indentation', !str_contains($json, "\n    \"instrument\""));
    $decoded = json_decode($json, true);
    $t->same('json header', ['instrument', 'version', 'exported', 'includes_synthetic', 'n', 'scoring', 'records'], array_keys($decoded));
    $t->true('exported is an ISO timestamp', (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.000Z$/', $decoded['exported']));

    /* ---------- codebook ---------- */

    $codebook = json_decode($exports->codebook(), true);
    $t->same('codebook counts', [12, 24], [$codebook['questions'], $codebook['ratings_per_questionnaire']]);
    $t->same('codebook scopes', ['self' => 'You', 'leadership' => 'Your organization’s leaders'], $codebook['scopes']);
    $t->same('codebook item id', 'FLU2', $codebook['dimensions'][1]['items'][1]['id']);
    $t->true('codebook item has both anchors', isset($codebook['dimensions'][0]['items'][0]['anchors']['leadership']['a5']));
    $t->same('first level runs from 1', ['lower_inclusive' => 1, 'upper' => 1.8, 'upper_inclusive' => false], array_intersect_key($codebook['levels'][0], array_flip(['lower_inclusive', 'upper', 'upper_inclusive'])));
    $t->same('last level is closed at 5', ['lower_inclusive' => 4.2, 'upper' => 5, 'upper_inclusive' => true], array_intersect_key($codebook['levels'][4], array_flip(['lower_inclusive', 'upper', 'upper_inclusive'])));
    $t->same('five caveats', 5, count($codebook['caveats']));
};
