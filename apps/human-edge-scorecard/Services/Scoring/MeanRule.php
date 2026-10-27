<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services\Scoring;

use Apps\HumanEdgeScorecard\Services\Stats;
use Platform\Assessment\ScoreSummary;
use Platform\Assessment\ScoringRule;

/**
 * The Human Edge Scorecard's rule: the level is the band of the overall
 * average of its six skill scores — the page's own summarize(). The weakest
 * skill is computed and stored alongside it, but it does not cap the level.
 *
 * Three rules that matter and are easy to get wrong:
 *
 *  - The average is the mean OF THE DIMENSION MEANS, which is what the reference
 *    computed (`mean(DIMS.map(d => dims[d.code]).filter(v => v != null))`). With
 *    all eighteen questions answered — which the page requires — that is
 *    identical to the mean of all eighteen marks, so the page's own wording, "the
 *    average of all your answers", stays true. It differs only on a partial
 *    scorecard, which the page cannot send.
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
        return 'level = band of the overall average of all answers; '
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

        // +mean(scored).toFixed(2): JavaScript rounding, so a mean on a band
        // edge lands on the same side of it as on the page.
        $mean = Stats::round2(Stats::mean(array_values($scored)));

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
            'score'        => $floorScore,
            'dimension_id' => $floorId,
        ];
    }
}
