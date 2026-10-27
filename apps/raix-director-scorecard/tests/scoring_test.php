<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecard\Services\Scoring\FloorRule;
use Apps\RaixDirectorScorecard\Services\Scoring\Scorer;
use Platform\Assessment\Bander;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * Scoring must behave exactly as the legacy single-file build did, because
 * scores already exist in the world and a rebuild that quietly re-bands them is
 * worse than no rebuild.
 *
 * Covers: band boundaries, the floor rule's tie and unscored rules, and a full
 * round trip through the database.
 *
 * The round-trip section creates its own submission and removes it again. It
 * never truncates a table — this database is shared with a running site.
 */
return static function (Assert $t): void {
    $app        = AppRegistry::get('raix-director-scorecard');
    $instrument = Database::selectOne(
        'SELECT `id` FROM `ds_instruments` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
        ['published']
    );

    $t->true('a published instrument exists', $instrument !== null);
    if ($instrument === null) {
        return;
    }

    $instrumentId = (int) $instrument['id'];
    $rungRows     = Database::select('SELECT * FROM `ds_rungs` WHERE `instrument_id` = ? ORDER BY `value`', [$instrumentId]);
    $bander       = new Bander($rungRows);

    /* ---------- band boundaries: [min, max), so a cut point lands HIGH ---------- */

    $t->same('1.00 -> Unaware',    'Unaware',    $bander->name(1.00));
    $t->same('1.74 -> Unaware',    'Unaware',    $bander->name(1.74));
    $t->same('1.75 -> Aware',      'Aware',      $bander->name(1.75));
    $t->same('2.74 -> Aware',      'Aware',      $bander->name(2.74));
    $t->same('2.75 -> Practiced',  'Practiced',  $bander->name(2.75));
    $t->same('3.74 -> Practiced',  'Practiced',  $bander->name(3.74));
    $t->same('3.75 -> Systematic', 'Systematic', $bander->name(3.75));
    $t->same('4.00 -> Systematic', 'Systematic', $bander->name(4.00));
    $t->same('null -> no band',    null,         $bander->name(null));
    $t->true('bands cover the scale with no gap or overlap', $bander->isContiguous());

    /* ---------- the floor rule ---------- */

    $rule = new FloorRule();
    $t->same('rule key matches the instrument row', 'floor', $rule->key());

    // The case the whole instrument exists to catch: strong everywhere but one.
    $sixStrong = $rule->summarise([10 => 4.0, 20 => 4.0, 30 => 3.0, 40 => 3.0, 50 => 4.0, 60 => 3.0, 70 => 1.0]);
    $t->same('floor is the weakest competency', 1.0, $sixStrong->headline);
    $t->same('floor names the competency responsible', 70, $sixStrong->headlineCompetencyId);
    $t->same('mean is reported second', 3.14, $sixStrong->mean);
    $t->same('level comes from the floor, not the mean', 'Unaware', $bander->name($sixStrong->headline));
    $t->same('the mean alone would have read Practiced', 'Practiced', $bander->name($sixStrong->mean));
    $t->same('spread exposes the gap', 2.14, $sixStrong->spread());

    // An unscored competency is skipped, not counted as zero.
    $withGap = $rule->summarise([10 => 3.0, 20 => null, 30 => 3.0]);
    $t->same('unscored competency does not set the floor', 3.0, $withGap->headline);
    $t->same('unscored competency is excluded from the mean', 3.0, $withGap->mean);
    $t->same('scored count', 2, $withGap->competenciesScored);
    $t->same('total count', 3, $withGap->competenciesTotal);
    $t->true('reports that something is missing', $withGap->hasUnscoredCompetencies());

    // Ties resolve to the earliest competency, every time.
    $tied = $rule->summarise([10 => 4.0, 20 => 2.0, 30 => 2.0, 40 => 4.0]);
    $t->same('a tie resolves to the earliest competency', 20, $tied->headlineCompetencyId);

    $none = $rule->summarise([10 => null, 20 => null]);
    $t->same('nothing scored -> no headline', null, $none->headline);
    $t->same('nothing scored -> no mean', null, $none->mean);
    $t->true('nothing scored -> not scored', !$none->isScored());

    /* ---------- full round trip through the database ---------- */

    $competencies = Database::select(
        'SELECT `id`, `code` FROM `ds_competencies` WHERE `instrument_id` = ? ORDER BY `position`',
        [$instrumentId]
    );
    $t->count('seven competencies to score', 7, $competencies);

    // Marks chosen to reproduce the legacy build's worked example exactly.
    $marks       = ['GOV' => 4, 'DAT' => 4, 'EXP' => 3, 'FAI' => 3, 'RIS' => 4, 'HUM' => 3, 'AUT' => 1];
    $token       = bin2hex(random_bytes(16));
    $submissionId = null;

    try {
        $submissionId = Database::insert('ds_submissions', [
            'public_token'    => $token,
            'instrument_id'   => $instrumentId,
            'assessment_year' => (int) date('Y'),
            'status'          => 'in_progress',
            'is_synthetic'    => 0,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        foreach ($competencies as $competency) {
            $items = Database::select(
                'SELECT `id` FROM `ds_items` WHERE `competency_id` = ? ORDER BY `position`',
                [$competency['id']]
            );

            foreach ($items as $item) {
                Database::insert('ds_submission_responses', [
                    'submission_id' => $submissionId,
                    'item_id'       => $item['id'],
                    'mark'          => $marks[$competency['code']],
                    'answered_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $summary = (new Scorer($app))->score($submissionId);

        $t->same('round trip: floor', 1.0, $summary->headline);
        $t->same('round trip: mean', 3.14, $summary->mean);
        $t->same('round trip: all seven scored', 7, $summary->competenciesScored);

        $stored = Database::selectOne(
            'SELECT `floor_score`, `mean_score`, `level_value`, `floor_competency_id`
               FROM `ds_submissions` WHERE `id` = ?',
            [$submissionId]
        );

        $t->same('persisted floor_score', '1.00', $stored['floor_score']);
        $t->same('persisted mean_score', '3.14', $stored['mean_score']);
        $t->same('persisted level_value is 1 (Unaware)', 1, (int) $stored['level_value']);

        $floorCompetency = Database::selectOne(
            'SELECT `code` FROM `ds_competencies` WHERE `id` = ?',
            [$stored['floor_competency_id']]
        );
        $t->same('floor competency is AUT', 'AUT', $floorCompetency['code']);

        $rows = Database::select(
            'SELECT c.`code`, s.`score`, s.`rung_value`, s.`items_answered`
               FROM `ds_submission_scores` s
               JOIN `ds_competencies` c ON c.`id` = s.`competency_id`
              WHERE s.`submission_id` = ?
           ORDER BY c.`position`',
            [$submissionId]
        );

        $t->count('one score row per competency', 7, $rows);
        $t->true(
            'every competency recorded five answered items',
            array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && (int) $r['items_answered'] === 5, true)
        );
        $t->same(
            'per-competency scores match the marks given',
            ['4.00', '4.00', '3.00', '3.00', '4.00', '3.00', '1.00'],
            array_column($rows, 'score')
        );

        // Rescoring is idempotent — the derived rows are a cache, not a log.
        (new Scorer($app))->score($submissionId);
        $t->count(
            'rescoring does not duplicate score rows',
            7,
            Database::select('SELECT `id` FROM `ds_submission_scores` WHERE `submission_id` = ?', [$submissionId])
        );

        // Clearing a competency's marks must remove its row, not strand it.
        $autId = null;
        foreach ($competencies as $competency) {
            if ($competency['code'] === 'AUT') {
                $autId = (int) $competency['id'];
            }
        }
        Database::statement(
            'DELETE r FROM `ds_submission_responses` r
               JOIN `ds_items` i ON i.`id` = r.`item_id`
              WHERE r.`submission_id` = ? AND i.`competency_id` = ?',
            [$submissionId, $autId]
        );

        $after = (new Scorer($app))->score($submissionId);
        $t->count(
            'a cleared competency loses its score row',
            6,
            Database::select('SELECT `id` FROM `ds_submission_scores` WHERE `submission_id` = ?', [$submissionId])
        );
        $t->same('floor rises once the blind spot is unscored', 3.0, $after->headline);
        $t->true('and the result flags that a competency is missing', $after->hasUnscoredCompetencies());
    } finally {
        if ($submissionId !== null) {
            // Cascades clear responses and scores. Only this row is touched.
            Database::statement('DELETE FROM `ds_submissions` WHERE `id` = ?', [$submissionId]);
        }
    }

    $t->same(
        'test submission cleaned up',
        0,
        (int) Database::scalar('SELECT COUNT(*) FROM `ds_submissions` WHERE `public_token` = ?', [$token])
    );
};
