<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services\Scoring;

use Platform\Assessment\Bander;

/**
 * The instrument's arithmetic, and nothing else: no database, no request.
 *
 * A line-for-line port of the reference's scoreDims(), summarize() and
 * scorePair(). Everything that states a score — the Scorer that persists one,
 * the analytics, the exports, the seeder — goes through this class, so no two
 * screens can compute a profile two ways.
 *
 * The rules, as the reference's codebook states them:
 *
 *  - Each scope (self, leadership) is scored SEPARATELY. The two profiles are
 *    never averaged together.
 *  - A dimension score is the mean of its three ratings in that scope, and only
 *    when all three are valid integers 1–5. Otherwise it is null (unscored).
 *  - The overall score is the mean of the scope's twelve ratings — but only
 *    when every dimension is scored. A partial profile has no overall score and
 *    no level.
 *  - The level is the band that overall score falls in, assigned from the
 *    UNROUNDED score. Rounding happens only for display.
 *  - The floor is the lowest dimension score in the scope. On a tie it is the
 *    FIRST tied dimension in questionnaire order (the reference's reduce keeps
 *    the earlier one on `<=`).
 *  - The difference is self minus leadership, per item, per dimension and
 *    overall, computed from unrounded values; range −4 to +4.
 *
 * Shapes. `$dimensions` is the instrument in order: list of
 * ['code' => 'JDG', 'items' => n]. `$responses` is keyed scope → code → zero-based
 * item index → rating, exactly the reference's `responses.self.JDG[0]`.
 */
final class PairedScoring
{
    public const SCOPES = ['self', 'leadership'];

    /** @param list<array{code:string, items:int}> $dimensions */
    public function __construct(
        private readonly array $dimensions,
        private readonly Bander $bander,
    ) {
    }

    public function bander(): Bander
    {
        return $this->bander;
    }

    /** @return list<array{code:string, items:int}> */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public static function validRating(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 5;
    }

    /**
     * scoreDims(): each dimension's mean in one scope, or null if any of its
     * ratings is missing or invalid.
     *
     * @param array<string, array<int, int>> $scopeResponses code → index → rating
     * @return array<string, float|null>
     */
    public function scoreDims(array $scopeResponses): array
    {
        $dims = [];

        foreach ($this->dimensions as $dimension) {
            $values = [];

            for ($i = 0; $i < $dimension['items']; $i++) {
                $values[] = $scopeResponses[$dimension['code']][$i] ?? null;
            }

            $valid = $values !== [] && array_reduce(
                $values,
                static fn (bool $ok, mixed $v): bool => $ok && self::validRating($v),
                true
            );

            $dims[$dimension['code']] = $valid ? (float) array_sum($values) / count($values) : null;
        }

        return $dims;
    }

    /**
     * summarize(): the floor, and — only when every dimension is scored — the
     * mean of the dimension means and its level.
     *
     * The level returned here is the rung ARRAY (or null); callers that want the
     * reference's string use its name.
     *
     * @param array<string, float|null> $dims
     * @return array{floor: float|null, floorCode: string|null, mean: float|null, level: array<string,mixed>|null}
     */
    public function summarize(array $dims): array
    {
        $floorCode = null;
        $scored    = [];

        foreach ($this->dimensions as $dimension) {
            $value = $dims[$dimension['code']] ?? null;

            if ($value === null) {
                continue;
            }

            $scored[] = $value;

            // `<=` keeps the EARLIER dimension on a tie, as the reference's
            // reduce((a, b) => dims[a] <= dims[b] ? a : b) does.
            if ($floorCode === null || !($dims[$floorCode] <= $value)) {
                $floorCode = $dimension['code'];
            }
        }

        if ($scored === []) {
            return ['floor' => null, 'floorCode' => null, 'mean' => null, 'level' => null];
        }

        $mean = count($scored) === count($this->dimensions)
            ? (float) array_sum($scored) / count($scored)
            : null;

        return [
            'floor'     => $dims[$floorCode],
            'floorCode' => $floorCode,
            'mean'      => $mean,
            'level'     => $this->bander->band($mean),
        ];
    }

    /**
     * scorePair(): both profiles and the difference between them.
     *
     * For a complete scope the overall mean is recomputed as the mean of ALL
     * its ratings, as the reference does after summarize(). With three
     * questions in every dimension that is the same number as the mean of the
     * dimension means; it is computed the reference's way so that stays true if
     * a later version ever has uneven dimensions.
     *
     * @param array<string, array<string, array<int, int>>> $responses scope → code → index → rating
     * @return array{scores: array<string, array<string, mixed>>, difference: array<string, mixed>}
     */
    public function scorePair(array $responses): array
    {
        $scores = [];

        foreach (self::SCOPES as $scope) {
            $scoped  = $responses[$scope] ?? [];
            $dims    = $this->scoreDims($scoped);
            $summary = $this->summarize($dims);

            if ($summary['mean'] !== null) {
                $all = [];
                foreach ($this->dimensions as $dimension) {
                    for ($i = 0; $i < $dimension['items']; $i++) {
                        $all[] = $scoped[$dimension['code']][$i];
                    }
                }

                $summary['mean']  = (float) array_sum($all) / count($all);
                $summary['level'] = $this->bander->band($summary['mean']);
            }

            $scores[$scope] = ['dims' => $dims] + $summary;
        }

        $self       = $scores['self'];
        $leadership = $scores['leadership'];

        $dimDiff  = [];
        $itemDiff = [];

        foreach ($this->dimensions as $dimension) {
            $code = $dimension['code'];

            $dimDiff[$code] = $self['dims'][$code] === null || $leadership['dims'][$code] === null
                ? null
                : $self['dims'][$code] - $leadership['dims'][$code];

            $itemDiff[$code] = [];
            for ($i = 0; $i < $dimension['items']; $i++) {
                $a = $responses['self'][$code][$i] ?? null;
                $b = $responses['leadership'][$code][$i] ?? null;

                $itemDiff[$code][$i] = self::validRating($a) && self::validRating($b) ? $a - $b : null;
            }
        }

        return [
            'scores'     => $scores,
            'difference' => [
                'mean'  => $self['mean'] === null || $leadership['mean'] === null
                    ? null
                    : $self['mean'] - $leadership['mean'],
                'dims'  => $dimDiff,
                'items' => $itemDiff,
            ],
        ];
    }

    /** Every rating present and valid in both scopes — what submit() requires. */
    public function isComplete(array $responses): bool
    {
        foreach (self::SCOPES as $scope) {
            foreach ($this->dimensions as $dimension) {
                for ($i = 0; $i < $dimension['items']; $i++) {
                    if (!self::validRating($responses[$scope][$dimension['code']][$i] ?? null)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /** How many ratings a complete questionnaire holds: 24 on v1.0. */
    public function ratingsPerQuestionnaire(): int
    {
        return count(self::SCOPES) * array_sum(array_column($this->dimensions, 'items'));
    }
}
