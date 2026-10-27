<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services\Scoring;

use Platform\Assessment\ScoreSummary;
use Platform\Assessment\ScoringRule;

/**
 * The Enterprise Scorecard's rule: the level is the band of the overall average.
 *
 * This is the opposite of the Director Scorecard's floor rule, and the
 * difference is the instrument's argument rather than an inconsistency. That
 * instrument measures one person's own conduct, where a blind spot is what the
 * board is exposed to and six strengths do not compensate for it. This one
 * measures an organisation's readiness, where the six dimensions genuinely do
 * add up: an organisation strong on five and weak on one is more ready than one
 * weak on all six, and reporting both as the same level would say something
 * false.
 *
 * So the level follows the average, and the FLOOR — the weakest dimension — is
 * computed and reported alongside it. The floor is the number the analytics
 * screens are built around, because the gap between it and the average is what
 * shows where an organisation has been spending its attention. It just does not
 * cap the level.
 *
 * Three rules that matter and are easy to get wrong:
 *
 *  - The average is the mean OF THE DIMENSION MEANS, which is what the reference
 *    computed (`mean(DIMS.map(d => dims[d.code]).filter(v => v != null))`). With
 *    all thirty questions answered — which submit() requires — that is
 *    identical to the mean of all thirty marks, so the page's own wording, "the
 *    average of all your answers", stays true. It differs only on a partial
 *    scorecard, which cannot be submitted.
 *  - An UNSCORED dimension is skipped, not treated as zero. It is left out of
 *    the average and cannot be the floor; the summary reports how many were
 *    scored so the UI can say the level may look better than it is.
 *  - A TIE on the floor resolves to the earliest dimension in instrument order,
 *    so the same marks always name the same weakest dimension. Without that the
 *    "Floor" tag moves between dimensions at random on identical data.
 */
final class MeanRule implements ScoringRule
{
    public function key(): string
    {
        return 'mean';
    }

    public function describe(): string
    {
        return 'organisation level = band of the overall average of all answers; '
             . 'the floor is the weakest scored dimension, reported alongside';
    }

    /**
     * @param array<int, float|null> $dimensionScores dimensionId => score, in
     *        instrument position order
     */
    public function summarise(array $dimensionScores): ScoreSummary
    {
        $total  = count($dimensionScores);
        $scored = array_filter($dimensionScores, static fn (?float $v): bool => $v !== null);

        if ($scored === []) {
            return ScoreSummary::unscored($total);
        }

        $mean = round(array_sum($scored) / count($scored), 2);

        /*
         * headlineCompetencyId is null, and that is the honest answer: the score
         * that sets the level is the whole row's average, so no single dimension
         * is responsible for it. The platform's ScoreSummary names one only when
         * the rule has one, which the floor rule does and this does not.
         *
         * The floor is asked for separately, by floor() below, because it is a
         * different fact about the same marks.
         */
        return new ScoreSummary(
            headline:             $mean,
            headlineCompetencyId: null,
            mean:                 $mean,
            competenciesScored:   count($scored),
            competenciesTotal:    $total,
        );
    }

    /**
     * The weakest scored dimension.
     *
     * Reported on the result and used by every analytics screen; it does not
     * enter summarise(), because it does not set the level.
     *
     * @param array<int, float|null> $dimensionScores dimensionId => score, in
     *        instrument position order
     * @return array{score: float|null, dimension_id: int|null}
     */
    public function floor(array $dimensionScores): array
    {
        $floorScore = null;
        $floorId    = null;

        // Strict less-than keeps the FIRST dimension on a tie, and the array
        // arrives in instrument order, so ties resolve deterministically.
        foreach ($dimensionScores as $dimensionId => $score) {
            if ($score === null) {
                continue;
            }

            if ($floorScore === null || $score < $floorScore) {
                $floorScore = $score;
                $floorId    = (int) $dimensionId;
            }
        }

        return [
            'score'        => $floorScore === null ? null : round($floorScore, 2),
            'dimension_id' => $floorId,
        ];
    }
}
