<?php

declare(strict_types=1);

namespace Platform\Assessment;

/**
 * How an instrument turns per-competency scores into one headline score.
 *
 * This is the seam between the platform and an app. The platform owns the
 * mechanics — averaging items, banding a score, storing the result — and each
 * app owns the rule that decides what the headline means.
 *
 * The Director Scorecard implements the floor rule: the headline is the
 * WEAKEST competency, so a director cannot average their way out of a blind
 * spot. That is this instrument's argument, not a platform-wide truth — a
 * weighted mean, or a mean with a mandatory-competency gate, are equally
 * legitimate ways to set a level. Hence an interface rather than a shared
 * function: the mechanics are common, the rule is the instrument's own.
 */
interface ScoringRule
{
    /** Stored on the instrument row as `scoring_rule`, e.g. 'floor'. */
    public function key(): string;

    /** One line for the codebook and exports. */
    public function describe(): string;

    /**
     * @param array<int, float|null> $competencyScores competencyId => mean of
     *        its answered items, or null when the competency was not scored.
     *        Ordered by competency position; a rule that breaks ties on order
     *        may rely on that.
     */
    public function summarise(array $competencyScores): ScoreSummary;
}
