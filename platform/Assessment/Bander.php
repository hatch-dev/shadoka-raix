<?php

declare(strict_types=1);

namespace Platform\Assessment;

use InvalidArgumentException;

/**
 * Turns a score into a rung, using the cut points stored on the instrument.
 *
 * The bands come from the database, not from config, because they are pinned to
 * a published instrument version. A submission taken under v1.0 keeps banding
 * the v1.0 way even after v2.0 moves a cut point — which is the whole reason
 * ds_rungs carries band_min/band_max at all.
 */
final class Bander
{
    /** @var list<array{value:int,name:string,description:string,colour:string,min:?float,max:?float}> */
    private array $rungs;

    /**
     * @param list<array<string,mixed>> $rungRows rows from the instrument's rungs table
     */
    public function __construct(array $rungRows)
    {
        if ($rungRows === []) {
            throw new InvalidArgumentException('An instrument must define at least one rung.');
        }

        $rungs = [];
        foreach ($rungRows as $row) {
            $rungs[] = [
                'value'       => (int) $row['value'],
                'name'        => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'colour'      => (string) ($row['colour_hex'] ?? ''),
                'min'         => isset($row['band_min']) && $row['band_min'] !== null ? (float) $row['band_min'] : null,
                'max'         => isset($row['band_max']) && $row['band_max'] !== null ? (float) $row['band_max'] : null,
            ];
        }

        usort($rungs, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        $this->rungs = $rungs;
    }

    /**
     * The rung a score falls in, or null when there is no score.
     *
     * Bands are [min, max) — inclusive lower, exclusive upper — so a score
     * exactly on a cut point lands in the HIGHER band. 2.75 is Practiced, not
     * Aware. Getting this backwards silently re-bands a slice of every dataset.
     *
     * @return array{value:int,name:string,description:string,colour:string,min:?float,max:?float}|null
     */
    public function band(?float $score): ?array
    {
        if ($score === null || is_nan($score)) {
            return null;
        }

        foreach ($this->rungs as $rung) {
            $aboveFloor = $rung['min'] === null || $score >= $rung['min'];
            $belowCeil  = $rung['max'] === null || $score < $rung['max'];

            if ($aboveFloor && $belowCeil) {
                return $rung;
            }
        }

        // Only reachable if an instrument's bands leave a gap — a data problem,
        // not a scoring one. Clamp rather than return null, so a result still
        // renders, and let the instrument validator be what complains.
        return $score < ($this->rungs[0]['max'] ?? INF)
            ? $this->rungs[0]
            : $this->rungs[count($this->rungs) - 1];
    }

    /** Just the rung value (1-4), or null. */
    public function value(?float $score): ?int
    {
        return $this->band($score)['value'] ?? null;
    }

    /** Just the rung name, or null. */
    public function name(?float $score): ?string
    {
        return $this->band($score)['name'] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function rungs(): array
    {
        return $this->rungs;
    }

    /**
     * True when the bands cover the whole scale with no gap or overlap.
     * Used by the instrument editor before a version may be published.
     */
    public function isContiguous(): bool
    {
        $previousMax = null;

        foreach ($this->rungs as $index => $rung) {
            if ($index === 0) {
                if ($rung['min'] !== null) {
                    return false;   // lowest band must be open-ended below
                }
            } elseif ($rung['min'] === null || $rung['min'] !== $previousMax) {
                return false;       // gap or overlap
            }

            $previousMax = $rung['max'];
        }

        return $previousMax === null;   // highest band must be open-ended above
    }
}
