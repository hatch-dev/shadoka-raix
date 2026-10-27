<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services;

use Apps\AiExecutiveReadinessScorecard\Services\Scoring\PairedScoring;
use Platform\Assessment\Bander;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * An instrument version's structure, read once per request.
 *
 * Every service that turns ratings into scores needs the same three things —
 * the dimensions in order, the items in each, and the rungs — and a request that
 * loads fifty records must not re-read them fifty times. Keyed by instrument id,
 * because a submission is scored against the version it was taken under, not
 * whatever is published today.
 */
final class InstrumentData
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public function __construct(private readonly AppManifest $app)
    {
    }

    /** @return array<string, mixed> the published instrument row */
    public function published(): array
    {
        $key = $this->app->slug . ':published';

        if (!isset(self::$cache[$key])) {
            $row = Database::selectOne(sprintf(
                'SELECT * FROM `%s` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
                $this->app->table('instruments')
            ), ['published']);

            if ($row === null) {
                throw new RuntimeException('No published instrument.');
            }

            self::$cache[$key] = $row;
        }

        return self::$cache[$key];
    }

    /** @return array<string, mixed> one instrument row by id */
    public function instrument(int $instrumentId): array
    {
        return $this->load($instrumentId)['instrument'];
    }

    /**
     * Dimensions in questionnaire order, each with its items in order.
     *
     * @return list<array<string, mixed>>
     */
    public function dimensions(int $instrumentId): array
    {
        return $this->load($instrumentId)['dimensions'];
    }

    /** @return list<array<string, mixed>> rung rows, lowest first */
    public function rungs(int $instrumentId): array
    {
        return $this->load($instrumentId)['rungs'];
    }

    public function bander(int $instrumentId): Bander
    {
        return $this->load($instrumentId)['bander'];
    }

    public function scoring(int $instrumentId): PairedScoring
    {
        return $this->load($instrumentId)['scoring'];
    }

    /**
     * item id → [code, zero-based index], for turning stored rows back into the
     * reference's `responses[scope][code][index]` shape.
     *
     * @return array<int, array{0:string, 1:int}>
     */
    public function itemIndex(int $instrumentId): array
    {
        return $this->load($instrumentId)['itemIndex'];
    }

    /** Forget everything read so far. Used by tests after reseeding. */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array<string, mixed> */
    private function load(int $instrumentId): array
    {
        $key = $this->app->slug . ':' . $instrumentId;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $instrument = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ?', $this->app->table('instruments')),
            [$instrumentId]
        );

        if ($instrument === null) {
            throw new RuntimeException("Instrument {$instrumentId} does not exist.");
        }

        $dimensions = Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->app->table('dimensions')
        ), [$instrumentId]);

        $items = Database::select(sprintf(
            'SELECT i.* FROM `%s` i
               JOIN `%s` d ON d.`id` = i.`dimension_id`
              WHERE d.`instrument_id` = ?
           ORDER BY d.`position`, i.`position`',
            $this->app->table('items'),
            $this->app->table('dimensions')
        ), [$instrumentId]);

        $byDimension = [];
        foreach ($items as $item) {
            $byDimension[(int) $item['dimension_id']][] = $item;
        }

        $itemIndex = [];
        foreach ($dimensions as &$dimension) {
            $dimension['items'] = $byDimension[(int) $dimension['id']] ?? [];

            foreach ($dimension['items'] as $index => $item) {
                $itemIndex[(int) $item['id']] = [(string) $dimension['code'], $index];
            }
        }
        unset($dimension);

        $rungs = Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`',
            $this->app->table('rungs')
        ), [$instrumentId]);

        $bander = new Bander($rungs);

        return self::$cache[$key] = [
            'instrument' => $instrument,
            'dimensions' => $dimensions,
            'rungs'      => $rungs,
            'bander'     => $bander,
            'itemIndex'  => $itemIndex,
            'scoring'    => new PairedScoring(
                array_map(
                    static fn (array $d): array => ['code' => (string) $d['code'], 'items' => count($d['items'])],
                    $dimensions
                ),
                $bander
            ),
        ];
    }
}
