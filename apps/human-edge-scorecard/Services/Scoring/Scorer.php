<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services\Scoring;

use Apps\HumanEdgeScorecard\Services\Stats;
use Platform\Assessment\Bander;
use Platform\Assessment\ScoreSummary;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * The only thing allowed to write a score.
 *
 * The page sends a record with its own scores; RecordMirror copies only the
 * MARKS into he_submission_responses, and this class averages each skill,
 * applies the app's MeanRule and writes the result. The console never shows a
 * score the browser asserted.
 *
 * Everything it derives is also a cache — re-running it on the same marks must
 * produce the same rows, so a bad deploy can always be corrected by rescoring.
 */
final class Scorer
{
    public function __construct(
        private readonly AppManifest $app,
        private readonly ?MeanRule $rule = null,
    ) {
    }

    private function rule(): MeanRule
    {
        return $this->rule ?? new MeanRule();
    }

    /**
     * Score a submission and persist the result.
     *
     * @return ScoreSummary the summary that was written
     */
    public function score(int $submissionId): ScoreSummary
    {
        $submission = Database::selectOne(
            sprintf('SELECT `id`, `instrument_id` FROM `%s` WHERE `id` = ?', $this->app->table('submissions')),
            [$submissionId]
        );

        if ($submission === null) {
            throw new RuntimeException("Submission {$submissionId} does not exist.");
        }

        $instrumentId  = (int) $submission['instrument_id'];
        $bander        = new Bander($this->rungRows($instrumentId));
        $perDimension  = $this->dimensionScores($submissionId, $instrumentId);

        $scores = [];
        foreach ($perDimension as $dimensionId => $row) {
            $scores[$dimensionId] = $row['score'];
        }

        $rule    = $this->rule();
        $summary = $rule->summarise($scores);
        $floor   = $rule->floor($scores);

        Database::transaction(function () use ($submissionId, $perDimension, $summary, $floor, $bander): void {
            $this->writeDimensionScores($submissionId, $perDimension, $bander);
            $this->writeSummary($submissionId, $summary, $floor, $bander);
        });

        return $summary;
    }

    /**
     * Mean of each dimension's ANSWERED questions.
     *
     * A dimension with no answers scores null rather than zero — it is unscored,
     * and an unscored dimension is left out of the average instead of dragging
     * it down.
     *
     * @return array<int, array{score: float|null, answered: int}> keyed by
     *         dimension id, in instrument position order
     */
    public function dimensionScores(int $submissionId, int $instrumentId): array
    {
        $rows = Database::select(sprintf(
            'SELECT d.`id` AS dimension_id,
                    COUNT(r.`mark`) AS answered,
                    SUM(r.`mark`)   AS total
               FROM `%s` d
          LEFT JOIN `%s` i ON i.`dimension_id` = d.`id`
          LEFT JOIN `%s` r ON r.`item_id` = i.`id` AND r.`submission_id` = ?
              WHERE d.`instrument_id` = ?
           GROUP BY d.`id`
           ORDER BY d.`position`',
            $this->app->table('dimensions'),
            $this->app->table('items'),
            $this->app->table('submission_responses')
        ), [$submissionId, $instrumentId]);

        $scores = [];
        foreach ($rows as $row) {
            $answered = (int) $row['answered'];

            $scores[(int) $row['dimension_id']] = [
                // +mean(vals).toFixed(2), as the page scores a skill.
                'score'    => $answered > 0 ? Stats::round2((int) $row['total'] / $answered) : null,
                'answered' => $answered,
            ];
        }

        return $scores;
    }

    /** @param array<int, array{score: float|null, answered: int}> $perDimension */
    private function writeDimensionScores(int $submissionId, array $perDimension, Bander $bander): void
    {
        // Rewritten wholesale rather than merged: a dimension cleared back to
        // unscored must lose its row, not keep a stale one.
        Database::statement(
            sprintf('DELETE FROM `%s` WHERE `submission_id` = ?', $this->app->table('submission_scores')),
            [$submissionId]
        );

        foreach ($perDimension as $dimensionId => $row) {
            if ($row['score'] === null) {
                continue;
            }

            Database::insert($this->app->table('submission_scores'), [
                'submission_id'  => $submissionId,
                'dimension_id'   => $dimensionId,
                'score'          => $row['score'],
                'rung_value'     => $bander->value($row['score']),
                'items_answered' => $row['answered'],
            ]);
        }
    }

    /** @param array{score: float|null, dimension_id: int|null} $floor */
    private function writeSummary(int $submissionId, ScoreSummary $summary, array $floor, Bander $bander): void
    {
        Database::statement(sprintf(
            'UPDATE `%s`
                SET `mean_score` = ?, `level_value` = ?,
                    `floor_score` = ?, `floor_dimension_id` = ?,
                    `updated_at` = ?
              WHERE `id` = ?',
            $this->app->table('submissions')
        ), [
            $summary->mean,
            // The level follows the AVERAGE. Banding the floor here instead is
            // the one mistake that would turn this instrument into the Director
            // Scorecard without anything looking wrong.
            $bander->value($summary->headline),
            $floor['score'],
            $floor['dimension_id'],
            date('Y-m-d H:i:s'),
            $submissionId,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rungRows(int $instrumentId): array
    {
        return Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`', $this->app->table('rungs')),
            [$instrumentId]
        );
    }
}
