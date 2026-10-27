<?php

declare(strict_types=1);

namespace Platform\Assessment;

/**
 * The result of applying a scoring rule to one respondent's competency scores.
 *
 * `headline` is the score that sets the level — which is NOT always the mean.
 * The Director Scorecard's floor rule makes it the weakest competency; another
 * instrument may use a weighted mean, or a mean with a mandatory-competency
 * gate. `headlineCompetencyId` names the competency responsible when the rule
 * has one, so the UI can point at it.
 */
final class ScoreSummary
{
    public function __construct(
        public readonly ?float $headline,
        public readonly ?int $headlineCompetencyId,
        public readonly ?float $mean,
        public readonly int $competenciesScored,
        public readonly int $competenciesTotal,
    ) {
    }

    public static function unscored(int $competenciesTotal): self
    {
        return new self(null, null, null, 0, $competenciesTotal);
    }

    public function isScored(): bool
    {
        return $this->headline !== null;
    }

    /**
     * The gap between the average and the headline.
     *
     * For the floor rule this is the number that matters most: it shows how
     * much of a director's attention went to the competencies they were already
     * comfortable with, and it is what a rising average conceals.
     */
    public function spread(): ?float
    {
        if ($this->mean === null || $this->headline === null) {
            return null;
        }

        return round($this->mean - $this->headline, 2);
    }

    public function hasUnscoredCompetencies(): bool
    {
        return $this->competenciesScored < $this->competenciesTotal;
    }
}
