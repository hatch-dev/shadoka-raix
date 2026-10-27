<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services\Synthetic;

use RuntimeException;

/**
 * The shapes a synthetic organisation can take, read out of the instrument JSON.
 *
 * Unlike the sibling app, which hard-codes its archetype table in PHP, every
 * figure here is loaded from database/seeds/data/instrument-v1.json — the same
 * file the instrument itself is seeded from, dumped out of the reference build.
 * The generator's tables are instrument data too: `fluent` scoring 4.3 on AI
 * fluency and 2.6 on structure is a claim about what real leadership teams look
 * like, and retyping twenty-four target numbers is exactly how one of them ends
 * up a tenth out with nothing to catch it.
 *
 * Target order matches instrument position: JDG FLU STR CHG. The targets are
 * the LEADERS' ratings; SeedGenerator derives each respondent's self-ratings
 * from them with a personal offset.
 */
final class Archetypes
{
    /** @var array<string, mixed>|null the synthetic block, loaded once */
    private static ?array $data = null;

    private function __construct()
    {
    }

    /** @return array<string, array{weight:float, targets:list<float>, emerging:bool, note:string}> */
    public static function definitions(): array
    {
        return array_map(
            static fn (array $a): array => [
                'weight'   => (float) $a['weight'],
                'targets'  => array_map('floatval', $a['targets']),
                'emerging' => (bool) ($a['emerging'] ?? false),
                'note'     => (string) $a['note'],
            ],
            self::load()['archetypes']
        );
    }

    /** Per-dimension shifts applied on top of an archetype's targets. @return array<string, list<float>> */
    public static function sectorPrior(): array
    {
        return array_map(
            static fn (array $shifts): array => array_map('floatval', $shifts),
            self::load()['sector_prior']
        );
    }

    /**
     * What each role tends to see, and to overrate, across the four.
     *
     * Only ten of the instrument's fifteen roles appear: the reference gave a
     * bias vector to the ones whose view of an organisation is predictable, and
     * used that same set as the pool for a multi-respondent organisation.
     *
     * @return array<string, list<float>>
     */
    public static function roleBias(): array
    {
        return array_map(
            static fn (array $shifts): array => array_map('floatval', $shifts),
            self::load()['role_bias']
        );
    }

    /** @return list<string> */
    public static function orgPrefix(): array
    {
        return self::load()['org_prefix'];
    }

    /** Sector-appropriate second halves of a generated organisation name. @return array<string, list<string>> */
    public static function orgSuffix(): array
    {
        return self::load()['org_suffix'];
    }

    /** @return list<string> */
    public static function firstNames(): array
    {
        return self::load()['first_names'];
    }

    /** @return list<string> */
    public static function lastNames(): array
    {
        return self::load()['last_names'];
    }

    /** @return list<string> */
    public static function sectors(): array
    {
        return self::instrument()['reference']['sectors'];
    }

    /** @return list<string> */
    public static function sizes(): array
    {
        return self::instrument()['reference']['organisation_sizes'];
    }

    /** @return list<string> */
    public static function tenures(): array
    {
        return self::instrument()['reference']['tenures'];
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return self::instrument()['reference']['roles'];
    }

    /** @return array<string, mixed> */
    private static function load(): array
    {
        return self::instrument()['synthetic'];
    }

    /** @return array<string, mixed> */
    private static function instrument(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $file = dirname(__DIR__, 2) . '/database/seeds/data/instrument-v1.json';

        if (!is_file($file)) {
            throw new RuntimeException('The instrument data file is missing; the seeder cannot run without it.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return self::$data = $data;
    }

    /** Forget the cached file. Used by tests. */
    public static function flush(): void
    {
        self::$data = null;
    }
}
