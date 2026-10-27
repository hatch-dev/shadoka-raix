<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services;

use Apps\EnterpriseAiScorecard\Services\Scoring\Scorer;
use Platform\Assessment\Bander;
use Platform\Assessment\OrganisationResolver;
use Platform\Assessment\ScoreSummary;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * Everything a submission does, between the controller and the database.
 *
 * The contract with the browser: it sends MARKS. It never sends a dimension
 * score, an average, a floor or a level — those are derived here by the Scorer.
 * In the reference build the browser computed and stored its own record, so any
 * client could assert any score.
 */
final class SubmissionService
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Starting                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Open a submission against the currently published instrument.
     *
     * @param array<string, mixed> $profile validated profile fields
     * @return array{token: string, id: int}
     */
    public function start(array $profile, ?string $ipHash = null): array
    {
        $instrument = $this->publishedInstrument();

        /*
         * The organisation is resolved onto the platform's shared table, and its
         * typed name is ALSO kept on the submission.
         *
         * Two different facts. `organisations.name` is the deduplicated entity —
         * "Meridian Capital Group" however three respondents spelled it — and is
         * what joins this dataset to any other app's. `organisation_name` is what
         * THIS respondent typed, which is the self-report the instrument
         * collected and the only version that survives if the two rows are ever
         * reconciled differently.
         */
        $organisationId = OrganisationResolver::resolve(
            $profile['organisation_name'] ?? null,
            $profile['sector'] ?? null,
            $profile['organisation_size'] ?? null,
        );

        $token = bin2hex(random_bytes(16));

        $consent = $profile['consent'] ?? null;

        $id = Database::insert($this->app->table('submissions'), [
            'public_token'      => $token,
            'instrument_id'     => (int) $instrument['id'],
            'organisation_id'   => $organisationId,

            'respondent_name'   => $profile['respondent_name'],
            'respondent_email'  => $profile['respondent_email'],
            'respondent_phone'  => $profile['respondent_phone'],
            'organisation_name' => $profile['organisation_name'],
            'organisation_size' => $profile['organisation_size'],

            'role'              => $profile['role'] ?? null,
            'tenure'            => $profile['tenure'] ?? null,
            'sector'            => $profile['sector'] ?? null,
            'assessment_year'   => (int) ($profile['assessment_year'] ?? date('Y')),

            'systems_inventory' => $profile['systems_inventory'] ?? null,
            'agenda_meetings'   => $profile['agenda_meetings'] ?? null,
            'education_hours'   => $profile['education_hours'] ?? null,

            /*
             * What they agreed to, and when. The URL is stored alongside the
             * timestamp so a later revision of the terms cannot be read back
             * onto a consent given under the old ones.
             */
            'consent_agreed_at' => $consent === true || ($consent['agreed'] ?? false)
                ? date('Y-m-d H:i:s')
                : null,
            'consent_terms_url' => $consent === true || ($consent['agreed'] ?? false)
                ? (string) $this->app->config('result.consent.terms_url')
                : null,

            'status'            => 'in_progress',
            'is_synthetic'      => 0,
            'ip_hash'           => $ipHash,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'id' => $id];
    }


    /**
     * Rewrite the profile of a scorecard already open.
     *
     * What "Back" leads to. The respondent is on step 0 again, editing what they
     * typed, and the record exists — so this updates it in place rather than
     * start()ing a second one, which would open a new token and strand every
     * answer already saved against the first.
     *
     * The organisation is re-resolved, because the name or the sector may be what
     * they came back to change. The consent columns are NOT touched: they record
     * an acceptance that already happened, at a URL that was already shown, and
     * an edit is not a fresh agreement. is_synthetic, created_at and the token
     * are likewise left alone.
     *
     * @param array<string, mixed> $profile validated profile fields
     */
    public function updateProfile(array $submission, array $profile): void
    {
        $organisationId = OrganisationResolver::resolve(
            $profile['organisation_name'] ?? null,
            $profile['sector'] ?? null,
            $profile['organisation_size'] ?? null,
        );

        $columns = [
            'organisation_id'   => $organisationId,

            'respondent_name'   => $profile['respondent_name'],
            'respondent_email'  => $profile['respondent_email'],
            'respondent_phone'  => $profile['respondent_phone'],
            'organisation_name' => $profile['organisation_name'],
            'organisation_size' => $profile['organisation_size'],

            'role'              => $profile['role'] ?? null,
            'tenure'            => $profile['tenure'] ?? null,
            'sector'            => $profile['sector'] ?? null,
            'assessment_year'   => (int) ($profile['assessment_year'] ?? date('Y')),

            'systems_inventory' => $profile['systems_inventory'] ?? null,
            'agenda_meetings'   => $profile['agenda_meetings'] ?? null,
            'education_hours'   => $profile['education_hours'] ?? null,

            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        $assignments = implode(', ', array_map(
            static fn (string $column): string => sprintf('`%s` = ?', $column),
            array_keys($columns)
        ));

        Database::statement(
            sprintf('UPDATE `%s` SET %s WHERE `id` = ?', $this->app->table('submissions'), $assignments),
            [...array_values($columns), $submission['id']]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Saving progress                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Record marks. Partial by design — the assessment saves as it goes.
     *
     * The reference kept a whole scorecard in sessionStorage and lost it when the
     * tab closed. Here every answer is written as it is given.
     *
     * @param list<array{item_id:int, mark:int|null}> $responses
     * @return array{saved:int, cleared:int}
     */
    public function saveProgress(array $submission, array $responses): array
    {
        $valid = $this->itemIds((int) $submission['instrument_id']);
        $table = $this->app->table('submission_responses');
        $now   = date('Y-m-d H:i:s');

        $saved   = 0;
        $cleared = 0;

        Database::transaction(function () use ($responses, $valid, $submission, $table, $now, &$saved, &$cleared): void {
            foreach ($responses as $response) {
                $itemId = (int) ($response['item_id'] ?? 0);

                // A question from another instrument (or another app) must never
                // attach to this submission, whatever the client sends.
                if (!isset($valid[$itemId])) {
                    continue;
                }

                $mark = $response['mark'] ?? null;

                // A cleared mark deletes the row rather than storing a zero. An
                // unanswered question is unscored, and unscored is not the same
                // as 1 — it is left out of the average entirely.
                if ($mark === null || $mark === '') {
                    $cleared += Database::statement(
                        sprintf('DELETE FROM `%s` WHERE `submission_id` = ? AND `item_id` = ?', $table),
                        [$submission['id'], $itemId]
                    );
                    continue;
                }

                $mark = (int) $mark;

                // Five points, not four. The sibling app's range is 1–4 and
                // copying its bound here would silently discard every 5.
                if ($mark < 1 || $mark > 5) {
                    continue;
                }

                Database::statement(
                    sprintf(
                        'INSERT INTO `%s` (`submission_id`, `item_id`, `mark`, `answered_at`)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE `mark` = VALUES(`mark`),
                                                 `answered_at` = VALUES(`answered_at`)',
                        $table
                    ),
                    [$submission['id'], $itemId, $mark, $now]
                );

                $saved++;
            }

            Database::statement(
                sprintf('UPDATE `%s` SET `updated_at` = ? WHERE `id` = ?', $this->app->table('submissions')),
                [$now, $submission['id']]
            );
        });

        return ['saved' => $saved, 'cleared' => $cleared];
    }

    /* ------------------------------------------------------------------ */
    /* Submitting                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Finalise and score.
     *
     * Every question must be answered, which is what the reference's nextDim()
     * enforced per dimension before it would advance. A partial scorecard is not
     * a valid one: the level is the average, and an average over eleven answers
     * is not comparable with an average over thirty.
     *
     * @return array{summary: ScoreSummary, missing: list<string>}
     */
    public function submit(array $submission): array
    {
        $missing = $this->missingDimensions($submission);

        if ($missing !== []) {
            return [
                'summary' => ScoreSummary::unscored(count($this->dimensions((int) $submission['instrument_id']))),
                'missing' => $missing,
            ];
        }

        $summary = (new Scorer($this->app))->score((int) $submission['id']);

        Database::statement(
            sprintf(
                'UPDATE `%s` SET `status` = ?, `submitted_at` = COALESCE(`submitted_at`, ?), `updated_at` = ?
                  WHERE `id` = ?',
                $this->app->table('submissions')
            ),
            ['complete', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $submission['id']]
        );

        return ['summary' => $summary, 'missing' => []];
    }

    /**
     * Record the respondent accepting their own scorecard.
     *
     * In the reference, "Save this scorecard" was what brought the record into
     * existence — until it was pressed, the scorecard lived only in the page.
     * Here the answers are already in the database, so the button has to mean
     * something else or mean nothing.
     *
     * It means acceptance: the respondent has seen the result and stands behind
     * it. For a self-assessment whose whole claim rests on that, it is the fact
     * worth keeping. Nothing filters on it — an unconfirmed scorecard still
     * scores, still exports and still counts as a peer.
     *
     * @return array{confirmed_at:string, confirmed_on:string, already_confirmed:bool, carry_forward:array<string, mixed>}
     */
    public function confirm(array $submission): array
    {
        $existing = $submission['confirmed_at'] ?? null;
        $now      = date('Y-m-d H:i:s');

        // A second press does not move the timestamp: the column records when
        // the respondent FIRST accepted this result, and pressing the button
        // again is not the acceptance of something different.
        if ($existing === null) {
            Database::statement(
                sprintf(
                    'UPDATE `%s` SET `confirmed_at` = ?, `updated_at` = ? WHERE `id` = ?',
                    $this->app->table('submissions')
                ),
                [$now, $now, $submission['id']]
            );
        }

        return [
            'confirmed_at'      => $existing ?? $now,
            // Formatted here, not in the browser: the note the page prints is
            // the same date in the same format however it got there.
            'confirmed_on'      => date((string) config('app.date_format'), strtotime($existing ?? $now)),
            'already_confirmed' => $existing !== null,

            /*
             * What the next scorecard may start from — the same three fields the
             * reference carried forward (`A.profile = {sector, size, year}`).
             *
             * Name, email, phone and role are deliberately NOT among them: the
             * point of scoring another organisation is that a different person
             * at a different organisation is answering. Raw values, not the
             * display defaults result() applies, so a blank sector prefills as
             * blank rather than as "Not specified".
             */
            'carry_forward' => [
                'sector'            => $submission['sector'],
                'organisation_size' => $submission['organisation_size'],
                'assessment_year'   => (int) $submission['assessment_year'],
            ],
        ];
    }

    /**
     * Dimension codes with at least one unanswered question.
     *
     * @return list<string>
     */
    public function missingDimensions(array $submission): array
    {
        $rows = Database::select(sprintf(
            'SELECT d.`code`, COUNT(i.`id`) AS items, COUNT(r.`id`) AS answered
               FROM `%s` d
               JOIN `%s` i ON i.`dimension_id` = d.`id`
          LEFT JOIN `%s` r ON r.`item_id` = i.`id` AND r.`submission_id` = ?
              WHERE d.`instrument_id` = ?
           GROUP BY d.`id`, d.`code`, d.`position`
             HAVING answered < items
           ORDER BY d.`position`',
            $this->app->table('dimensions'),
            $this->app->table('items'),
            $this->app->table('submission_responses')
        ), [$submission['id'], $submission['instrument_id']]);

        return array_column($rows, 'code');
    }

    /* ------------------------------------------------------------------ */
    /* Reading                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `public_token` = ?', $this->app->table('submissions')),
            [$token]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ?', $this->app->table('submissions')),
            [$id]
        );
    }

    /** The marks recorded so far, keyed by item id. */
    public function responses(int $submissionId): array
    {
        $rows = Database::select(
            sprintf('SELECT `item_id`, `mark` FROM `%s` WHERE `submission_id` = ?',
                $this->app->table('submission_responses')),
            [$submissionId]
        );

        $byItem = [];
        foreach ($rows as $row) {
            $byItem[(int) $row['item_id']] = ['mark' => (int) $row['mark']];
        }

        return $byItem;
    }

    /**
     * The full result payload: the level, the six dimensions, the floor, the
     * reading, and the benchmark.
     *
     * @return array<string, mixed>
     */
    public function result(array $submission): array
    {
        $instrumentId = (int) $submission['instrument_id'];
        $bander       = new Bander($this->rungRows($instrumentId));

        $scores = Database::select(sprintf(
            'SELECT d.`id` AS dimension_id, d.`code`, d.`name`, d.`pressing_question`,
                    d.`failure_line`, d.`floor_narrative`,
                    s.`score`, s.`rung_value`, s.`items_answered`
               FROM `%s` d
          LEFT JOIN `%s` s ON s.`dimension_id` = d.`id` AND s.`submission_id` = ?
              WHERE d.`instrument_id` = ?
           ORDER BY d.`position`',
            $this->app->table('dimensions'),
            $this->app->table('submission_scores')
        ), [$submission['id'], $instrumentId]);

        $meanScore  = $submission['mean_score'] === null ? null : (float) $submission['mean_score'];
        $floorScore = $submission['floor_score'] === null ? null : (float) $submission['floor_score'];
        $floorId    = $submission['floor_dimension_id'] === null ? null : (int) $submission['floor_dimension_id'];

        $floorDimension = null;
        $dimensions     = [];

        foreach ($scores as $row) {
            $isFloor = $floorId !== null && (int) $row['dimension_id'] === $floorId;

            if ($isFloor) {
                $floorDimension = $row;
            }

            $dimensions[] = [
                'code'              => $row['code'],
                'name'              => $row['name'],
                'pressing_question' => $row['pressing_question'],
                'score'             => $row['score'] === null ? null : (float) $row['score'],
                'rung'              => $row['score'] === null ? null : $bander->band((float) $row['score']),
                'items_answered'    => (int) ($row['items_answered'] ?? 0),
                'is_floor'          => $isFloor,
            ];
        }

        $unscored = array_values(array_map(
            static fn (array $d): string => $d['name'],
            array_filter($dimensions, static fn (array $d): bool => $d['score'] === null)
        ));

        $unspecified = (string) $this->app->config('result.unspecified_label');

        return [
            'token'  => $submission['public_token'],
            'status' => $submission['status'],

            // Null until the respondent accepts the result on this page. The
            // action row reads it so a returning respondent is not asked to
            // confirm something they already confirmed.
            'confirmed_at' => $submission['confirmed_at'] ?? null,

            'year'         => (int) $submission['assessment_year'],
            'respondent'   => $submission['respondent_name']
                ?: (string) $this->app->config('result.anonymous_label'),
            'email'        => $submission['respondent_email'],
            'phone'        => $submission['respondent_phone'],
            'organisation' => $submission['organisation_name'],
            'size'         => $submission['organisation_size'],
            'role'         => $submission['role'],
            'tenure'       => $submission['tenure'],
            'sector'       => $submission['sector'] ?: $unspecified,

            'submitted_on' => date(
                (string) config('app.date_format'),
                strtotime((string) ($submission['submitted_at'] ?? $submission['created_at']))
            ),

            /*
             * The level follows the AVERAGE, and `level` is banded from it. The
             * floor travels beside it and is labelled as the floor everywhere it
             * appears, so no screen can quietly present one as the other.
             */
            'mean'       => $meanScore,
            'level'      => $bander->band($meanScore),
            'floor'      => $floorScore,
            'floor_code' => $floorDimension['code'] ?? null,
            'floor_name' => $floorDimension['name'] ?? null,
            'spread'     => ($floorScore !== null && $meanScore !== null)
                ? round($meanScore - $floorScore, 2)
                : null,

            'dimensions'    => $dimensions,
            'scored_count'  => count($dimensions) - count($unscored),
            'total_count'   => count($dimensions),
            'unscored'      => $unscored,

            'floor_note'      => $this->floorNote($floorDimension, $floorScore),
            'floor_narrative' => $this->floorNarrative($floorDimension, $floorScore),
            'benchmark'       => $this->benchmark($submission, $meanScore),

            // For the browser-generated PDF, so the document cannot state
            // anything this page does not.
            'band_edges'   => $this->bandEdges($bander),
            'rungs'        => $bander->rungs(),
            'pdf_footnote' => $this->pdfFootnote($bander),

            // The raw marks, so "Review answers" can reopen a finished scorecard
            // without a second round trip. The server-rendered page ignores it.
            'responses' => $this->responses((int) $submission['id']),
        ];
    }

    /**
     * Where this organisation's overall score sits among its peers.
     *
     * Withheld until the dataset holds enough REAL scorecards: a percentile
     * against three organisations is not a position, it is noise dressed as one.
     * Synthetic records are never peers — telling a respondent they are ahead of
     * a generated population would be telling them something false.
     *
     * The reference benchmarked the FLOOR. This benchmarks the overall score,
     * because that is the number the page reports as the result; comparing a
     * floor against a stated average would be comparing two different things.
     *
     * @return array<string, mixed>|null null when there is no score at all
     */
    private function benchmark(array $submission, ?float $meanScore): ?array
    {
        if ($meanScore === null) {
            return null;
        }

        $minimum = (int) $this->app->config('result.benchmark_min_peers', 5);

        $means = array_map(
            static fn (array $r): float => (float) $r['mean_score'],
            Database::select(sprintf(
                'SELECT `mean_score` FROM `%s`
                  WHERE `status` = \'complete\'
                    AND `is_synthetic` = 0
                    AND `mean_score` IS NOT NULL
                    AND `id` <> ?
               ORDER BY `mean_score`',
                $this->app->table('submissions')
            ), [$submission['id']])
        );

        $peers = count($means);

        if ($peers < $minimum) {
            return ['peers' => $peers, 'minimum' => $minimum, 'reportable' => false];
        }

        $below = count(array_filter($means, static fn (float $m): bool => $m < $meanScore));

        return [
            'peers'      => $peers,
            'minimum'    => $minimum,
            'reportable' => true,
            'percentile' => (int) round($below / $peers * 100),
            'median'     => $peers % 2 === 1
                ? $means[intdiv($peers, 2)]
                : round(($means[$peers / 2 - 1] + $means[$peers / 2]) / 2, 2),
        ];
    }

    /**
     * The floor note, returned as its parts rather than one finished sentence.
     *
     * The view emphasises the dimension name. Substituting here would mean either
     * shipping HTML in a config string or a view that prints an unescaped value;
     * handing over the parts lets the view escape each one and add the emphasis
     * itself.
     *
     * @return array{template:string, dimension:string, floor:string}|null
     */
    private function floorNote(?array $floorDimension, ?float $floorScore): ?array
    {
        if ($floorDimension === null || $floorScore === null) {
            return null;
        }

        return [
            'template'  => (string) $this->app->config('result.floor_note'),
            'dimension' => (string) $floorDimension['name'],
            'floor'     => number_format($floorScore, 2),
        ];
    }

    /**
     * The closing reading of the result.
     *
     * The reference asked an AI endpoint for this at render time, from the
     * browser, with no API key — so what every visitor actually saw was the catch
     * block: one sentence naming the weakest dimension and the question to press
     * on it. That sentence is what is composed here, from the instrument's own
     * `pressing_question` and `failure_line`, with no request that cannot
     * succeed. A version that carries written `floor_narrative` uses that
     * instead.
     */
    private function floorNarrative(?array $floorDimension, ?float $floorScore): ?string
    {
        if ($floorDimension === null) {
            return null;
        }

        if (!empty($floorDimension['floor_narrative'])) {
            return str_replace(
                '{floor}',
                $floorScore === null ? '—' : number_format($floorScore, 2),
                (string) $floorDimension['floor_narrative']
            );
        }

        return sprintf(
            '%s is your weakest dimension, at %s. What failing it looks like: %s The question '
            . 'to press, concrete enough to assign to a named person: “%s”',
            $floorDimension['name'],
            $floorScore === null ? '—' : number_format($floorScore, 2),
            rtrim((string) $floorDimension['failure_line'], ' ') . (str_ends_with(trim((string) $floorDimension['failure_line']), '.') ? '' : '.'),
            rtrim((string) $floorDimension['pressing_question'], ' ')
        );
    }

    /**
     * The band cut points, for the gauge the results page and the PDF both draw.
     *
     * Read off the published instrument rather than restated, so a gauge cannot
     * mark its arcs at edges the scorer no longer uses.
     *
     * @return list<float>
     */
    private function bandEdges(Bander $bander): array
    {
        $edges = [];

        foreach ($bander->rungs() as $rung) {
            if ($rung['min'] !== null) {
                $edges[] = (float) $rung['min'];
            }
        }

        return $edges;
    }

    /**
     * The foot of the downloaded PDF.
     *
     * The level legend is generated from the instrument's own rungs rather than
     * written out, so a PDF cannot describe a ladder the instrument has since
     * changed.
     */
    private function pdfFootnote(Bander $bander): string
    {
        $levels = implode(' · ', array_map(
            static fn (array $rung): string => sprintf(
                '%d %s (%s)',
                $rung['value'],
                $rung['name'],
                strtolower(rtrim($rung['description'], '.'))
            ),
            $bander->rungs()
        ));

        return strtr((string) $this->app->config('result.pdf_footnote'), [
            '{copyright}' => (string) config('app.copyright'),
            '{levels}'    => $levels,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Lookups                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function publishedInstrument(): array
    {
        $instrument = Database::selectOne(
            sprintf(
                'SELECT * FROM `%s` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
                $this->app->table('instruments')
            ),
            ['published']
        );

        if ($instrument === null) {
            throw new RuntimeException('No published instrument.');
        }

        return $instrument;
    }

    /** @return array<int, true> valid item ids for an instrument */
    private function itemIds(int $instrumentId): array
    {
        $rows = Database::select(sprintf(
            'SELECT i.`id` FROM `%s` i JOIN `%s` d ON d.`id` = i.`dimension_id` WHERE d.`instrument_id` = ?',
            $this->app->table('items'),
            $this->app->table('dimensions')
        ), [$instrumentId]);

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['id']] = true;
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function dimensions(int $instrumentId): array
    {
        return Database::select(
            sprintf('SELECT `id`, `code` FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
                $this->app->table('dimensions')),
            [$instrumentId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function rungRows(int $instrumentId): array
    {
        return Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`', $this->app->table('rungs')),
            [$instrumentId]
        );
    }
}
