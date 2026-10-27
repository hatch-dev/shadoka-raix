<?php

declare(strict_types=1);

use Apps\HumanEdgeScorecard\Services\Instrument;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;

/**
 * The instrument in the database is the page's, word for word.
 *
 * seeds/data/instrument-v1.json was evaluated out of the supplied page, and the
 * seeder loaded it. This checks both steps: the published rows equal the JSON,
 * and the JSON equals the page's own `const DIMS` (read here a second, separate
 * way — straight out of the page file).
 *
 * Reads only.
 */
return static function (Assert $t): void {
    $app        = AppRegistry::get('human-edge-scorecard');
    Instrument::flush();
    $instrument = new Instrument($app);
    $json       = json_decode((string) file_get_contents($app->path('database/seeds/data/instrument-v1.json')), true, 512, JSON_THROW_ON_ERROR);

    $t->same('published version is 1.0', '1.0', (string) $instrument->row()['version']);
    $t->same('six skills', 6, count($instrument->dims()));
    $t->same('eighteen questions', 18, array_sum(array_map(static fn (array $d): int => count($d['qs']), $instrument->dims())));
    $t->same('skill codes in page order', ['JDG', 'ADP', 'EMP', 'CON', 'IMG', 'AUT'], array_column($instrument->dims(), 'code'));
    $t->same('five rungs, Rare to Habitual', ['Rare', 'Occasional', 'Developing', 'Consistent', 'Habitual'], array_column($instrument->rungs(), 'name'));
    $t->same('band edges are the page\'s BAND_EDGES', [1.8, 2.6, 3.4, 4.2], $instrument->bandEdges());

    $mismatch = [];
    foreach ($json['dimensions'] as $i => $d) {
        $db = $instrument->dims()[$i];
        foreach (['code' => 'code', 'name' => 'name', 'sub' => 'subtitle', 'line' => 'summary_line', 'desc' => 'description', 'ask' => 'pressing_question', 'fail' => 'failure_line'] as $mine => $theirs) {
            if ($db[$mine] !== $d[$theirs]) {
                $mismatch[] = $d['code'] . '.' . $mine;
            }
        }
        foreach ($d['items'] as $j => $q) {
            foreach (['t' => 'label', 'q' => 'question', 'l1' => 'anchor_low_label', 'a1' => 'anchor_low_text', 'l5' => 'anchor_high_label', 'a5' => 'anchor_high_text', 'next' => 'next_step'] as $mine => $theirs) {
                if (($db['qs'][$j][$mine] ?? null) !== $q[$theirs]) {
                    $mismatch[] = $d['code'] . '_' . $j . '.' . $mine;
                }
            }
        }
    }
    $t->same('every database field equals the extracted JSON', [], $mismatch);

    // The page itself, read independently of the extractor.
    $html  = (string) file_get_contents($app->path('page/Human_Edge_Scorecard.html'));
    $start = strpos($html, 'const DIMS = [');
    $end   = strpos($html, "\n];", (int) $start);
    $dims  = json_decode(substr($html, $start + 13, $end - $start - 11), true);

    $t->true('the page\'s DIMS parse', is_array($dims) && count($dims) === 6);

    $pageMismatch = [];
    foreach ($dims as $i => $d) {
        foreach (['code', 'name', 'sub', 'line', 'desc', 'ask', 'fail'] as $k) {
            if ($instrument->dims()[$i][$k] !== $d[$k]) {
                $pageMismatch[] = $d['code'] . '.' . $k;
            }
        }
        foreach ($d['qs'] as $j => $q) {
            foreach (['t', 'q', 'l1', 'a1', 'l5', 'a5', 'next'] as $k) {
                if ($instrument->dims()[$i]['qs'][$j][$k] !== $q[$k]) {
                    $pageMismatch[] = $d['code'] . '_' . $j . '.' . $k;
                }
            }
        }
    }
    $t->same('every database field equals the page\'s own DIMS', [], $pageMismatch);

    $t->same('sectors from the page', $json['reference']['sectors'], $instrument->options('sectors'));
    $t->same('sizes from the page', $json['reference']['organisation_sizes'], $instrument->options('organisation_sizes'));
    $t->same('roles from the page', $json['reference']['roles'], $instrument->options('roles'));
    $t->same('tenures from the page', $json['reference']['tenures'], $instrument->options('tenures'));
};
