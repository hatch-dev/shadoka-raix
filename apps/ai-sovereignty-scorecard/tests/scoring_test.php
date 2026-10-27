<?php

declare(strict_types=1);

use Apps\AiSovereigntyScorecard\Services\Scoring\MeanRule;
use Apps\AiSovereigntyScorecard\Services\Scoring\Scorer;
use Platform\Assessment\Bander;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * Scoring must behave exactly as the reference build did, because the numbers it
 * produces are the whole product and a rebuild that quietly re-bands them is
 * worse than no rebuild.
 *
 * The rule this suite exists to protect: the level is the band of the OVERALL
 * AVERAGE, and the floor is reported beside it. The sibling app in the folder
 * next door does the opposite, and the two are one line apart in the Scorer —
 * banding the floor instead of the mean would produce numbers that all look
 * plausible and are all wrong.
 *
 * The round-trip section creates its own submission and removes it again. It
 * never truncates a table: this database is shared with a running site.
 */
return static function (Assert $t): void {
    $app        = AppRegistry::get('ai-sovereignty-scorecard');
    $instrument = Database::selectOne(
        'SELECT `id` FROM `sov_instruments` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
        ['published']
    );

    $t->true('a published instrument exists', $instrument !== null);
    if ($instrument === null) {
        return;
    }

    $instrumentId = (int) $instrument['id'];
    $bander       = new Bander(
        Database::select('SELECT * FROM `sov_rungs` WHERE `instrument_id` = ? ORDER BY `value`', [$instrumentId])
    );

    /* ---------- band boundaries: [min, max), so a cut point lands HIGH ---------- */

    $t->same('1.00 -> Undeveloped', 'Undeveloped', $bander->name(1.00));
    $t->same('1.79 -> Undeveloped', 'Undeveloped', $bander->name(1.79));
    $t->same('1.80 -> Emerging',    'Emerging',    $bander->name(1.80));
    $t->same('2.59 -> Emerging',    'Emerging',    $bander->name(2.59));
    $t->same('2.60 -> Developing',  'Developing',  $bander->name(2.60));
    $t->same('3.39 -> Developing',  'Developing',  $bander->name(3.39));
    $t->same('3.40 -> Established', 'Established', $bander->name(3.40));
    $t->same('4.19 -> Established', 'Established', $bander->name(4.19));
    $t->same('4.20 -> Embedded',    'Embedded',    $bander->name(4.20));
    $t->same('5.00 -> Embedded',    'Embedded',    $bander->name(5.00));
    $t->same('null -> no band',     null,          $bander->name(null));
    $t->true('bands cover the scale with no gap or overlap', $bander->isContiguous());

    /* ---------- the mean rule ---------- */

    $rule = new MeanRule();
    $t->same('rule key matches the instrument row', 'mean', $rule->key());

    /*
     * The case that separates this instrument from its sibling: five strong
     * dimensions and one weak one. The floor rule would call this Undeveloped;
     * the mean rule calls it what the row as a whole says.
     */
    $lopsided = [10 => 4.0, 20 => 4.0, 30 => 4.0, 40 => 4.0, 50 => 4.0, 60 => 1.0];
    $summary  = $rule->summarise($lopsided);

    $t->same('the headline is the average, not the floor', 3.5, $summary->headline);
    $t->same('the average is reported as the mean too', 3.5, $summary->mean);
    $t->same('no single dimension is responsible for the level', null, $summary->headlineCompetencyId);
    $t->same('3.50 bands as Established', 'Established', $bander->name($summary->headline));

    $floor = $rule->floor($lopsided);
    $t->same('the floor is the weakest dimension', 1.0, $floor['score']);
    $t->same('the floor names its dimension', 60, $floor['dimension_id']);

    /* A tie resolves to the earliest dimension in instrument order, always. */
    $tied = $rule->floor([10 => 3.0, 20 => 2.0, 30 => 2.0, 40 => 4.0, 50 => 4.0, 60 => 4.0]);
    $t->same('a tied floor keeps the first dimension in order', 20, $tied['dimension_id']);

    /* An unscored dimension is skipped, not counted as zero or as one. */
    $partial = $rule->summarise([10 => 4.0, 20 => 4.0, 30 => null, 40 => null, 50 => null, 60 => null]);
    $t->same('unscored dimensions stay out of the average', 4.0, $partial->mean);
    $t->same('it reports how many were scored', 2, $partial->competenciesScored);
    $t->same('and how many there are', 6, $partial->competenciesTotal);
    $t->true('it knows some are missing', $partial->hasUnscoredCompetencies());

    $partialFloor = $rule->floor([10 => 4.0, 20 => null, 30 => null, 40 => null, 50 => null, 60 => null]);
    $t->same('an unscored dimension cannot be the floor', 10, $partialFloor['dimension_id']);

    /* Nothing scored at all is unscored, not zero. */
    $none = $rule->summarise([10 => null, 20 => null, 30 => null, 40 => null, 50 => null, 60 => null]);
    $t->same('nothing scored means no headline', null, $none->headline);
    $t->true('and it is not treated as scored', !$none->isScored());

    /* The gap the analytics screens are built around. */
    $t->same('spread is the average above the floor', 0.0, $rule->summarise([10 => 3.0, 20 => 3.0])->spread());

    /* ---------- a full round trip through the database ---------- */

    $dimensions = Database::select(
        'SELECT `id`, `code` FROM `sov_dimensions` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrumentId]
    );

    $token = bin2hex(random_bytes(16));

    $submissionId = Database::insert('sov_submissions', [
        'public_token'      => $token,
        'instrument_id'     => $instrumentId,
        'respondent_name'   => 'Scoring Test',
        'respondent_email'  => 'scoring.test@example-not-used.test',
        'respondent_phone'  => '+1 555 0100',
        'organisation_name' => 'Scoring Test Organisation',
        'organisation_size' => '1–50',
        'assessment_year'   => (int) date('Y'),
        'status'            => 'in_progress',
        'is_synthetic'      => 1,
        'created_at'        => date('Y-m-d H:i:s'),
    ]);

    try {
        /*
         * A deliberately lopsided scorecard: 5s on the first dimension down to
         * 1s on the last two. Mean of the dimension means = (5+3+1+1)/4 = 2.50,
         * which is Emerging; the floor is 1.00, which is Undeveloped. The two
         * must not be confused anywhere.
         */
        $marks = [5, 3, 1, 1];
        $now   = date('Y-m-d H:i:s');

        foreach ($dimensions as $index => $dimension) {
            $items = Database::select(
                'SELECT `id` FROM `sov_items` WHERE `dimension_id` = ? ORDER BY `position`',
                [$dimension['id']]
            );

            foreach ($items as $item) {
                Database::insert('sov_submission_responses', [
                    'submission_id' => $submissionId,
                    'item_id'       => $item['id'],
                    'mark'          => $marks[$index],
                    'answered_at'   => $now,
                ]);
            }
        }

        $written = (new Scorer($app))->score($submissionId);

        $t->same('the round trip averages to 2.50', 2.5, $written->mean);

        $row = Database::selectOne('SELECT * FROM `sov_submissions` WHERE `id` = ?', [$submissionId]);

        $t->same('mean_score is stored', '2.50', $row['mean_score']);
        $t->same('floor_score is the weakest dimension', '1.00', $row['floor_score']);

        $t->same(
            'level_value is banded from the MEAN, not the floor',
            2,
            (int) $row['level_value']
        );
        $t->true(
            'which is not what banding the floor would have given',
            (int) $row['level_value'] !== $bander->value(1.00)
        );

        $floorDimension = Database::selectOne(
            'SELECT `code` FROM `sov_dimensions` WHERE `id` = ?',
            [$row['floor_dimension_id']]
        );
        $t->same(
            'the floor names the first of the two tied lowest dimensions',
            $dimensions[2]['code'],
            $floorDimension['code']
        );

        $scores = Database::select(
            'SELECT d.`code`, s.`score`, s.`rung_value`, s.`items_answered`
               FROM `sov_submission_scores` s
               JOIN `sov_dimensions` d ON d.`id` = s.`dimension_id`
              WHERE s.`submission_id` = ?
           ORDER BY d.`position`',
            [$submissionId]
        );

        $t->count('one score row per dimension', 4, $scores);
        $t->same('each records four answered questions', 4, (int) $scores[0]['items_answered']);
        $t->same('the first dimension scored 5.00', '5.00', $scores[0]['score']);
        $t->same('and bands as Embedded', 5, (int) $scores[0]['rung_value']);

        /* Re-running the scorer on the same marks must produce the same rows. */
        $again = (new Scorer($app))->score($submissionId);
        $t->same('rescoring is idempotent', $written->mean, $again->mean);
        $t->count(
            'and does not duplicate the score rows',
            4,
            Database::select('SELECT `id` FROM `sov_submission_scores` WHERE `submission_id` = ?', [$submissionId])
        );

        /*
         * Clearing a dimension's marks must remove its score row rather than
         * leave a stale one, and must lift the average rather than drag it down.
         */
        Database::statement(
            'DELETE FROM `sov_submission_responses`
              WHERE `submission_id` = ?
                AND `item_id` IN (SELECT `id` FROM `sov_items` WHERE `dimension_id` = ?)',
            [$submissionId, $dimensions[3]['id']]
        );

        $cleared = (new Scorer($app))->score($submissionId);

        $t->same('clearing a dimension leaves it out of the average', 3.0, $cleared->mean);
        $t->same('and it reports three scored of four', 3, $cleared->competenciesScored);
        $t->count(
            'and its score row is gone',
            3,
            Database::select('SELECT `id` FROM `sov_submission_scores` WHERE `submission_id` = ?', [$submissionId])
        );
    } finally {
        // Responses and scores cascade from the submission.
        Database::statement('DELETE FROM `sov_submissions` WHERE `id` = ?', [$submissionId]);
    }
};
