<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services\Scoring;

use Apps\AiExecutiveReadinessScorecard\Services\InstrumentData;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * The only thing allowed to write a score.
 *
 * The browser posts ratings. This class reads them back out of the database,
 * scores both profiles with PairedScoring and writes the result. In the
 * reference build the browser computed its own record — both profiles, the
 * levels and the differences — and stored it, which meant any client could
 * assert any score; that is the hole this closes.
 *
 * Everything it derives is also a cache — re-running it on the same ratings
 * must produce the same rows, so a bad deploy can always be corrected by
 * rescoring.
 */
final class Scorer
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    /**
     * Score a submission and persist the result.
     *
     * @return array{scores: array<string, array<string, mixed>>, difference: array<string, mixed>}
     */
    public function score(int $submissionId): array
    {
        $submission = Database::selectOne(
            sprintf('SELECT `id`, `instrument_id` FROM `%s` WHERE `id` = ?', $this->app->table('submissions')),
            [$submissionId]
        );

        if ($submission === null) {
            throw new RuntimeException("Submission {$submissionId} does not exist.");
        }

        $instrumentId = (int) $submission['instrument_id'];
        $data         = new InstrumentData($this->app);
        $scoring      = $data->scoring($instrumentId);
        $result       = $scoring->scorePair($this->responses($submissionId, $instrumentId));

        $dimensionIds = [];
        foreach ($data->dimensions($instrumentId) as $dimension) {
            $dimensionIds[(string) $dimension['code']] = (int) $dimension['id'];
        }

        Database::transaction(function () use ($submissionId, $result, $dimensionIds, $scoring): void {
            // Rewritten wholesale rather than merged: a dimension cleared back
            // to unscored must lose its row, not keep a stale one.
            Database::statement(
                sprintf('DELETE FROM `%s` WHERE `submission_id` = ?', $this->app->table('submission_scores')),
                [$submissionId]
            );

            foreach ($result['scores'] as $scope => $profile) {
                foreach ($profile['dims'] as $code => $score) {
                    if ($score === null) {
                        continue;
                    }

                    Database::insert($this->app->table('submission_scores'), [
                        'submission_id' => $submissionId,
                        'dimension_id'  => $dimensionIds[$code],
                        'scope'         => $scope,
                        'score'         => round($score, 4),
                        'rung_value'    => $scoring->bander()->value($score),
                    ]);
                }
            }

            $self       = $result['scores']['self'];
            $leadership = $result['scores']['leadership'];

            Database::statement(sprintf(
                'UPDATE `%s`
                    SET `self_mean` = ?, `self_level` = ?, `self_floor` = ?, `self_floor_dimension_id` = ?,
                        `leadership_mean` = ?, `leadership_level` = ?, `leadership_floor` = ?,
                        `leadership_floor_dimension_id` = ?, `difference_mean` = ?, `updated_at` = ?
                  WHERE `id` = ?',
                $this->app->table('submissions')
            ), [
                self::round($self['mean']),
                $self['level']['value'] ?? null,
                self::round($self['floor']),
                $self['floorCode'] === null ? null : $dimensionIds[$self['floorCode']],
                self::round($leadership['mean']),
                $leadership['level']['value'] ?? null,
                self::round($leadership['floor']),
                $leadership['floorCode'] === null ? null : $dimensionIds[$leadership['floorCode']],
                self::round($result['difference']['mean']),
                date('Y-m-d H:i:s'),
                $submissionId,
            ]);
        });

        return $result;
    }

    /**
     * A submission's ratings in the reference's shape: scope → code → index.
     *
     * @return array<string, array<string, array<int, int>>>
     */
    public function responses(int $submissionId, int $instrumentId): array
    {
        $index     = (new InstrumentData($this->app))->itemIndex($instrumentId);
        $responses = ['self' => [], 'leadership' => []];

        foreach (Database::select(
            sprintf('SELECT `item_id`, `scope`, `mark` FROM `%s` WHERE `submission_id` = ?',
                $this->app->table('submission_responses')),
            [$submissionId]
        ) as $row) {
            if (!isset($index[(int) $row['item_id']])) {
                continue;
            }

            [$code, $i] = $index[(int) $row['item_id']];
            $responses[(string) $row['scope']][$code][$i] = (int) $row['mark'];
        }

        return $responses;
    }

    /** Stored to four places: exact for any mean of twelve 1–5 ratings to the display precision. */
    private static function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }
}
