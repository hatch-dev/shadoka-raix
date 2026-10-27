<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services\Scoring;

use Platform\Assessment\Bander;
use Platform\Assessment\ScoreSummary;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * The only thing allowed to write a score.
 *
 * The browser posts marks. This class reads them back out of the database,
 * averages each competency, applies the app's ScoringRule and writes the
 * result. In the legacy single-file build the browser computed its own floor
 * and level and stored the answer, which meant any client could assert any
 * score; that is the hole this closes.
 *
 * Everything it derives is also a cache — re-running it on the same marks must
 * produce the same rows, so a bad deploy can always be corrected by rescoring.
 */
final class Scorer
{
    public function __construct(
        private readonly AppManifest $app,
        private readonly ScoringRule|FloorRule|null $rule = null,
    ) {
    }

    private function rule(): FloorRule
    {
        return $this->rule instanceof FloorRule ? $this->rule : new FloorRule();
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

        $instrumentId = (int) $submission['instrument_id'];
        $bander       = new Bander($this->rungRows($instrumentId));
        $perCompetency = $this->competencyScores($submissionId, $instrumentId);

        $scores = [];
        foreach ($perCompetency as $competencyId => $row) {
            $scores[$competencyId] = $row['score'];
        }

        $summary = $this->rule()->summarise($scores);

        Database::transaction(function () use ($submissionId, $perCompetency, $summary, $bander): void {
            $this->writeCompetencyScores($submissionId, $perCompetency, $bander);
            $this->writeSummary($submissionId, $summary, $bander);
        });

        return $summary;
    }

    /**
     * Mean of each competency's ANSWERED items.
     *
     * A competency with no answers scores null rather than zero — it is
     * unscored, and an unscored competency cannot set the floor.
     *
     * @return array<int, array{score: float|null, answered: int}> keyed by
     *         competency id, in instrument position order
     */
    public function competencyScores(int $submissionId, int $instrumentId): array
    {
        $rows = Database::select(sprintf(
            'SELECT c.`id` AS competency_id,
                    COUNT(r.`mark`) AS answered,
                    AVG(r.`mark`)   AS mean_mark
               FROM `%s` c
          LEFT JOIN `%s` i ON i.`competency_id` = c.`id`
          LEFT JOIN `%s` r ON r.`item_id` = i.`id` AND r.`submission_id` = ?
              WHERE c.`instrument_id` = ?
           GROUP BY c.`id`
           ORDER BY c.`position`',
            $this->app->table('competencies'),
            $this->app->table('items'),
            $this->app->table('submission_responses')
        ), [$submissionId, $instrumentId]);

        $scores = [];
        foreach ($rows as $row) {
            $answered = (int) $row['answered'];

            $scores[(int) $row['competency_id']] = [
                'score'    => $answered > 0 ? round((float) $row['mean_mark'], 2) : null,
                'answered' => $answered,
            ];
        }

        return $scores;
    }

    /** @param array<int, array{score: float|null, answered: int}> $perCompetency */
    private function writeCompetencyScores(int $submissionId, array $perCompetency, Bander $bander): void
    {
        // Rewritten wholesale rather than merged: a competency cleared back to
        // unscored must lose its row, not keep a stale one.
        Database::statement(
            sprintf('DELETE FROM `%s` WHERE `submission_id` = ?', $this->app->table('submission_scores')),
            [$submissionId]
        );

        foreach ($perCompetency as $competencyId => $row) {
            if ($row['score'] === null) {
                continue;
            }

            Database::insert($this->app->table('submission_scores'), [
                'submission_id'  => $submissionId,
                'competency_id'  => $competencyId,
                'score'          => $row['score'],
                'rung_value'     => $bander->value($row['score']),
                'items_answered' => $row['answered'],
            ]);
        }
    }

    private function writeSummary(int $submissionId, ScoreSummary $summary, Bander $bander): void
    {
        Database::statement(sprintf(
            'UPDATE `%s`
                SET `floor_score` = ?, `floor_competency_id` = ?, `mean_score` = ?,
                    `level_value` = ?, `updated_at` = ?
              WHERE `id` = ?',
            $this->app->table('submissions')
        ), [
            $summary->headline,
            $summary->headlineCompetencyId,
            $summary->mean,
            $bander->value($summary->headline),
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

    /**
     * Which competencies fall below the re-score threshold.
     *
     * @param array<int, float|null> $scores
     * @return list<int> competency ids
     */
    public function belowPracticed(array $scores): array
    {
        $threshold = (float) $this->app->config('scoring.practiced_threshold', 2.75);

        $below = [];
        foreach ($scores as $competencyId => $score) {
            if ($score !== null && $score < $threshold) {
                $below[] = (int) $competencyId;
            }
        }

        return $below;
    }
}
