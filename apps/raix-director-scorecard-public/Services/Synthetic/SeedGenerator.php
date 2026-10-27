<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services\Synthetic;

use Apps\RaixDirectorScorecardPublic\Services\Scoring\Scorer;
use Platform\Assessment\OrganisationResolver;
use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * Generates flagged demo scorecards, for showing the analytics with something
 * in them before real directors have scored.
 *
 * Two defects in the legacy generator are designed out here, and one of its
 * choices is kept on purpose:
 *
 *  1. KEPT, at the user's request (25 Sep 2026): each synthetic director's
 *     "meetings with AI as a decision item" and "hours of AI education" are
 *     derived from that record's archetype target, exactly as the reference's
 *     synthRecord() does, so the Correlation tab has points and the trend the
 *     reference shows. That trend is put there by the generator; it is not a
 *     finding. Board seats are independent noise.
 *
 *  2. It timestamped records with `Date.now() - daysAgo` and then forced the
 *     year, which rolled roughly one record in ten into the future. Dates here
 *     are drawn inside the target year and clamped to today.
 *
 *  3. It shuffled roles with `sort(() => rng() - .5)`, an inconsistent
 *     comparator whose call count is engine-dependent — so "deterministic for a
 *     given seed" held only within one JS engine. This uses a Fisher-Yates
 *     shuffle, which consumes an exactly predictable number of draws.
 *
 * Every record is written through the real Scorer, so a demo dataset cannot
 * contain a score the scoring rules could not produce.
 */
final class SeedGenerator
{
    /** Deterministic PRNG. Same seed, same dataset, on any machine. */
    private int $state;

    private int $drawCount = 0;

    public function __construct(
        private readonly AppManifest $app,
        private readonly int $seed,
    ) {
        $this->state = ($seed & 0x7FFFFFFF) ?: 1;
    }

    /**
     * @param array{count?:int, raters?:array{min:int,max:int}, board_share?:float,
     *              spread?:float, years?:list<int>, archetypes?:array<string,float>} $options
     * @return array{records:int, organisations:int, seed_run_id:int}
     */
    public function generate(array $options = [], ?int $createdBy = null): array
    {
        $options = $this->normalise($options);
        $instrument = $this->publishedInstrument();

        $competencies = Database::select(sprintf(
            'SELECT `id`, `code`, `position` FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->app->table('competencies')
        ), [$instrument['id']]);

        $itemsByCompetency = [];
        foreach ($competencies as $competency) {
            $itemsByCompetency[$competency['code']] = array_column(Database::select(sprintf(
                'SELECT `id` FROM `%s` WHERE `competency_id` = ? ORDER BY `position`',
                $this->app->table('items')
            ), [$competency['id']]), 'id');
        }

        $seedRunId = Database::insert($this->app->table('seed_runs'), [
            'seed'          => $this->seed,
            'options'       => json_encode($options, JSON_THROW_ON_ERROR),
            'instrument_id' => $instrument['id'],
            'created_by'    => $createdBy,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $usedNames     = [];
        $records       = 0;
        $organisations = [];

        for ($b = 0; $b < $options['count']; $b++) {
            $sector = $this->pick(Archetypes::SECTORS);
            $size   = $this->pick(Archetypes::SIZES);

            do {
                $name = $this->pick(Archetypes::ORG_PREFIX) . ' ' . $this->pick(Archetypes::ORG_SUFFIX[$sector]);
            } while (isset($usedNames[$name]));
            $usedNames[$name] = true;

            $organisationId = OrganisationResolver::resolve($name, $sector, $size, true);
            $organisations[$organisationId] = true;

            $archetype = $this->chooseArchetype($options['archetypes']);
            $targets   = $this->targets($archetype, $sector, $competencies);

            // How many directors of this board scored themselves.
            $raters = $this->chance($options['board_share'])
                ? $this->intBetween($options['raters']['min'], $options['raters']['max'])
                : 1;

            $roles = $this->shuffle(array_keys(Archetypes::ROLE_BIAS));

            for ($r = 0; $r < $raters; $r++) {
                $role = $roles[$r % count($roles)];

                $this->writeRecord(
                    instrumentId: (int) $instrument['id'],
                    organisationId: $organisationId,
                    boardName: $name,
                    sector: $sector,
                    size: $size,
                    seedRunId: $seedRunId,
                    year: $this->pick($options['years']),
                    role: $role,
                    archetype: $archetype,
                    targets: $this->applyRoleBias($targets, $role, $competencies, $options['spread']),
                    spread: $options['spread'],
                    stated: Archetypes::DEFINITIONS[$archetype]['stated'] ?? false,
                    itemsByCompetency: $itemsByCompetency,
                    competencies: $competencies,
                );

                $records++;
            }
        }

        Database::statement(sprintf(
            'UPDATE `%s` SET `records_created` = ?, `organisations_created` = ? WHERE `id` = ?',
            $this->app->table('seed_runs')
        ), [$records, count($organisations), $seedRunId]);

        // Each demo row gets a record in dsp_records, so the v14 page's admin
        // views show the same demo set as the console. See Services/RecordMirror.
        (new \Apps\RaixDirectorScorecardPublic\Services\RecordMirror($this->app))->adoptSynthetic($seedRunId);

        return [
            'records'       => $records,
            'organisations' => count($organisations),
            'seed_run_id'   => $seedRunId,
        ];
    }

    /* ------------------------------------------------------------------ */

    private function writeRecord(
        int $instrumentId,
        ?int $organisationId,
        string $boardName,
        string $sector,
        string $size,
        int $seedRunId,
        int $year,
        string $role,
        string $archetype,
        array $targets,
        float $spread,
        bool $stated,
        array $itemsByCompetency,
        array $competencies,
    ): void {
        $submittedAt = $this->dateWithin($year);
        $person      = $this->person($boardName);

        /*
         * The three optional figures, as the reference's synthRecord() makes
         * them — chosen on 25 Sep 2026 so the Correlation tab has points to
         * plot. Seats are noise; meetings and hours are "loosely tied to the
         * archetype target so the scatter is not pure noise", in the
         * reference's words. That tie means demo records show an association
         * the generator put there. Real records get these only from the form.
         */
        $clamp  = static fn (float $v, float $lo, float $hi): float => max($lo, min($hi, $v));
        $tm     = array_sum($targets) / max(1, count($targets));
        $seats  = (int) round($clamp($this->gauss(9, 2), 5, 15));
        $agenda = (int) round($clamp($this->gauss(($tm - 1) * 1.2, 0.9), 0, 6));
        $edu    = round($clamp($this->gauss(($tm - 1) * 4, 3), 0, 24) * 2) / 2;

        $submissionId = Database::insert($this->app->table('submissions'), [
            'public_token'    => bin2hex(random_bytes(16)),
            'instrument_id'   => $instrumentId,
            'organisation_id' => $organisationId,
            'director_name'   => $person['name'],
            'director_email'  => $person['email'],
            'director_phone'  => $person['phone'],
            'board_name'      => $boardName,
            'sector'          => $sector,
            'company_size'    => $size,
            'seat_role'       => $role,
            'tenure'          => $this->pick(Archetypes::TENURES),
            'assessment_year' => $year,
            'systems_seen'    => $this->intBetween(3, 62),
            'board_seats'     => $seats,
            'agenda_meetings' => $agenda,
            'education_hours' => $edu,

            'status'          => 'complete',
            'is_synthetic'    => 1,
            'archetype'       => $archetype,
            'seed_run_id'     => $seedRunId,
            'submitted_at'    => $submittedAt,
            'created_at'      => $submittedAt,
        ]);

        $now = date('Y-m-d H:i:s');

        foreach ($competencies as $index => $competency) {
            $target = $targets[$index];

            foreach ($itemsByCompetency[$competency['code']] as $itemId) {
                Database::insert($this->app->table('submission_responses'), [
                    'submission_id' => $submissionId,
                    'item_id'       => $itemId,
                    'mark'          => $this->mark($target, $spread, $stated),
                    'answered_at'   => $now,
                ]);
            }
        }

        // Scored by the real Scorer, so a demo record can never hold a score
        // the scoring rules could not have produced.
        (new Scorer($this->app))->score($submissionId);
    }

    /** One item mark, 1–4. */
    /**
     * A synthetic identity, in the shape real records now require.
     *
     * Deliberately unreachable, as in the reference's synthPerson(): the
     * `.invalid` TLD is reserved, so the address can never be delivered, and
     * 555-0100 to 555-0199 is the range set aside for fiction. A seeded row can
     * never be mistaken for a director someone could contact — and the email
     * rule refuses `.invalid`, so no real submission can look like one either.
     *
     * @return array{name:string, email:string, phone:string}
     */
    private function person(string $boardName): array
    {
        $first = $this->pick(Archetypes::FIRST_NAMES);
        $last  = $this->pick(Archetypes::LAST_NAMES);

        $slug = static function (string $value): string {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
            $clean = substr((string) preg_replace('/[^a-z0-9]+/', '', strtolower($ascii)), 0, 18);

            return $clean !== '' ? $clean : 'org';
        };

        return [
            'name'  => $first . ' ' . $last,
            'email' => $slug($first) . '.' . $slug($last) . '@' . $slug($boardName) . '.example.invalid',
            'phone' => '+1 555 01' . str_pad((string) $this->intBetween(0, 99), 2, '0', STR_PAD_LEFT),
        ];
    }

    private function mark(float $target, float $spread, bool $stated): int
    {
        if ($stated) {
            // The paper tiger: understands the value, has acted on none of it.
            $roll = $this->next();

            return $roll < 0.72 ? 2 : ($this->next() < 0.6 ? 1 : 3);
        }

        return (int) max(1, min(4, (int) round($this->gauss($target, $spread * 0.7))));
    }

    /** @return list<float> per-competency targets for an archetype in a sector */
    private function targets(string $archetype, string $sector, array $competencies): array
    {
        $base  = Archetypes::DEFINITIONS[$archetype]['targets'];
        $prior = Archetypes::SECTOR_PRIOR[$sector] ?? array_fill(0, count($competencies), 0.0);

        $targets = [];
        foreach ($competencies as $index => $competency) {
            $targets[] = max(1.0, min(4.0,
                ($base[$index] ?? 2.5) + ($prior[$index] ?? 0.0) + $this->gauss(0, 0.15)
            ));
        }

        return $targets;
    }

    /** @return list<float> */
    private function applyRoleBias(array $targets, string $role, array $competencies, float $spread): array
    {
        $bias = Archetypes::ROLE_BIAS[$role] ?? array_fill(0, count($competencies), 0.0);

        $out = [];
        foreach ($targets as $index => $target) {
            $out[] = max(1.0, min(4.0,
                $target + ($bias[$index] ?? 0.0) + $this->gauss(0, $spread * 0.45)
            ));
        }

        return $out;
    }

    /**
     * A datetime inside the target year, never in the future.
     *
     * The legacy generator subtracted days from "now" and then forced the year,
     * which pushed about one record in ten past today's date.
     */
    private function dateWithin(int $year): string
    {
        $start = mktime(9, 0, 0, 1, 1, $year);
        $end   = min(mktime(17, 0, 0, 12, 31, $year), time());

        if ($end <= $start) {
            $end = $start;
        }

        return date('Y-m-d H:i:s', $start + (int) floor($this->next() * ($end - $start)));
    }

    /* ---------------------------------------------------------- randomness -- */

    /**
     * Linear congruential generator, matching the legacy constants.
     *
     * Deliberately not mt_rand(): this must produce the same dataset from the
     * same seed on any PHP build, which a seeded global generator does not
     * guarantee across versions.
     */
    private function next(): float
    {
        $this->drawCount++;
        $this->state = ($this->state * 1664525 + 1013904223) & 0xFFFFFFFF;

        return $this->state / 4294967296;
    }

    /** Approximately normal, from the sum of four uniforms. */
    private function gauss(float $mean = 0.0, float $sd = 1.0): float
    {
        $sum = $this->next() + $this->next() + $this->next() + $this->next() - 2;

        return $mean + $sd * ($sum * sqrt(3));
    }

    private function pick(array $values): mixed
    {
        $values = array_values($values);

        return $values[(int) floor($this->next() * count($values))] ?? $values[0];
    }

    private function intBetween(int $low, int $high): int
    {
        return $low + (int) floor($this->next() * ($high - $low + 1));
    }

    private function chance(float $probability): bool
    {
        return $this->next() < $probability;
    }

    /**
     * Fisher-Yates.
     *
     * The legacy build used `sort(() => rng() - .5)`, an inconsistent
     * comparator. V8 calls such a comparator a data-dependent number of times,
     * so the PRNG stream desynchronised and the same seed produced 32 records
     * in Node and 38 in Chrome. This consumes exactly n-1 draws, always.
     */
    private function shuffle(array $values): array
    {
        $values = array_values($values);

        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = (int) floor($this->next() * ($i + 1));
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }

    private function chooseArchetype(array $weights): string
    {
        $total = array_sum($weights);
        $roll  = $this->next() * $total;

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** How many PRNG draws this run consumed. Used by the determinism test. */
    public function draws(): int
    {
        return $this->drawCount;
    }

    /* -------------------------------------------------------------- setup -- */

    private function normalise(array $options): array
    {
        $year = (int) date('Y');

        $archetypes = $options['archetypes'] ?? array_map(
            static fn (array $a): float => $a['weight'],
            Archetypes::DEFINITIONS
        );

        $archetypes = array_filter($archetypes, static fn (float $w): bool => $w > 0);

        if ($archetypes === []) {
            $archetypes = ['engaged' => 1.0];
        }

        return [
            // 500 is the form's own maximum; a lower clamp here would silently
            // hand back fewer boards than the screen said it would make.
            'count'       => max(1, min(500, (int) ($options['count'] ?? 14))),
            'raters'      => [
                'min' => max(1, (int) ($options['raters']['min'] ?? 3)),
                'max' => max(1, (int) ($options['raters']['max'] ?? 5)),
            ],
            'board_share' => max(0.0, min(1.0, (float) ($options['board_share'] ?? 0.35))),
            'spread'      => max(0.1, min(1.2, (float) ($options['spread'] ?? 0.5))),
            'years'       => $options['years'] ?? [$year - 2, $year - 1, $year],
            'archetypes'  => $archetypes,
        ];
    }

    /** @return array<string, mixed> */
    private function publishedInstrument(): array
    {
        $instrument = Database::selectOne(sprintf(
            'SELECT * FROM `%s` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
            $this->app->table('instruments')
        ), ['published']);

        if ($instrument === null) {
            throw new \RuntimeException('No published instrument to generate against.');
        }

        return $instrument;
    }

    /* ------------------------------------------------------------ removal -- */

    /** Delete every synthetic record, or just one run's. Real ones are untouched. */
    public static function purge(AppManifest $app, ?int $seedRunId = null): int
    {
        $table = $app->table('submissions');

        // The demo records these rows mirror (dsp_records) go first, or the
        // page would keep showing them. See Services/RecordMirror.
        (new \Apps\RaixDirectorScorecardPublic\Services\RecordMirror($app))->forgetSynthetic($seedRunId);

        if ($seedRunId !== null) {
            $removed = Database::statement(
                sprintf('DELETE FROM `%s` WHERE `is_synthetic` = 1 AND `seed_run_id` = ?', $table),
                [$seedRunId]
            );
        } else {
            $removed = Database::statement(sprintf('DELETE FROM `%s` WHERE `is_synthetic` = 1', $table));
        }

        self::pruneOrganisations();

        return $removed;
    }

    /** Delete the demo boards that no app's submissions reference any more. */
    public static function pruneOrganisations(): void
    {
        /*
         * Demo boards that nothing points at any more. A real board is never
         * touched, even if a synthetic director happened to score against it.
         *
         * `organisations` is shared by every app, so "nothing" has to mean
         * nothing in ANY app's submissions. Checking only this app's table
         * deleted boards the other apps' demo sets still used, and their
         * ON DELETE SET NULL links went blank with no error anywhere.
         */
        $referenced = [];
        foreach (\Platform\Core\AppRegistry::all() as $other) {
            $candidate = $other->table('submissions');

            if (Database::hasTable($candidate)) {
                $referenced[] = sprintf(
                    'SELECT DISTINCT `organisation_id` FROM `%s` WHERE `organisation_id` IS NOT NULL',
                    $candidate
                );
            }
        }

        if ($referenced !== []) {
            Database::statement(sprintf(
                'DELETE FROM `organisations`
                  WHERE `is_synthetic` = 1
                    AND `id` NOT IN (%s)',
                implode(' UNION ', $referenced)
            ));
        }
    }
}
