<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Services\Scoring;

use Platform\Assessment\ScoreSummary;
use Platform\Assessment\ScoringRule;

/**
 * The Director Scorecard's rule: your level is your weakest competency.
 *
 * This is the instrument's entire argument. A director who is Systematic on six
 * competencies and Unaware on the seventh is Unaware, because the blind spot is
 * what the board is exposed to — the six do not compensate for it. The mean is
 * still reported, second, and the gap between the two is the number worth
 * watching year on year.
 *
 * Two rules that matter and are easy to get wrong:
 *
 *  - An UNSCORED competency cannot set the floor. It is skipped, not treated as
 *    zero — otherwise a half-finished scorecard would read as catastrophic. The
 *    result reports how many were scored so the UI can say the level may look
 *    better than it is.
 *  - A TIE resolves to the earliest competency in instrument order, so the same
 *    marks always name the same floor. Without that, the "Floor" tag moves
 *    between competencies at random on identical data.
 */
final class FloorRule implements ScoringRule
{
    public function key(): string
    {
        return 'floor';
    }

    public function describe(): string
    {
        return 'director level = band of the weakest scored competency (floor rule)';
    }

    /** @param array<int, float|null> $competencyScores competencyId => score, in instrument order */
    public function summarise(array $competencyScores): ScoreSummary
    {
        $total  = count($competencyScores);
        $scored = array_filter($competencyScores, static fn (?float $v): bool => $v !== null);

        if ($scored === []) {
            return ScoreSummary::unscored($total);
        }

        $floorId    = null;
        $floorScore = null;

        // Strict less-than keeps the FIRST competency on a tie, and the array
        // arrives in instrument order, so ties are resolved deterministically.
        foreach ($competencyScores as $competencyId => $score) {
            if ($score === null) {
                continue;
            }

            if ($floorScore === null || $score < $floorScore) {
                $floorScore = $score;
                $floorId    = (int) $competencyId;
            }
        }

        return new ScoreSummary(
            headline:             round((float) $floorScore, 2),
            headlineCompetencyId: $floorId,
            mean:                 round(array_sum($scored) / count($scored), 2),
            competenciesScored:   count($scored),
            competenciesTotal:    $total,
        );
    }
}
