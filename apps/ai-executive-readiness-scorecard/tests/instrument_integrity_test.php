<?php

declare(strict_types=1);

use Apps\AiExecutiveReadinessScorecard\Services\InstrumentData;
use Apps\AiExecutiveReadinessScorecard\Services\RespondentIdentity;
use Apps\AiExecutiveReadinessScorecard\Services\Scopes;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The published instrument, row for row, against the JSON the extractor dumped
 * from the reference — so a transcription error, or a seeder that drops a
 * field, cannot reach a respondent unnoticed.
 */
return static function (Assert $t): void {
    $app  = AppRegistry::get('ai-executive-readiness-scorecard');
    $json = json_decode((string) file_get_contents($app->path('database/seeds/data/instrument-v1.json')), true, 512, JSON_THROW_ON_ERROR);

    InstrumentData::flush();
    $data       = new InstrumentData($app);
    $instrument = $data->published();
    $id         = (int) $instrument['id'];

    $t->same('version', $json['version'], $instrument['version']);
    $t->same('instructions', $json['text']['instructions'], $instrument['instructions']);
    $t->same('comparison note', $json['text']['comparison_note'], $instrument['comparison_note']);
    $t->same('disclaimer', $json['text']['disclaimer'], $instrument['disclaimer']);

    $dimensions = $data->dimensions($id);
    $t->same('four dimensions', 4, count($dimensions));
    $t->same('codes in order', ['JDG', 'FLU', 'STR', 'CHG'], array_column($dimensions, 'code'));

    $anchors = 0;

    foreach ($json['dimensions'] as $d => $expected) {
        $row = $dimensions[$d];

        foreach (['name', 'description', 'summary_line'] as $field) {
            $t->same("{$expected['code']} {$field}", $expected[$field], $row[$field]);
        }

        foreach (['what', 'why', 'how'] as $panel) {
            $t->same("{$expected['code']} guide {$panel}", $expected['guide'][$panel], $row['guide_' . $panel]);
        }

        $t->same("{$expected['code']} has three questions", 3, count($row['items']));

        foreach ($expected['items'] as $i => $item) {
            $stored = $row['items'][$i];
            $where  = $expected['code'] . ' ' . ($i + 1);

            $t->same("{$where} label", $item['label'], $stored['label']);
            $t->same("{$where} question", $item['question'], $stored['question']);
            $t->same("{$where} low label", $item['anchor_low_label'], $stored['anchor_low_label']);
            $t->same("{$where} high label", $item['anchor_high_label'], $stored['anchor_high_label']);

            foreach (['self', 'leadership'] as $scope) {
                foreach (['low', 'high'] as $end) {
                    $t->same("{$where} {$scope} {$end}", $item['anchors'][$scope][$end], $stored[$scope . '_' . $end . '_text']);
                    $anchors++;
                }
            }
        }
    }

    $t->same('48 anchor texts: 12 questions, two ratings, two ends', 48, $anchors);

    // The self and leadership anchors are different sentences, not copies.
    $first = $dimensions[0]['items'][0];
    $t->true('self and leadership anchors differ', $first['self_low_text'] !== $first['leadership_low_text']);

    /* ---------- levels ---------- */

    $rungs = $data->rungs($id);
    $t->same('five rungs', 5, count($rungs));

    foreach ($json['rungs'] as $i => $rung) {
        $t->same("rung {$rung['value']} name", $rung['name'], $rungs[$i]['name']);
        $t->same("rung {$rung['value']} description", $rung['description'], $rungs[$i]['description']);
        $t->same("rung {$rung['value']} colour", $rung['colour_hex'], $rungs[$i]['colour_hex']);
        $t->same(
            "rung {$rung['value']} band",
            [$json['bands'][$i]['min'], $json['bands'][$i]['max']],
            [
                $rungs[$i]['band_min'] === null ? null : (float) $rungs[$i]['band_min'],
                $rungs[$i]['band_max'] === null ? null : (float) $rungs[$i]['band_max'],
            ]
        );
    }

    $t->same('config bands agree with the instrument', [null, 1.8], $app->config('scoring.bands')[1]);

    /* ---------- scopes, reference lists, identity ---------- */

    foreach ($json['scopes'] as $i => $scope) {
        $mine = Scopes::all()[$i];
        $t->same("scope {$scope['key']} key", $scope['key'], $mine['key']);
        $t->same("scope {$scope['key']} label", $scope['label'], $mine['label']);
        $t->same("scope {$scope['key']} group label", $scope['group_label'], $mine['group_label']);
        $t->same("scope {$scope['key']} colour", $scope['colour'], $mine['colour']);
    }

    foreach (['sectors', 'organisation_sizes', 'roles', 'tenures'] as $group) {
        $stored = array_column(Database::select(
            sprintf('SELECT `value` FROM `%s` WHERE `instrument_id` = ? AND `group_key` = ? ORDER BY `position`', $app->table('reference_options')),
            [$id, $group]
        ), 'value');

        $t->same("reference list {$group}", $json['reference'][$group], $stored);
    }

    $t->same('email pattern matches the reference', '/' . $json['identity']['email_pattern'] . '/', RespondentIdentity::EMAIL_PATTERN);
    $t->same('blocked domains match the reference', $json['identity']['blocked_email_domains'], RespondentIdentity::BLOCKED_DOMAINS);
};
