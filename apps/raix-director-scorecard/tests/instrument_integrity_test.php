<?php

declare(strict_types=1);

use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The instrument in MySQL must be identical to the instrument in the legacy
 * single-file build — not merely the right shape, the same words.
 *
 * This is the test that makes the migration defensible: every statement a
 * director is asked to mark, every guide panel, every floor narrative and every
 * evidence prompt is compared character for character against the JSON dumped
 * straight out of reference/RAIX_Director_Scorecard/index.html.
 */
return static function (Assert $t): void {
    $source = json_decode(
        (string) file_get_contents(__DIR__ . '/../database/seeds/data/instrument-v1.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $instrument = Database::selectOne(
        'SELECT * FROM `ds_instruments` WHERE `version` = ?',
        [$source['version']]
    );

    $t->true('instrument v1.0 exists in the database', $instrument !== null);

    if ($instrument === null) {
        return;
    }

    $t->same('instrument name', $source['name'], $instrument['name']);
    $t->same('scoring rule is the floor rule', 'floor', $instrument['scoring_rule']);
    $t->same('instrument is published', 'published', $instrument['status']);

    /* ---- rungs and their band cut points ---- */

    $rungs = Database::select(
        'SELECT * FROM `ds_rungs` WHERE `instrument_id` = ? ORDER BY `value`',
        [$instrument['id']]
    );

    $t->count('four rungs', 4, $rungs);

    foreach ($source['rungs'] as $i => $expected) {
        $actual = $rungs[$i] ?? null;
        if ($actual === null) {
            $t->true("rung {$expected['value']} present", false);
            continue;
        }
        $t->same("rung {$expected['value']} name", $expected['name'], $actual['name']);
        $t->same("rung {$expected['value']} description", $expected['description'], $actual['description']);
        $t->same("rung {$expected['value']} colour", $expected['colour_hex'], $actual['colour_hex']);
    }

    // The cut points that turn a competency mean into a level.
    $bands = [];
    foreach ($rungs as $rung) {
        $bands[(int) $rung['value']] = [
            $rung['band_min'] === null ? null : (float) $rung['band_min'],
            $rung['band_max'] === null ? null : (float) $rung['band_max'],
        ];
    }

    $t->same('band 1 (Unaware)',    [null, 1.75], $bands[1] ?? null);
    $t->same('band 2 (Aware)',      [1.75, 2.75], $bands[2] ?? null);
    $t->same('band 3 (Practiced)',  [2.75, 3.75], $bands[3] ?? null);
    $t->same('band 4 (Systematic)', [3.75, null], $bands[4] ?? null);

    /* ---- competencies, items, guides, narratives, prompts ---- */

    $competencies = Database::select(
        'SELECT * FROM `ds_competencies` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrument['id']]
    );

    $t->count('seven competencies', 7, $competencies);
    $t->same(
        'competency codes and order',
        array_column($source['competencies'], 'code'),
        array_column($competencies, 'code')
    );

    $totalItems   = 0;
    $totalPrompts = 0;

    foreach ($source['competencies'] as $i => $expected) {
        $actual = $competencies[$i] ?? null;
        $code   = $expected['code'];

        if ($actual === null) {
            $t->true("{$code} present", false);
            continue;
        }

        $t->same("{$code} name", $expected['name'], $actual['name']);
        $t->same("{$code} description", $expected['description'], $actual['description']);
        $t->same("{$code} board question", $expected['board_question'], $actual['board_question']);
        $t->same("{$code} failure line", $expected['failure_line'], $actual['failure_line']);

        foreach (['what', 'why', 'how', 'example'] as $panel) {
            $t->same(
                "{$code} guide: {$panel}",
                $expected['guide'][$panel],
                $actual['guide_' . $panel]
            );
        }

        $t->same("{$code} floor narrative", $expected['floor_narrative'], $actual['floor_narrative']);

        $items = Database::select(
            'SELECT * FROM `ds_items` WHERE `competency_id` = ? ORDER BY `position`',
            [$actual['id']]
        );

        $t->count("{$code} has five statements", 5, $items);

        foreach ($expected['items'] as $j => $expectedItem) {
            $t->same(
                sprintf('%s %d statement', $code, $j + 1),
                $expectedItem['statement'],
                $items[$j]['statement'] ?? null
            );
            $totalItems++;
        }

        $prompts = Database::select(
            'SELECT * FROM `ds_evidence_prompts` WHERE `competency_id` = ? ORDER BY `position`',
            [$actual['id']]
        );

        $t->count("{$code} has five evidence prompts", 5, $prompts);

        foreach ($expected['evidence_prompts'] as $j => $expectedPrompt) {
            $t->same(
                sprintf('%s evidence prompt %d', $code, $j + 1),
                $expectedPrompt['template'],
                $prompts[$j]['template'] ?? null
            );
            $totalPrompts++;
        }
    }

    $t->same('35 statements compared', 35, $totalItems);
    $t->same('35 evidence prompts compared', 35, $totalPrompts);

    /* ---- reference lists behind the profile dropdowns ---- */

    foreach (['sectors', 'company_sizes', 'roles', 'tenures'] as $group) {
        $values = Database::select(
            'SELECT `value` FROM `ds_reference_options`
              WHERE `instrument_id` = ? AND `group_key` = ? ORDER BY `position`',
            [$instrument['id'], $group]
        );

        $t->same(
            "reference list: {$group}",
            $source['reference'][$group],
            array_column($values, 'value')
        );
    }

    /* ---- the non-negotiable invariant ---- */

    $t->true(
        'no item statement was truncated by its column type',
        Database::scalar('SELECT COUNT(*) FROM `ds_items` WHERE CHAR_LENGTH(`statement`) < 20') === 0,
        'a TEXT column silently truncating would show up as very short statements'
    );
};
