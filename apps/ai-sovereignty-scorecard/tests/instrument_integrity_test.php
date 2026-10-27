<?php

declare(strict_types=1);

use Apps\AiSovereigntyScorecard\Services\RespondentIdentity;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The seeded instrument must match the reference build, word for word.
 *
 * Every dimension name, every one of the sixteen questions, both written anchors
 * on each of them, the five level names and the four band cut points are
 * compared against database/seeds/data/instrument-v1.json — which was dumped out
 * of AI_Sovereignty_Scorecard/index.html by seeds/data/extract-instrument.mjs
 * rather than retyped.
 *
 * This is the test that makes the extraction worth doing. A retyped instrument
 * looks completely fine until someone compares a score to one taken on the
 * original, and by then every record in the dataset is affected.
 */
return static function (Assert $t): void {
    $file = __DIR__ . '/../database/seeds/data/instrument-v1.json';

    $t->true('the extracted instrument file is present', is_file($file));
    if (!is_file($file)) {
        return;
    }

    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    $instrument = Database::selectOne(
        'SELECT * FROM `sov_instruments` WHERE `version` = ?',
        [$data['version']]
    );

    $t->true('instrument v' . $data['version'] . ' is in the database', $instrument !== null);
    if ($instrument === null) {
        return;
    }

    $instrumentId = (int) $instrument['id'];

    $t->same('it is published', 'published', $instrument['status']);
    $t->same('its rule is the mean rule', 'mean', $instrument['scoring_rule']);

    /* ---------- the five levels, and where they band ---------- */

    $rungs = Database::select(
        'SELECT * FROM `sov_rungs` WHERE `instrument_id` = ? ORDER BY `value`',
        [$instrumentId]
    );

    $t->count('five levels', 5, $rungs);

    foreach ($data['rungs'] as $index => $expected) {
        $actual = $rungs[$index] ?? null;

        if ($actual === null) {
            $t->true('level ' . $expected['value'] . ' exists', false);
            continue;
        }

        $t->same('level ' . $expected['value'] . ' name', $expected['name'], $actual['name']);
        $t->same('level ' . $expected['value'] . ' description', $expected['description'], $actual['description']);
        $t->same('level ' . $expected['value'] . ' colour', $expected['colour_hex'], $actual['colour_hex']);
    }

    /*
     * The cut points, which are the single most consequential numbers here: the
     * reference bands at 1.80 / 2.60 / 3.40 / 4.20, and one of them a tenth out
     * silently re-bands a slice of every dataset.
     */
    $bands = [];
    foreach ($data['bands'] as $band) {
        $bands[(int) $band['rung_value']] = $band;
    }

    foreach ($rungs as $rung) {
        $expected = $bands[(int) $rung['value']];

        $t->same(
            'level ' . $rung['value'] . ' band_min',
            $expected['min'] === null ? null : number_format((float) $expected['min'], 2),
            $rung['band_min'] === null ? null : number_format((float) $rung['band_min'], 2)
        );
        $t->same(
            'level ' . $rung['value'] . ' band_max',
            $expected['max'] === null ? null : number_format((float) $expected['max'], 2),
            $rung['band_max'] === null ? null : number_format((float) $rung['band_max'], 2)
        );
    }

    /*
     * And that they are the reference's own numbers, not merely consistent with
     * themselves. A regenerated JSON carrying different edges would pass every
     * check above and this one would catch it.
     */
    $edges = array_values(array_filter(
        array_map(static fn (array $r): ?float => $r['band_min'] === null ? null : (float) $r['band_min'], $rungs),
        static fn (?float $v): bool => $v !== null
    ));
    $t->same('the band edges are the reference\'s', [1.8, 2.6, 3.4, 4.2], $edges);

    /* ---------- four dimensions, sixteen questions, thirty-two anchors ---------- */

    $dimensions = Database::select(
        'SELECT * FROM `sov_dimensions` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrumentId]
    );

    $t->count('four dimensions', 4, $dimensions);
    $t->same(
        'their codes, in order',
        array_column($data['dimensions'], 'code'),
        array_column($dimensions, 'code')
    );

    $questions = 0;

    foreach ($data['dimensions'] as $index => $expected) {
        $actual = $dimensions[$index] ?? null;

        if ($actual === null) {
            $t->true($expected['code'] . ' exists', false);
            continue;
        }

        $code = $expected['code'];

        $t->same($code . ' name', $expected['name'], $actual['name']);
        $t->same($code . ' description', $expected['description'], $actual['description']);
        $t->same($code . ' question to press', $expected['pressing_question'], $actual['pressing_question']);
        $t->same($code . ' summary line', $expected['summary_line'], $actual['summary_line']);
        $t->same($code . ' failing signature', $expected['failure_line'], $actual['failure_line']);
        $t->same($code . ' guide: what it is', $expected['guide']['what'], $actual['guide_what']);
        $t->same($code . ' guide: why it matters', $expected['guide']['why'], $actual['guide_why']);
        $t->same($code . ' guide: how to build it', $expected['guide']['how'], $actual['guide_how']);

        $items = Database::select(
            'SELECT * FROM `sov_items` WHERE `dimension_id` = ? ORDER BY `position`',
            [$actual['id']]
        );

        $t->count($code . ' has four questions', 4, $items);

        foreach ($expected['items'] as $i => $item) {
            $stored = $items[$i] ?? null;

            if ($stored === null) {
                $t->true($code . ' ' . $item['position'] . ' exists', false);
                continue;
            }

            $label = $code . ' ' . $item['position'];

            $t->same($label . ' label', $item['label'], $stored['label']);
            $t->same($label . ' question', $item['question'], $stored['question']);
            $t->same($label . ' anchor 1 label', $item['anchor_low_label'], $stored['anchor_low_label']);
            $t->same($label . ' anchor 1 text', $item['anchor_low_text'], $stored['anchor_low_text']);
            $t->same($label . ' anchor 5 label', $item['anchor_high_label'], $stored['anchor_high_label']);
            $t->same($label . ' anchor 5 text', $item['anchor_high_text'], $stored['anchor_high_text']);

            $questions++;
        }
    }

    $t->same('sixteen questions in total', 16, $questions);

    /* ---------- the reference lists the profile form is built from ---------- */

    $reference = [];
    foreach (Database::select(
        'SELECT `group_key`, `value` FROM `sov_reference_options`
          WHERE `instrument_id` = ? AND `is_active` = 1 ORDER BY `group_key`, `position`',
        [$instrumentId]
    ) as $row) {
        $reference[$row['group_key']][] = $row['value'];
    }

    foreach (['sectors', 'organisation_sizes', 'roles', 'tenures'] as $group) {
        $t->same(
            $group . ' match the reference',
            $data['reference'][$group],
            $reference[$group] ?? []
        );
    }

    /* ---------- the identity rules, which are instrument data too ---------- */

    /*
     * The email pattern lives in two places by necessity — PHP for the server and
     * the JSON for the record of what the reference used. They must not drift,
     * because the PHP one is what actually refuses an address.
     */
    /*
     * Byte-identical once PHP's delimiters come off. The PHP constant is a
     * SINGLE-quoted string precisely so that it can be: in single quotes only
     * \\ and \' are escapes, so every \s, \] and \. in the pattern reaches the
     * regex engine exactly as written. Retyping it into double quotes would
     * consume those backslashes and quietly widen what the form accepts.
     */
    $t->same(
        'the email pattern is the reference\'s',
        $data['identity']['email_pattern'],
        trim(RespondentIdentity::EMAIL_PATTERN, '/')
    );

    // And that it still behaves: the reason this pattern exists rather than
    // filter_var(FILTER_VALIDATE_EMAIL) is that filter_var accepts "a@b".
    $t->true('a plain address passes', preg_match(RespondentIdentity::EMAIL_PATTERN, 'dana@meridian.com') === 1);
    $t->true('an address with no TLD is refused', preg_match(RespondentIdentity::EMAIL_PATTERN, 'dana@meridian') === 0);

    $t->same(
        'the blocked domain list is the reference\'s',
        $data['identity']['blocked_email_domains'],
        RespondentIdentity::BLOCKED_DOMAINS
    );

    /* ---------- the generator's tables are instrument data as well ---------- */

    $t->count('six archetypes', 6, $data['synthetic']['archetypes']);

    foreach ($data['synthetic']['archetypes'] as $key => $archetype) {
        $t->count(
            $key . ' has one target per dimension',
            4,
            $archetype['targets']
        );
    }

    foreach ($data['synthetic']['sector_prior'] as $sector => $shifts) {
        $t->count('sector prior for ' . $sector . ' has four shifts', 4, $shifts);
    }

    foreach ($data['synthetic']['role_bias'] as $role => $shifts) {
        $t->count('role bias for ' . $role . ' has four shifts', 4, $shifts);
    }

    /*
     * Every sector the instrument offers needs a name suffix pool, or an
     * organisation in that sector cannot be named and the generator falls back
     * silently.
     */
    foreach ($data['reference']['sectors'] as $sector) {
        $t->true(
            'the generator can name an organisation in ' . $sector,
            isset($data['synthetic']['org_suffix'][$sector])
        );
    }
};
