<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services\Synthetic;

use Apps\AiExecutiveReadinessScorecard\Services\Records;
use Apps\AiExecutiveReadinessScorecard\Services\Scoring\Scorer;
use Platform\Assessment\OrganisationResolver;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * Generates flagged demo questionnaires — the reference's seedDataset() and
 * synthRecord() — so the analytics screens can be shown before real leaders
 * have answered.
 *
 * Every record is `is_synthetic = 1`, is labelled SYNTHETIC wherever it
 * appears, is left out of analytics and exports unless "include synthetic" is
 * on, and is scored by the real Scorer, so a demo dataset cannot hold a score
 * the scoring rules could not produce.
 *
 * How a generated respondent answers, as the reference generated it:
 *
 *  - The organization gets an archetype's four targets, jittered, and (when it
 *    appears in several years) a drift walked back from the latest year.
 *  - The respondent rates the LEADERS around those targets, and THEMSELVES
 *    around the same targets shifted by a personal offset drawn once per
 *    questionnaire (`gauss(0, .55)`) plus a little per dimension — so some
 *    respondents rate themselves above their leaders and some below, which is
 *    what the Comparison pane is there to show.
 *  - A single-respondent organization keeps the SAME person from year to year,
 *    so the year-over-year "same respondents" table has someone to match.
 *    Several respondents appear only in the latest year.
 *  - The executive-meeting and education figures are drawn uniformly, as the
 *    reference drew them (0–17 and 0–35), independently of every score — so
 *    the Correlation pane shows noise, which is the honest result for data
 *    nobody reported.
 *
 * Departures from the reference, kept from the sibling apps: roles are shuffled
 * with Fisher-Yates rather than `sort(() => r() - .5)`, whose number of calls
 * depends on the JavaScript engine; and no consent is recorded, because nobody
 * consented to anything.
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
        $suffixes    = Archetypes::orgSuffix();
        $singleRoles = array_values(array_filter(Archetypes::roles(), static fn (string $role): bool => $role !== 'Other'));

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

            $archetype = $this->chooseArchetype($options['archetypes']);
            $base      = $this->baseTargets($definitions[$archetype]['targets'], $sector, count($dimensions));
            $systems   = (int) floor($this->next() * 60 + 3);

            // Most organizations answer once, some twice, the rest every year.
            $roll  = $this->next();
            $span  = $roll < 0.45 ? 1 : ($this->next() < 0.7 ? 2 : count($options['years']));
            $years = array_slice($options['years'], -min($span, count($options['years'])));

            // Most organizations improve; about one in five goes backwards.
            $drift = ($this->next() < 0.2 ? -1 : 1) * abs($this->gauss($options['drift'], 0.12));

            $multiRespondent = $this->next() < $options['multi_share'];
            $personName      = $this->pick(Archetypes::firstNames()) . ' ' . $this->pick(Archetypes::lastNames());

            foreach ($years as $index => $year) {
                $stepsBack = count($years) - 1 - $index;
                $targets   = array_map(
                    fn (float $t): float => $this->clamp($t - $drift * $stepsBack, 1.0, 5.0),
                    $base
                );

                // The reference drew a `daysAgo` here that nothing reads; the
                // draw is kept so a seed walks the same sequence.
                $this->next();

                if ($multiRespondent && $index === count($years) - 1) {
                    $count = $this->intBetween($options['respondents']['min'], $options['respondents']['max']);
                    $roles = array_slice($this->shuffle(array_keys($roleBias)), 0, $count);

                    foreach ($roles as $role) {
                        $rater = $this->pick(Archetypes::firstNames()) . ' ' . $this->pick(Archetypes::lastNames());

                        $this->writeRecord($instrument, $organisationId, $seedRunId, $name, $sector, $size, (string) $role,
                            $year, $archetype, $rater, $systems,
                            $this->applyRoleBias($targets, $roleBias[$role] ?? [], $options['spread']),
                            $options['spread'], $itemsByDimension, $dimensions);
                        $records++;
                    }

                    continue;
                }

                $this->writeRecord($instrument, $organisationId, $seedRunId, $name, $sector, $size,
                    (string) $this->pick($singleRoles), $year, $archetype, $personName, $systems,
                    $targets, $options['spread'] * 0.6, $itemsByDimension, $dimensions);
                $records++;
            }
        }

        Database::statement(sprintf(
            'UPDATE `%s` SET `records_created` = ?, `organisations_created` = ? WHERE `id` = ?',
            $this->app->table('seed_runs')
        ), [$records, count($organisations), $seedRunId]);

        // Each demo row gets a record in exr_records, so the v4 page's admin
        // views show the same demo set as the console. See Services/RecordMirror.
        (new \Apps\AiExecutiveReadinessScorecard\Services\RecordMirror($this->app))->adoptSynthetic($seedRunId);

        Records::flush();

        return [
            'records'       => $records,
            'organisations' => count($organisations),
            'seed_run_id'   => $seedRunId,
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * synthRecord(): one questionnaire, both scopes.
     *
     * @param array<string, mixed>       $instrument
     * @param list<float>                $targets    the leaders' targets, one per dimension
     * @param array<string, list<int>>   $itemsByDimension
     * @param list<array<string, mixed>> $dimensions
     */
    private function writeRecord(
        array $instrument,
        ?int $organisationId,
        int $seedRunId,
        string $organisationName,
        string $sector,
        string $size,
        string $role,
        int $year,
        string $archetype,
        string $rater,
        int $systems,
        array $targets,
        float $spread,
        array $itemsByDimension,
        array $dimensions,
    ): void {
        $person     = $this->person($organisationName, $rater);
        $selfOffset = $this->gauss(0, 0.55);
        $ratings    = [];

        foreach ($dimensions as $index => $dimension) {
            $target = $targets[$index] ?? 2.5;

            foreach ($itemsByDimension[$dimension['code']] as $itemId) {
                $ratings[] = [$itemId, 'leadership', $this->mark($target, $spread)];
            }

            $selfTarget = $this->clamp($target + $selfOffset + $this->gauss(0, 0.3), 1.0, 5.0);

            foreach ($itemsByDimension[$dimension['code']] as $itemId) {
                $ratings[] = [$itemId, 'self', $this->mark($selfTarget, $spread)];
            }
        }

        $agenda = (int) floor($this->next() * 18);
        $edu    = (int) floor($this->next() * 36);
        $tenure = (string) $this->pick(Archetypes::tenures());

        // The reference's record id draw, kept for the sequence.
        $this->next();

        $submittedAt = $this->monthStart($year);

        $submissionId = Database::insert($this->app->table('submissions'), [
            'public_token'      => bin2hex(random_bytes(16)),
            'instrument_id'     => (int) $instrument['id'],
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
            'systems_inventory' => $systems,
            'agenda_meetings'   => $agenda,
            'education_hours'   => $edu,
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

        foreach ($ratings as [$itemId, $scope, $mark]) {
            Database::insert($this->app->table('submission_responses'), [
                'submission_id' => $submissionId,
                'item_id'       => $itemId,
                'scope'         => $scope,
                'mark'          => $mark,
                'answered_at'   => $now,
            ]);
        }

        (new Scorer($this->app))->score($submissionId);
    }

    /** synthItems(): one rating, a gaussian round the target, rounded and clamped to 1–5. */
    private function mark(float $target, float $spread): int
    {
        return (int) round($this->clamp($this->gauss($target, $spread * 0.7), 1, 5));
    }

    /**
     * An archetype's targets, shifted by its sector and jittered.
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
            $targets[] = $this->clamp(($base[$i] ?? 2.5) + ($prior[$i] ?? 0.0) + $this->gauss(0, 0.15), 1.0, 5.0);
        }

        return $targets;
    }

    /**
     * A role's bias, scaled by the spread setting, plus that respondent's noise.
     *
     * @param list<float> $targets
     * @param list<float> $bias
     * @return list<float>
     */
    private function applyRoleBias(array $targets, array $bias, float $spread): array
    {
        $out = [];

        foreach ($targets as $index => $target) {
            $out[] = $this->clamp(
                $target + ($bias[$index] ?? 0.0) * (1 + 2 * $spread) + $this->gauss(0, $spread * 0.45),
                1.0,
                5.0
            );
        }

        return $out;
    }

    /**
     * synthPerson(): deliberately unreachable. `.invalid` is a reserved TLD and
     * 555-01xx is the fictional US range, and RespondentIdentity refuses
     * `.invalid`, so one of these could not be submitted through the form.
     *
     * @return array{name:string, email:string, phone:string}
     */
    private function person(string $organisation, string $name): array
    {
        $slug = static function (string $value): string {
            $value = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', self::fold($value)));

            return substr($value, 0, 18) ?: 'org';
        };

        $parts = preg_split('/\s+/', trim($name)) ?: [$name];

        return [
            'name'  => $name,
            'email' => $slug($parts[0]) . '.' . $slug($parts[count($parts) - 1]) . '@' . $slug($organisation) . '.example.invalid',
            'phone' => '+1 555 01' . str_pad((string) (int) floor($this->next() * 100), 2, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * The first of a month inside the year, never in the future — the
     * reference's `Date.UTC(year, month, 1)` with the month drawn from the
     * months that have begun.
     */
    private function monthStart(int $year): string
    {
        $months = $year === (int) date('Y') ? (int) date('n') : 12;
        $month  = (int) floor($this->next() * $months);

        return date('Y-m-d H:i:s', gmmktime(0, 0, 0, $month + 1, 1, $year));
    }

    /** Strip accents, so "Sørensen" slugs to "sorensen" rather than "srensen". */
    private static function fold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $folded === false ? $value : $folded;
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
            'multi_share' => max(0.0, min(1.0, (float) ($options['multi_share'] ?? 0.3))),
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

        // The demo records these rows mirror (exr_records) go first, or the page
        // would keep showing them. See Services/RecordMirror.
        (new \Apps\AiExecutiveReadinessScorecard\Services\RecordMirror($app))->forgetSynthetic($seedRunId);

        if ($seedRunId !== null) {
            $removed = Database::statement(
                sprintf('DELETE FROM `%s` WHERE `is_synthetic` = 1 AND `seed_run_id` = ?', $table),
                [$seedRunId]
            );
        } else {
            $removed = Database::statement(sprintf('DELETE FROM `%s` WHERE `is_synthetic` = 1', $table));
        }

        self::pruneOrganisations();

        Records::flush();

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

        Records::flush();
    }
}
