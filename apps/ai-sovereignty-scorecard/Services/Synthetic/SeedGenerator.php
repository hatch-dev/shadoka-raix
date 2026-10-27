<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Services\Synthetic;

use Apps\AiSovereigntyScorecard\Services\Scoring\Scorer;
use Platform\Assessment\OrganisationResolver;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * Generates flagged demo scorecards, so the analytics screens can be shown with
 * something in them before real organisations have scored.
 *
 * Every record it writes is `is_synthetic = 1`, is excluded from benchmarks,
 * and is left out of analytics (correlations included) and exports unless the
 * include-synthetic toggle is on. It is also written through the real Scorer, so
 * a demo dataset cannot contain a score the scoring rules could not produce.
 *
 * Three defects in the reference generator are designed out here rather than
 * avoided by accident:
 *
 *  1. It shuffled roles with `sort(() => r() - .5)`, an inconsistent comparator.
 *     V8 calls such a comparator a data-dependent number of times, so the PRNG
 *     stream desynchronised and "deterministic for a given seed" held only
 *     within one JavaScript engine. This uses Fisher-Yates, which consumes
 *     exactly n-1 draws, always.
 *
 *  2. It generated an item mark of `clamp(gauss(target, spread), 1, 5)` but
 *     clamped its archetype TARGETS to 4 (`clamp(t + prior + gauss, 1, 4)`), so
 *     no synthetic organisation could ever reach Embedded however
 *     institutionalized its archetype claimed to be — the top level was empty in
 *     every demo dataset. Targets are clamped to the full 1–5 scale here.
 *
 *  3. It set a timestamp from `Date.now() - daysAgo` and then forced the year,
 *     which could push a row past today; it patched that with a loop that walked
 *     the year back, silently moving the record into a year it was not generated
 *     for. Dates here are drawn INSIDE the target year and clamped to now.
 *
 * And one restraint carried over deliberately: `agenda_meetings`,
 * `education_hours` and `systems_inventory` reported figures are NOT derived from
 * a record's own score. The reference left them absent, so the correlation panes
 * honestly showed that nobody had given a number. Deriving them would make those
 * panes report a relationship this generator had manufactured.
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
     * @param array{count?:int, respondents?:array{min:int,max:int}, multi_share?:float,
     *              spread?:float, years?:list<int>, drift?:float,
     *              archetypes?:array<string,float>} $options
     * @return array{records:int, organisations:int, seed_run_id:int}
     */
    public function generate(array $options = [], ?int $createdBy = null): array
    {
        $options    = $this->normalise($options);
        $instrument = $this->publishedInstrument();

        $dimensions = Database::select(sprintf(
            'SELECT `id`, `code`, `position` FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->app->table('dimensions')
        ), [$instrument['id']]);

        if ($dimensions === []) {
            throw new RuntimeException('The published instrument has no dimensions to generate against.');
        }

        $itemsByDimension = [];
        foreach ($dimensions as $dimension) {
            $itemsByDimension[$dimension['code']] = array_column(Database::select(sprintf(
                'SELECT `id` FROM `%s` WHERE `dimension_id` = ? ORDER BY `position`',
                $this->app->table('items')
            ), [$dimension['id']]), 'id');
        }

        $seedRunId = Database::insert($this->app->table('seed_runs'), [
            'seed'          => $this->seed,
            'options'       => json_encode($options, JSON_THROW_ON_ERROR),
            'instrument_id' => $instrument['id'],
            'created_by'    => $createdBy,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $definitions = Archetypes::definitions();
        $roleBias    = Archetypes::roleBias();
        $sizes       = Archetypes::sizes();
        $sectors     = Archetypes::sectors();
        $tenures     = Archetypes::tenures();
        $suffixes    = Archetypes::orgSuffix();
        // The roles a single-respondent organisation draws from: every role the
        // instrument offers except "Other", which is not a job.
        $singleRoles = array_values(array_filter(
            Archetypes::roles(),
            static fn (string $role): bool => $role !== 'Other'
        ));

        $usedNames     = [];
        $records       = 0;
        $organisations = [];

        for ($o = 0; $o < $options['count']; $o++) {
            $sector = $this->pick($sectors);
            $size   = $this->pick($sizes);

            $suffixPool = $suffixes[$sector] ?? $suffixes['Other'] ?? ['Group'];

            do {
                $name = $this->pick(Archetypes::orgPrefix()) . ' ' . $this->pick($suffixPool);
            } while (isset($usedNames[$name]));
            $usedNames[$name] = true;

            $organisationId = OrganisationResolver::resolve($name, $sector, $size, true);
            $organisations[$organisationId] = true;

            $archetype  = $this->chooseArchetype($options['archetypes']);
            $definition = $definitions[$archetype];
            $base       = $this->baseTargets($definition['targets'], $sector, count($dimensions));

            $systems = $this->intBetween(3, 62);

            /*
             * How many years this organisation appears in, with the reference's
             * own weighting: most score once, some twice, the rest every year
             * offered. A dataset where every organisation has scored three times
             * would make the year-over-year screen look far healthier than any
             * real corpus.
             */
            $roll   = $this->next();
            $span   = $roll < 0.45 ? 1 : ($roll < 0.70 ? 2 : count($options['years']));
            $years  = array_slice($options['years'], -min($span, count($options['years'])));

            // Most organisations improve; about one in five goes backwards.
            $drift = ($this->next() < 0.2 ? -1 : 1) * abs($this->gauss($options['drift'], 0.12));

            // Only in the LATEST year does an organisation get several
            // respondents: a spread between executives is a snapshot of
            // disagreement now, not something to invent retrospectively.
            $multiRespondent = $this->next() < $options['multi_share'];

            foreach ($years as $index => $year) {
                // Earlier years are walked back down the drift, so the latest
                // year holds the base targets.
                $stepsBack = count($years) - 1 - $index;
                $targets   = array_map(
                    fn (float $t): float => $this->clamp($t - $drift * $stepsBack, 1.0, 5.0),
                    $base
                );

                if ($multiRespondent && $index === count($years) - 1) {
                    $count = $this->intBetween($options['respondents']['min'], $options['respondents']['max']);
                    $roles = $this->shuffle(array_keys($roleBias));

                    for ($r = 0; $r < $count; $r++) {
                        $role = $roles[$r % count($roles)];

                        $this->writeRecord(
                            instrumentId: (int) $instrument['id'],
                            organisationId: $organisationId,
                            seedRunId: $seedRunId,
                            organisationName: $name,
                            sector: $sector,
                            size: $size,
                            tenure: $this->pick($tenures),
                            role: $role,
                            year: $year,
                            archetype: $archetype,
                            targets: $this->applyRoleBias($targets, $roleBias[$role] ?? [], $options['spread']),
                            spread: $options['spread'],
                            emerging: $definition['emerging'],
                            systems: $systems,
                            itemsByDimension: $itemsByDimension,
                            dimensions: $dimensions,
                        );

                        $records++;
                    }

                    continue;
                }

                $this->writeRecord(
                    instrumentId: (int) $instrument['id'],
                    organisationId: $organisationId,
                    seedRunId: $seedRunId,
                    organisationName: $name,
                    sector: $sector,
                    size: $size,
                    tenure: $this->pick($tenures),
                    role: $this->pick($singleRoles),
                    year: $year,
                    archetype: $archetype,
                    // A single respondent is the organisation's own view, so its
                    // item noise is tighter than a multi-respondent spread.
                    targets: $targets,
                    spread: $options['spread'] * 0.6,
                    emerging: $definition['emerging'],
                    systems: $systems,
                    itemsByDimension: $itemsByDimension,
                    dimensions: $dimensions,
                );

                $records++;
            }
        }

        Database::statement(sprintf(
            'UPDATE `%s` SET `records_created` = ?, `organisations_created` = ? WHERE `id` = ?',
            $this->app->table('seed_runs')
        ), [$records, count($organisations), $seedRunId]);

        // Each demo row gets a record in sov_records, so the v6 page's admin
        // views show the same demo set as the console. See Services/RecordMirror.
        (new \Apps\AiSovereigntyScorecard\Services\RecordMirror($this->app))->adoptSynthetic($seedRunId);

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
        int $seedRunId,
        string $organisationName,
        string $sector,
        string $size,
        string $tenure,
        string $role,
        int $year,
        string $archetype,
        array $targets,
        float $spread,
        bool $emerging,
        int $systems,
        array $itemsByDimension,
        array $dimensions,
    ): void {
        $submittedAt = $this->dateWithin($year);
        $person      = $this->person($organisationName);

        $submissionId = Database::insert($this->app->table('submissions'), [
            'public_token'      => bin2hex(random_bytes(16)),
            'instrument_id'     => $instrumentId,
            'organisation_id'   => $organisationId,

            'respondent_name'   => $person['name'],
            'respondent_email'  => $person['email'],
            'respondent_phone'  => $person['phone'],
            'organisation_name' => $organisationName,
            'organisation_size' => $size,

            'role'              => $role,
            'tenure'            => $tenure,
            'sector'            => $sector,
            'assessment_year'   => $year,

            /*
             * The inventory count is generated — it is a fact about the
             * organisation, and a demo dataset with none of them would leave the
             * "what is actually enumerated" panel empty. `agenda_meetings` and
             * `education_hours` are NOT, and must not be: those two are the
             * axes of the correlation panes, and generating them from a
             * record's own score is precisely how a demo dataset comes to
             * report a finding.
             */
            'systems_inventory' => $systems,
            'agenda_meetings'   => null,
            'education_hours'   => null,

            // No consent is recorded: nobody consented to anything. A synthetic
            // row with a consent timestamp would be a false statement about a
            // person, which is worse than a missing column.
            'consent_agreed_at' => null,
            'consent_terms_url' => null,

            'status'            => 'complete',
            'is_synthetic'      => 1,
            'archetype'         => $archetype,
            'seed_run_id'       => $seedRunId,
            'submitted_at'      => $submittedAt,
            'created_at'        => $submittedAt,
        ]);

        $now = date('Y-m-d H:i:s');

        foreach ($dimensions as $index => $dimension) {
            $target = $targets[$index] ?? 2.5;

            foreach ($itemsByDimension[$dimension['code']] as $itemId) {
                Database::insert($this->app->table('submission_responses'), [
                    'submission_id' => $submissionId,
                    'item_id'       => $itemId,
                    'mark'          => $this->mark($target, $spread, $emerging),
                    'answered_at'   => $now,
                ]);
            }
        }

        // Scored by the real Scorer, so a demo record can never hold a score the
        // scoring rules could not have produced.
        (new Scorer($this->app))->score($submissionId);
    }

    /**
     * One item mark, 1–5.
     *
     * The `emerging` branch is the reference's, verbatim in behaviour: an
     * organisation with plenty of ungoverned activity answers 2 most of the time,
     * and otherwise 1 or 3. It does not spread around a target, because the
     * pattern IS the archetype.
     */
    private function mark(float $target, float $spread, bool $emerging): int
    {
        if ($emerging) {
            return $this->next() < 0.68 ? 2 : ($this->next() < 0.55 ? 1 : 3);
        }

        return (int) $this->clamp((float) round($this->gauss($target, $spread * 0.7)), 1, 5);
    }

    /**
     * An archetype's targets, shifted by its sector and jittered.
     *
     * Clamped to the full 1–5 scale. The reference clamped this to 4, which is
     * why no synthetic organisation ever reached Embedded: its
     * `institutionalized` archetype asks for 4.3 on the pipeline and got 4.0.
     *
     * @param list<float> $base
     * @return list<float>
     */
    private function baseTargets(array $base, string $sector, int $count): array
    {
        $priors = Archetypes::sectorPrior();
        $prior  = $priors[$sector] ?? $priors['Other'] ?? array_fill(0, $count, 0.0);

        $targets = [];
        for ($i = 0; $i < $count; $i++) {
            $targets[] = $this->clamp(
                ($base[$i] ?? 2.5) + ($prior[$i] ?? 0.0) + $this->gauss(0, 0.15),
                1.0,
                5.0
            );
        }

        return $targets;
    }

    /**
     * @param list<float> $targets
     * @param list<float> $bias
     * @return list<float>
     */
    private function applyRoleBias(array $targets, array $bias, float $spread): array
    {
        $out = [];

        foreach ($targets as $index => $target) {
            // The reference scaled a role's bias by the spread setting, so
            // "Wide" variation widens the disagreement between roles as well as
            // the noise within one respondent's answers.
            $out[] = $this->clamp(
                $target + ($bias[$index] ?? 0.0) * (1 + 2 * $spread) + $this->gauss(0, $spread * 0.45),
                1.0,
                5.0
            );
        }

        return $out;
    }

    /**
     * A synthetic respondent, deliberately unreachable.
     *
     * `.invalid` is a reserved TLD and 555-01xx is the fictional US range, so a
     * seeded row can never be mistaken for a contactable person — and
     * RespondentIdentity refuses `.invalid` outright, so one of these addresses
     * could not be submitted through the form either.
     *
     * @return array{name:string, email:string, phone:string}
     */
    private function person(string $organisation): array
    {
        $first = $this->pick(Archetypes::firstNames());
        $last  = $this->pick(Archetypes::lastNames());

        $slug = static function (string $value): string {
            $value = strtolower(
                (string) preg_replace('/[^A-Za-z0-9]+/', '', self::fold($value))
            );

            return substr($value, 0, 18) ?: 'org';
        };

        return [
            'name'  => $first . ' ' . $last,
            'email' => $slug($first) . '.' . $slug($last) . '@' . $slug($organisation) . '.example.invalid',
            'phone' => '+1 555 01' . str_pad((string) $this->intBetween(0, 99), 2, '0', STR_PAD_LEFT),
        ];
    }

    /** Strip accents, so "Sørensen" slugs to "sorensen" rather than "srensen". */
    private static function fold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $folded === false ? $value : $folded;
    }

    /**
     * A datetime inside the target year, never in the future.
     *
     * The reference subtracted days from "now" and then forced the year, which
     * pushed roughly one record in ten past today; its fix walked the year back,
     * which moved the record into a year it was not generated for.
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
     * Linear congruential generator, matching the reference's constants.
     *
     * Deliberately not mt_rand(): this must produce the same dataset from the same
     * seed on any PHP build, which a seeded global generator does not guarantee
     * across versions.
     */
    private function next(): float
    {
        $this->drawCount++;
        $this->state = ($this->state * 1664525 + 1013904223) & 0xFFFFFFFF;

        return $this->state / 4294967296;
    }

    /** Approximately normal, from the sum of four uniforms — the reference's gauss. */
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

    private function clamp(float $value, float $low, float $high): float
    {
        return max($low, min($high, $value));
    }

    /** Fisher-Yates. Consumes exactly n-1 draws, always. */
    private function shuffle(array $values): array
    {
        $values = array_values($values);

        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = (int) floor($this->next() * ($i + 1));
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }

    /** @param array<string, float> $weights */
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

        return (string) array_key_last($weights);
    }

    /** How many PRNG draws this run consumed. Used by the determinism test. */
    public function draws(): int
    {
        return $this->drawCount;
    }

    /* -------------------------------------------------------------- setup -- */

    /** @return array<string, mixed> */
    private function normalise(array $options): array
    {
        $year = (int) date('Y');

        $defaults = array_map(
            static fn (array $a): float => $a['weight'],
            Archetypes::definitions()
        );

        // Only archetypes this instrument defines: a name from another
        // instrument would otherwise reach generate() and have no targets.
        $archetypes = array_filter(
            array_map('floatval', array_intersect_key($options['archetypes'] ?? $defaults, $defaults)),
            static fn (float $w): bool => $w > 0
        );

        // Every weight zeroed would divide by zero when choosing. The form
        // refuses it too; this is the belt for the API and the CLI, and it falls
        // back to the instrument's own mix rather than naming an archetype here.
        if ($archetypes === []) {
            $archetypes = array_filter($defaults, static fn (float $w): bool => $w > 0);
        }

        $years = array_values(array_unique(array_map('intval', (array) ($options['years'] ?? [$year - 2, $year - 1, $year]))));
        sort($years);

        return [
            // 500 is the form's own maximum; a lower clamp here would silently
            // hand back fewer organisations than the screen said it would make.
            'count'       => max(1, min(500, (int) ($options['count'] ?? 14))),
            'respondents' => [
                'min' => max(1, (int) ($options['respondents']['min'] ?? 3)),
                'max' => max(1, (int) ($options['respondents']['max'] ?? 5)),
            ],
            'multi_share' => max(0.0, min(1.0, (float) ($options['multi_share'] ?? 0.35))),
            'spread'      => max(0.1, min(1.2, (float) ($options['spread'] ?? 0.5))),
            'drift'       => max(-1.0, min(1.0, (float) ($options['drift'] ?? 0.22))),
            'years'       => $years === [] ? [$year] : $years,
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
            throw new RuntimeException('No published instrument to generate against.');
        }

        return $instrument;
    }

    /* ------------------------------------------------------------ removal -- */

    /** Delete every synthetic record, or just one run's. Real ones are untouched. */
    public static function purge(AppManifest $app, ?int $seedRunId = null): int
    {
        $table = $app->table('submissions');

        // The demo records these rows mirror (sov_records) go first, or the page
        // would keep showing them. See Services/RecordMirror.
        (new \Apps\AiSovereigntyScorecard\Services\RecordMirror($app))->forgetSynthetic($seedRunId);

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

    /** Delete the demo organisations that no app's submissions reference any more. */
    public static function pruneOrganisations(): void
    {
        /*
         * Demo organisations that nothing points at any more.
         *
         * `organisations` is shared with every other app, so the NOT IN has to
         * consider every app's submissions, not just this one's — deleting a
         * synthetic organisation that the Director Scorecard's demo set still
         * references would orphan its rows. Only synthetic organisations are
         * ever considered, so a real company is never touched even if a
         * synthetic respondent happened to score against it.
         */
        $referenced = [];
        foreach (\Platform\Core\AppRegistry::all() as $other) {
            $candidate = $other->table('submissions');

            if (!Database::hasTable($candidate)) {
                continue;
            }

            $referenced[] = sprintf(
                'SELECT DISTINCT `organisation_id` FROM `%s` WHERE `organisation_id` IS NOT NULL',
                $candidate
            );
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
