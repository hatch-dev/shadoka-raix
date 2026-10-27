<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services\Synthetic;

use Apps\HumanEdgeScorecard\Services\Instrument;
use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Apps\HumanEdgeScorecard\Services\Records;
use Apps\HumanEdgeScorecard\Services\RecordStore;
use Apps\HumanEdgeScorecard\Services\Stats;
use Platform\Core\AppManifest;
use Platform\Core\Auth;
use Platform\Core\Database;
use RuntimeException;

/**
 * The Data seeder: the page's seedDataset() and applySeed(), ported.
 *
 * Every record it produces is flagged synthetic:true and is excluded from
 * benchmarks and default exports. Deterministic for a given seed: the page's
 * own generator (a 32-bit LCG, `s = s*1664525 + 1013904223`) is reproduced
 * exactly, call for call, so one seed and one set of options always give the
 * same organizations, people and answers.
 *
 * One deliberate difference: the page picks the respondents of a
 * several-respondent organization with `keys.sort(() => r() - .5)`, which
 * depends on the browser's sort algorithm. This uses a Fisher-Yates shuffle
 * driven by the same generator, so the result depends on the seed alone — the
 * same choice the other RAIX scorecards' seeders made.
 *
 * The records are written exactly as the page's seeder writes them: the whole
 * synthetic set in he_records is REPLACED (RecordStore::replaceSynthetic, the
 * B3 rule), real records are never touched, and the console's tables follow
 * through RecordMirror. The page and the console therefore always show one
 * demonstration set. Each run is recorded in he_seed_runs.
 *
 * The figures the generator invents (archetypes, sector priors, role biases,
 * AI hours) are the page's, read from seeds/data/instrument-v1.json, which was
 * extracted from the page rather than retyped.
 */
final class SeedGenerator
{
    /** The page's default demo set: applySeed({count:14, seed:20260908}). */
    public const DEFAULT_OPTIONS = ['count' => 14, 'seed' => 20260908];

    /** The rater surnames of the several-respondent path: the page's inline list, with its 'Sørensen'. */
    private const RATER_LAST = ['Whitfield', 'Menon', 'Natarajan', 'Reyes', 'Bello', 'Okafor', 'Lindqvist', 'Tanaka', 'Marchetti', 'Haddad', 'Sørensen', 'Zhang'];

    private int $state = 1;

    /** @var array<string, mixed> */
    private array $synthetic;

    private Instrument $instrument;

    private Stats $stats;

    public function __construct(private readonly AppManifest $app)
    {
        $this->instrument = new Instrument($app);
        $this->stats      = new Stats($this->instrument);
        $this->synthetic  = self::config($app);
    }

    /** The page's ARCHETYPES, SECTOR_PRIOR, RATER_BIAS and name lists. @return array<string, mixed> */
    public static function config(AppManifest $app): array
    {
        $file = $app->path('database/seeds/data/instrument-v1.json');
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return $data['synthetic'];
    }

    /** The archetypes and their default weights, in the page's order. @return array<string, array<string, mixed>> */
    public function archetypes(): array
    {
        return $this->synthetic['archetypes'];
    }

    /**
     * The options with the page's defaults filled in, and every value held to
     * what the form allows.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function normalise(array $options): array
    {
        $year = (int) date('Y');

        $mix = [];
        foreach ($this->archetypes() as $key => $archetype) {
            $mix[$key] = isset($options['archetypes'][$key]) ? max(0.0, (float) $options['archetypes'][$key]) : (float) $archetype['weight'];
        }

        $min = max(1, (int) ($options['raters']['min'] ?? 3));
        $max = max($min, (int) ($options['raters']['max'] ?? 5));

        return [
            'count'      => max(1, min(500, (int) ($options['count'] ?? 30))),
            'years'      => [$year],
            'raters'     => ['min' => $min, 'max' => $max],
            'boardShare' => max(0.0, min(1.0, (float) ($options['boardShare'] ?? .3))),
            'spread'     => max(0.0, min(1.0, (float) ($options['spread'] ?? .5))),
            'seed'       => (int) ($options['seed'] ?? 20260908),
            'archetypes' => $mix,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* The page's generator                                                 */
    /* ------------------------------------------------------------------ */

    /** rng(seed): `(seed >>> 0) || 1`. */
    private function seedWith(int $seed): void
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 1;
    }

    /** r(): `s = (s*1664525 + 1013904223) >>> 0; return s / 4294967296`. */
    private function r(): float
    {
        $this->state = ($this->state * 1664525 + 1013904223) & 0xFFFFFFFF;

        return $this->state / 4294967296;
    }

    /** pick(r, arr) */
    private function pick(array $values): mixed
    {
        $values = array_values($values);

        return $values[(int) floor($this->r() * count($values))];
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }

    /** gauss(r, mu, sig): an approximate normal from the sum of four uniforms. */
    private function gauss(float $mu = 0.0, float $sig = 1.0): float
    {
        $a = $this->r();
        $b = $this->r();
        $c = $this->r();
        $d = $this->r();

        return $mu + $sig * (($a + $b + $c + $d - 2) * sqrt(3));
    }

    /** synAI(r): hours a week using AI tools, skewed toward light use, up to 30. */
    private function synAI(): float|int
    {
        if ($this->r() < .08) {
            return 0;
        }

        return self::half(Stats::jsRound(($this->r() ** 1.6) * 60) / 2);
    }

    /** synEdu(r): hours of AI education in the last 12 months, up to 40. */
    private function synEdu(): float|int
    {
        if ($this->r() < .2) {
            return 0;
        }

        return self::half(Stats::jsRound(($this->r() ** 1.8) * 80) / 2);
    }

    /** A whole number stays an int, so it encodes as 7 and not 7.0, as in JavaScript. */
    private static function half(float|int $v): float|int
    {
        return floor((float) $v) == (float) $v ? (int) $v : (float) $v;
    }

    /** synthItems(): one 1–5 answer for each question of the skill. @return array<int, int> */
    private function synthItems(string $code, float $target, float $spread, bool $emerging): array
    {
        $resp = [];
        $dim  = $this->instrument->dimBy()[$code];

        for ($i = 0; $i < count($dim['qs']); $i++) {
            if ($emerging) {
                $v = $this->r() < .68 ? 2 : ($this->r() < .55 ? 1 : 3);
            } else {
                $v = Stats::jsRound(self::clamp($this->gauss($target, $spread * 0.7), 1, 5));
            }
            $resp[$i] = (int) $v;
        }

        return $resp;
    }

    /** slug(s) in synthPerson(): lower case, accents and punctuation removed, at most 18 characters. */
    private static function slug(?string $s): string
    {
        $s = Stats::norm($s === null || $s === '' ? 'org' : $s);
        $s = substr((string) preg_replace('/[^a-z0-9]+/', '', $s), 0, 18);

        return $s === '' ? 'org' : $s;
    }

    /** synthPerson(): a reserved .invalid address and a fictional 555-01xx number. @return array{name:string, email:string, phone:string} */
    private function synthPerson(string $org, ?string $name): array
    {
        $full  = $name ?? ($this->pick($this->synthetic['first_names']) . ' ' . $this->pick($this->synthetic['last_names']));
        $parts = preg_split('/\s+/', $full) ?: [$full];

        return [
            'name'  => $full,
            'email' => self::slug($parts[0]) . '.' . self::slug($parts[count($parts) - 1]) . '@' . self::slug($org) . '.example.invalid',
            'phone' => '+1 555 01' . str_pad((string) (int) floor($this->r() * 100), 2, '0', STR_PAD_LEFT),
        ];
    }

    /** synthRecord(): one synthetic scorecard in the page's record shape. @return array<string, mixed> */
    private function synthRecord(array $o): array
    {
        $person    = $this->synthPerson($o['org'], $o['rater'] ?? null);
        $responses = [];
        $artifacts = [];

        foreach ($this->instrument->dims() as $i => $dim) {
            $responses[$dim['code']] = $this->synthItems($dim['code'], $o['targets'][$i], $o['spread'], $o['emerging']);
            $artifacts[$dim['code']] = [];
        }

        $dims = [];
        foreach ($this->instrument->dims() as $dim) {
            $vals = array_values($responses[$dim['code']]);
            $dims[$dim['code']] = $vals === [] ? null : Stats::round2(Stats::mean($vals));
        }
        $s = $this->stats->summarize($dims);

        // new Date(Date.now() - daysAgo*86400000), its year forced to o.year
        // and walked back a year while that puts it in the future.
        $nowMs = (int) floor(microtime(true) * 1000);
        $ms    = $nowMs - $o['daysAgo'] * 86400000;
        $ts    = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $ts    = $ts->setDate($o['year'], (int) $ts->format('n'), (int) $ts->format('j'));
        while ($ts->getTimestamp() * 1000 + $ms % 1000 > $nowMs) {
            $ts = $ts->setDate((int) $ts->format('Y') - 1, (int) $ts->format('n'), (int) $ts->format('j'));
        }

        $id = 'syn-' . base_convert((string) (int) floor($this->r() * 1e12), 10, 36);

        return [
            'id'        => $id,
            'timestamp' => gmdate('Y-m-d\TH:i:s', $ts->getTimestamp()) . sprintf('.%03dZ', $ms % 1000),
            'year'      => $o['year'],
            'org'       => [
                'org' => $o['org'], 'name' => $person['name'], 'email' => $person['email'], 'phone' => $person['phone'],
                'role' => $o['role'], 'sector' => $o['sector'], 'size' => $o['size'], 'year' => $o['year'],
                'aiuse' => $o['aiuse'], 'edu' => $o['edu'], 'tenure' => $o['tenure'],
            ],
            'dims'      => (object) $dims,
            'mean'      => $s['mean'],
            'level'     => $s['level']['name'] ?? null,
            'responses' => (object) array_map(static fn (array $r): object => (object) $r, $responses),
            'artifacts' => (object) array_map(static fn (array $a): object => (object) $a, $artifacts),
            'synthetic' => true,
            'archetype' => $o['archetype'],
            'userName'  => $person['name'],
            'userEmail' => $person['email'],
            'userPhone' => $person['phone'],
        ];
    }

    /** chooseArchetype(r, mix) */
    private function chooseArchetype(array $mix): string
    {
        $keys  = array_keys($mix);
        $total = array_sum($mix);
        $x     = $this->r() * $total;

        foreach ($keys as $key) {
            $x -= $mix[$key];
            if ($x <= 0) {
                return $key;
            }
        }

        return $keys[count($keys) - 1];
    }

    /**
     * seedDataset(opts): the records, nothing written.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function records(array $options): array
    {
        $O = $this->normalise($options);
        $this->seedWith($O['seed']);

        $archetypes = $this->archetypes();
        $sectors    = $this->instrument->options('sectors');
        $sizes      = $this->instrument->options('organisation_sizes');
        $roles      = $this->instrument->options('roles');
        $tenures    = $this->instrument->options('tenures');
        $priors     = $this->synthetic['sector_prior'];
        $bias       = $this->synthetic['role_bias'];
        $prefixes   = $this->synthetic['org_prefix'];
        $suffixes   = $this->synthetic['org_suffix'];
        $firstNames = $this->synthetic['first_names'];

        $records = [];
        $used    = [];

        for ($n = 0; $n < $O['count']; $n++) {
            $sector = $this->pick($sectors);
            $size   = $this->pick($sizes);

            $tries = 0;
            do {
                $name = $this->pick($prefixes) . ' ' . $this->pick($suffixes[$sector] ?? $suffixes['Other']);
                if (++$tries > 10000) {
                    throw new RuntimeException('Ran out of distinct organization names. Ask for fewer organizations.');
                }
            } while (isset($used[$name]));
            $used[$name] = true;

            $arch  = $this->chooseArchetype($O['archetypes']);
            $A     = $archetypes[$arch];
            $prior = $priors[$sector] ?? $priors['Other'];
            $base  = [];
            foreach ($A['targets'] as $i => $t) {
                $base[] = self::clamp($t + ($prior[$i] ?? 0) + $this->gauss(0, .15), 1, 5);
            }

            $year    = $O['years'][count($O['years']) - 1];   // one research wave
            $several = $this->r() < $O['boardShare'];
            $targets = $base;
            $daysAgo = (int) floor($this->r() * 300 + 10);

            if ($several) {
                $k = (int) floor($this->r() * ($O['raters']['max'] - $O['raters']['min'] + 1)) + $O['raters']['min'];

                // Fisher-Yates over the role-bias keys (see the class comment).
                $keys = array_keys($bias);
                for ($i = count($keys) - 1; $i > 0; $i--) {
                    $j = (int) floor($this->r() * ($i + 1));
                    [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
                }

                foreach (array_slice($keys, 0, $k) as $ri => $role) {
                    $roleBias = $bias[$role];
                    $rater    = $this->pick($firstNames) . ' ' . $this->pick(self::RATER_LAST);

                    $raterTargets = [];
                    foreach ($targets as $i => $t) {
                        $raterTargets[] = self::clamp($t + $roleBias[$i] * (1 + 2 * $O['spread']) + $this->gauss(0, $O['spread'] * .45), 1, 5);
                    }

                    $records[] = $this->synthRecord([
                        'org' => $name, 'sector' => $sector, 'size' => $size, 'role' => $role, 'targets' => $raterTargets,
                        'year' => $year, 'daysAgo' => $daysAgo - $ri, 'rater' => $rater,
                        'aiuse' => $this->synAI(), 'edu' => $this->synEdu(), 'tenure' => $this->pick($tenures),
                        'spread' => $O['spread'], 'emerging' => (bool) ($A['emerging'] ?? false), 'archetype' => $arch,
                    ]);
                }
            } else {
                $role = $this->pick(array_values(array_filter($roles, static fn (string $r): bool => $r !== 'Other')));

                $records[] = $this->synthRecord([
                    'org' => $name, 'sector' => $sector, 'size' => $size, 'role' => $role, 'targets' => $targets,
                    'year' => $year, 'daysAgo' => $daysAgo,
                    'aiuse' => $this->synAI(), 'edu' => $this->synEdu(), 'tenure' => $this->pick($tenures),
                    'spread' => $O['spread'] * .6, 'emerging' => (bool) ($A['emerging'] ?? false), 'archetype' => $arch,
                ]);
            }
        }

        return $records;
    }

    /* ------------------------------------------------------------------ */
    /* applySeed(): written, replacing the synthetic set                     */
    /* ------------------------------------------------------------------ */

    /**
     * Generate, record the run, and replace the synthetic set with it.
     *
     * @param array<string, mixed> $options
     * @return array{records:int, orgs:int, run:int}
     */
    public function generate(array $options): array
    {
        $O       = $this->normalise($options);
        $records = $this->records($O);
        $orgs    = count(array_unique(array_map(static fn (array $r): string => (string) $r['org']['org'], $records)));

        $runId = Database::insert($this->app->table('seed_runs'), [
            'seed'                  => $O['seed'],
            'options'               => json_encode($O, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'records_created'       => min(65535, count($records)),
            'organisations_created' => min(65535, $orgs),
            'instrument_id'         => $this->instrument->id(),
            'created_by'            => Auth::id(),
            'created_at'            => date('Y-m-d H:i:s'),
        ]);

        $result = (new RecordStore($this->app))->replaceSynthetic(
            json_encode(['records' => $records], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            $runId
        );

        if ($result['status'] !== 200) {
            Database::statement(sprintf('DELETE FROM `%s` WHERE `id` = ?', $this->app->table('seed_runs')), [$runId]);
            throw new RuntimeException((string) $result['error']);
        }

        RecordMirror::pruneOrganisations();
        Records::flush();

        return ['records' => count($records), 'orgs' => $orgs, 'run' => $runId];
    }

    /**
     * Delete every synthetic record (B4). Real records are never touched.
     *
     * @return int how many were removed
     */
    public static function purge(AppManifest $app): int
    {
        $removed = (new RecordStore($app))->deleteSynthetic();

        RecordMirror::pruneOrganisations();
        Records::flush();

        return $removed;
    }
}
