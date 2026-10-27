<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Services;

use Apps\RaixDirectorScorecard\Services\Scoring\Scorer;
use Platform\Assessment\Bander;
use Platform\Assessment\OrganisationResolver;
use Platform\Assessment\ScoreSummary;
use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * Everything a submission does, between the controller and the database.
 *
 * The contract with the browser: it sends MARKS. It never sends a score, a
 * floor, a level or a band — those are derived here by the Scorer. In the
 * legacy build the browser computed and stored its own result, so any client
 * could assert any score.
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

        $organisationId = OrganisationResolver::resolve(
            $profile['board_name'] ?? null,
            $profile['sector'] ?? null,
            $profile['company_size'] ?? null,
        );

        $token = bin2hex(random_bytes(16));

        $id = Database::insert($this->app->table('submissions'), [
            'public_token'    => $token,
            'instrument_id'   => (int) $instrument['id'],
            'organisation_id' => $organisationId,
            'director_name'   => $profile['director_name'] ?? null,
            'seat_role'       => $profile['seat_role'] ?? null,
            'tenure'          => $profile['tenure'] ?? null,

            /*
             * Kept on the submission as well as resolved onto the organisation.
             * A blank board name yields no organisation at all, and these two
             * answers used to vanish with it — accepted by the form, validated
             * against the instrument's own lists, then silently dropped.
             */
            'sector'          => $profile['sector'] ?? null,
            'company_size'    => $profile['company_size'] ?? null,
            'assessment_year' => (int) ($profile['assessment_year'] ?? date('Y')),
            'systems_seen'    => $profile['systems_seen'] ?? null,
            'board_seats'     => $profile['board_seats'] ?? null,
            'agenda_meetings' => $profile['agenda_meetings'] ?? null,
            'education_hours' => $profile['education_hours'] ?? null,
            'remit'           => isset($profile['remit']) && $profile['remit'] !== []
                ? json_encode(array_values((array) $profile['remit']), JSON_THROW_ON_ERROR)
                : null,
            'status'          => 'in_progress',
            'is_synthetic'    => 0,
            'ip_hash'         => $ipHash,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'id' => $id];
    }

    /* ------------------------------------------------------------------ */
    /* Saving progress                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Record marks. Partial by design — the assessment saves as it goes.
     *
     * @param list<array{item_id:int, mark:int|null, evidence_note?:string|null}> $responses
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

                // An item from another instrument (or another app) must never
                // attach to this submission, whatever the client sends.
                if (!isset($valid[$itemId])) {
                    continue;
                }

                $mark = $response['mark'] ?? null;

                // A cleared mark deletes the row rather than storing a zero.
                // An unanswered item is unscored, and unscored is not the same
                // as bad — an unscored competency cannot set the floor.
                if ($mark === null || $mark === '') {
                    $cleared += Database::statement(
                        sprintf('DELETE FROM `%s` WHERE `submission_id` = ? AND `item_id` = ?', $table),
                        [$submission['id'], $itemId]
                    );
                    continue;
                }

                $mark = (int) $mark;
                if ($mark < 1 || $mark > 4) {
                    continue;
                }

                $note = $response['evidence_note'] ?? null;
                if (is_string($note)) {
                    $note = mb_substr(trim($note), 0, 255);
                    $note = $note === '' ? null : $note;
                }

                Database::statement(
                    sprintf(
                        'INSERT INTO `%s` (`submission_id`, `item_id`, `mark`, `evidence_note`, `answered_at`)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE `mark` = VALUES(`mark`),
                                                 `evidence_note` = VALUES(`evidence_note`),
                                                 `answered_at` = VALUES(`answered_at`)',
                        $table
                    ),
                    [$submission['id'], $itemId, $mark, $note, $now]
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
     * Every item must be answered. The instrument's own guidance is that a
     * director scores all seven competencies whatever their committee remit —
     * remit is context, never an exemption — so a partial submission is not a
     * valid scorecard.
     *
     * @return array{summary: ScoreSummary, missing: list<string>}
     */
    public function submit(array $submission): array
    {
        $missing = $this->missingCompetencies($submission);

        if ($missing !== []) {
            return ['summary' => ScoreSummary::unscored(count($this->competencies((int) $submission['instrument_id']))), 'missing' => $missing];
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
     * Record the director accepting their own scorecard.
     *
     * In the single-file build "Save this scorecard" was what brought the
     * record into existence — until it was pressed, the scorecard lived only in
     * the page. Here the answers are already in the database, so the button has
     * to mean something else or mean nothing.
     *
     * It means acceptance: the director has seen the result and stands behind
     * it. That is a genuinely different fact from "a browser reached the
     * results page", and for a self-assessment it is the one worth keeping.
     * Nothing filters on it — an unconfirmed scorecard still scores, still
     * exports and still counts as a peer.
     *
     * @return array{confirmed_at:string, confirmed_on:string, already_confirmed:bool, carry_forward:array<string, mixed>}
     */
    public function confirm(array $submission): array
    {
        $existing = $submission['confirmed_at'] ?? null;
        $now      = date('Y-m-d H:i:s');

        // A second press does not move the timestamp: the column records when
        // the director first accepted this result, and pressing the button
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

        $organisation = $this->organisation($submission);

        return [
            'confirmed_at'      => $existing ?? $now,
            // Formatted here, not in the browser: the note the page prints is
            // the same date in the same format however it got there.
            'confirmed_on'      => date((string) config('app.date_format'), strtotime($existing ?? $now)),
            'already_confirmed' => $existing !== null,

            /*
             * What the next scorecard may start from. Board context only —
             * the same three fields the single-file build carried forward
             * (`A.profile={sector,size,year}`). Name, role and tenure are
             * deliberately not among them: the whole point of scoring another
             * seat is that the seat is a different one.
             *
             * Raw values, not the display defaults `result()` applies, so a
             * blank sector prefills as blank rather than as "Not specified".
             */
            'carry_forward' => [
                'sector'          => $organisation['sector'] ?? null,
                'company_size'    => $organisation['size_band'] ?? null,
                'assessment_year' => (int) $submission['assessment_year'],
            ],
        ];
    }

    /**
     * Competency codes with at least one unanswered item.
     *
     * @return list<string>
     */
    public function missingCompetencies(array $submission): array
    {
        $rows = Database::select(sprintf(
            'SELECT c.`code`, COUNT(i.`id`) AS items, COUNT(r.`id`) AS answered
               FROM `%s` c
               JOIN `%s` i ON i.`competency_id` = c.`id`
          LEFT JOIN `%s` r ON r.`item_id` = i.`id` AND r.`submission_id` = ?
              WHERE c.`instrument_id` = ?
           GROUP BY c.`id`, c.`code`
             HAVING answered < items
           ORDER BY c.`position`',
            $this->app->table('competencies'),
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

    /**
     * The board this scorecard is about, as a director is shown it.
     *
     * Never null: a scorecard with no board named reads as "Anonymous", which
     * is what the single-file build displayed. The board lives in the
     * platform's `organisations` table, not on the submission row, and this is
     * needed while a scorecard is still in progress — the resume prompt names
     * the seat.
     */
    public function boardName(array $submission): string
    {
        return $this->organisation($submission)['name']
            ?? (string) $this->app->config('result.anonymous_board_label');
    }

    /** The marks recorded so far, keyed by item id. */
    public function responses(int $submissionId): array
    {
        $rows = Database::select(
            sprintf('SELECT `item_id`, `mark`, `evidence_note` FROM `%s` WHERE `submission_id` = ?',
                $this->app->table('submission_responses')),
            [$submissionId]
        );

        $byItem = [];
        foreach ($rows as $row) {
            $byItem[(int) $row['item_id']] = [
                'mark'          => (int) $row['mark'],
                'evidence_note' => $row['evidence_note'],
            ];
        }

        return $byItem;
    }

    /**
     * The full result payload: scores, the floor narrative, cadence, commitments.
     *
     * @return array<string, mixed>
     */
    public function result(array $submission): array
    {
        $instrumentId = (int) $submission['instrument_id'];
        $bander       = new Bander($this->rungRows($instrumentId));

        $scores = Database::select(sprintf(
            'SELECT c.`code`, c.`name`, c.`board_question`, c.`floor_narrative`,
                    s.`score`, s.`rung_value`, s.`items_answered`, c.`id` AS competency_id
               FROM `%s` c
          LEFT JOIN `%s` s ON s.`competency_id` = c.`id` AND s.`submission_id` = ?
              WHERE c.`instrument_id` = ?
           ORDER BY c.`position`',
            $this->app->table('competencies'),
            $this->app->table('submission_scores')
        ), [$submission['id'], $instrumentId]);

        $floorScore     = $submission['floor_score'] === null ? null : (float) $submission['floor_score'];
        $floorId        = $submission['floor_competency_id'] === null ? null : (int) $submission['floor_competency_id'];
        $meanScore      = $submission['mean_score'] === null ? null : (float) $submission['mean_score'];
        $floorCompetency = null;

        $competencies = [];
        foreach ($scores as $row) {
            $isFloor = $floorId !== null && (int) $row['competency_id'] === $floorId;

            if ($isFloor) {
                $floorCompetency = $row;
            }

            $competencies[] = [
                'code'           => $row['code'],
                'name'           => $row['name'],
                'board_question' => $row['board_question'],
                'score'          => $row['score'] === null ? null : (float) $row['score'],
                'rung'           => $row['score'] === null ? null : $bander->band((float) $row['score']),
                'items_answered' => (int) ($row['items_answered'] ?? 0),
                'is_floor'       => $isFloor,
            ];
        }

        $unscored = array_values(array_map(
            static fn (array $c): string => $c['name'],
            array_filter($competencies, static fn (array $c): bool => $c['score'] === null)
        ));

        $organisation = $this->organisation($submission);

        return [
            'token'      => $submission['public_token'],
            'status'     => $submission['status'],

            // Null until the director accepts the result on this page. The
            // action row reads it so a returning director is not asked to
            // confirm something they already confirmed.
            'confirmed_at' => $submission['confirmed_at'] ?? null,

            'year'       => (int) $submission['assessment_year'],
            'director'   => $submission['director_name'],
            // Never null: an unnamed board reads as "Anonymous" everywhere it
            // is shown — the results eyebrow and the PDF's seat line alike.
            'board'      => $organisation['name']
                ?? (string) $this->app->config('result.anonymous_board_label'),

            /*
             * The seat context and the footnote below are here for the
             * downloaded PDF, which is generated in the browser from this
             * payload — so the document cannot state anything the page does
             * not. The results page itself ignores them.
             */
            'seat_role'     => $submission['seat_role'],
            'tenure'        => $submission['tenure'],
            /*
             * The director's own answer first, the board's as a fallback: a
             * scorecard with no board name has no organisation row, and before
             * these columns existed that meant no sector at all.
             *
             * Then a label, so the PDF's seat line reads as a sentence; role
             * and tenure just drop out when unstated.
             */
            'sector'        => $submission['sector'] ?? $organisation['sector']
                ?? (string) $this->app->config('result.unspecified_label'),
            'company_size'  => $submission['company_size'] ?? $organisation['size_band']
                ?? (string) $this->app->config('result.unspecified_label'),
            'submitted_on'  => date(
                (string) config('app.date_format'),
                strtotime((string) ($submission['submitted_at'] ?? $submission['created_at']))
            ),
            'pdf_footnote'  => $this->pdfFootnote($bander),
            'level'      => $bander->band($floorScore),
            'floor'      => $floorScore,
            'mean'       => $meanScore,
            'spread'     => ($floorScore !== null && $meanScore !== null)
                ? round($meanScore - $floorScore, 2)
                : null,
            'competencies'       => $competencies,
            'scored_count'       => count($competencies) - count($unscored),
            'total_count'        => count($competencies),
            'unscored'           => $unscored,
            'floor_note'         => $this->floorNote($floorCompetency, $floorScore),
            'floor_narrative'    => $this->floorNarrative($floorCompetency, $floorScore),
            'cadence'            => $this->cadence($submission, $competencies),
            'commitments'        => $this->commitments((int) $submission['id']),
            'commitments_note'   => $this->app->config('result.commitments_note'),
            'benchmark'          => $this->benchmark($submission, $floorScore),

            // The raw marks, so "Review answers" can reopen a finished
            // scorecard for editing without a second round trip. The
            // server-rendered results page ignores this.
            'responses'          => $this->responses((int) $submission['id']),
        ];
    }

    /**
     * Where this director's floor sits among their peers.
     *
     * Withheld until the dataset holds enough REAL scorecards: a percentile
     * against three people is not a position, it is noise dressed as one.
     * Synthetic records are never peers — a director being told they are ahead
     * of a generated population would be told something false.
     *
     * @return array<string, mixed>|null null when there are too few peers
     */
    private function benchmark(array $submission, ?float $floorScore): ?array
    {
        if ($floorScore === null) {
            return null;
        }

        $minimum = (int) $this->app->config('result.benchmark_min_peers', 5);

        $floors = array_map(
            static fn (array $r): float => (float) $r['floor_score'],
            Database::select(sprintf(
                'SELECT `floor_score` FROM `%s`
                  WHERE `status` = \'complete\'
                    AND `is_synthetic` = 0
                    AND `floor_score` IS NOT NULL
                    AND `id` <> ?
               ORDER BY `floor_score`',
                $this->app->table('submissions')
            ), [$submission['id']])
        );

        $peers = count($floors);

        if ($peers < $minimum) {
            return ['peers' => $peers, 'minimum' => $minimum, 'reportable' => false];
        }

        $below = count(array_filter($floors, static fn (float $f): bool => $f < $floorScore));

        return [
            'peers'      => $peers,
            'minimum'    => $minimum,
            'reportable' => true,
            'percentile' => (int) round($below / $peers * 100),
            'median'     => $peers % 2 === 1
                ? $floors[intdiv($peers, 2)]
                : round(($floors[$peers / 2 - 1] + $floors[$peers / 2]) / 2, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function organisation(array $submission): array
    {
        if ($submission['organisation_id'] === null) {
            return [];
        }

        return Database::selectOne(
            'SELECT `name`, `sector`, `size_band` FROM `organisations` WHERE `id` = ?',
            [$submission['organisation_id']]
        ) ?? [];
    }

    /**
     * The foot of the downloaded PDF.
     *
     * The level legend is generated from the instrument's own rungs rather than
     * written out, so a PDF cannot describe a ladder the instrument has since
     * changed — the rungs were renamed once already.
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

    /**
     * The floor note, returned as its parts rather than one finished sentence.
     *
     * The view emphasises the competency name, as the single-file build did
     * (`Your floor is <b>Governance Oversight</b> at 2.20`). Substituting here
     * would mean either shipping HTML in a config string, or a view that has to
     * print an unescaped value to keep the markup. Handing over the parts lets
     * the view escape each one and add the emphasis itself.
     *
     * @return array{template:string, competency:string, floor:string}|null
     */
    private function floorNote(?array $floorCompetency, ?float $floorScore): ?array
    {
        if ($floorCompetency === null || $floorScore === null) {
            return null;
        }

        return [
            'template'   => (string) $this->app->config('result.floor_note'),
            'competency' => (string) $floorCompetency['name'],
            'floor'      => number_format($floorScore, 2),
        ];
    }

    /**
     * The long-form interpretation for the competency that set the level.
     *
     * Held back until now on purpose: it is part of a director's result, not
     * part of the questionnaire, so it is never served with the instrument.
     */
    private function floorNarrative(?array $floorCompetency, ?float $floorScore): ?string
    {
        if ($floorCompetency === null || $floorCompetency['floor_narrative'] === null) {
            return null;
        }

        return str_replace(
            '{floor}',
            $floorScore === null ? '—' : number_format($floorScore, 2),
            (string) $floorCompetency['floor_narrative']
        );
    }

    /** @param list<array<string, mixed>> $competencies */
    private function cadence(array $submission, array $competencies): array
    {
        $anchor    = $submission['submitted_at'] ?? $submission['created_at'] ?? date('Y-m-d H:i:s');
        $threshold = (float) $this->app->config('scoring.practiced_threshold', 2.75);
        $months    = (array) $this->app->config('scoring.cadence', ['full' => 12, 'below_practiced' => 3]);

        $low = array_values(array_map(
            static fn (array $c): string => $c['code'],
            array_filter(
                $competencies,
                static fn (array $c): bool => $c['score'] !== null && $c['score'] < $threshold
            )
        ));

        return [
            'full' => [
                'due'  => $this->addMonths($anchor, (int) ($months['full'] ?? 12)),
                'note' => 'Re-score all seven every year, alongside the board evaluation.',
            ],
            'sooner' => [
                'due'          => $low === [] ? null : $this->addMonths($anchor, (int) ($months['below_practiced'] ?? 3)),
                'competencies' => $low,
                'note'         => $low === []
                    ? 'Nothing sits below Practiced.'
                    : implode(', ', $low) . ' sit below Practiced. Score them again in three months.',
            ],
            'on_incident' => [
                'due'  => 'immediately',
                'note' => 'Re-score after any material AI incident at the company. What you did in '
                        . 'the week after it is the real test of this scorecard.',
            ],
        ];
    }

    private function addMonths(string $date, int $months): string
    {
        return (new \DateTimeImmutable($date))
            ->modify("+{$months} months")
            ->format('Y-m-d');
    }

    /* ------------------------------------------------------------------ */
    /* Commitments                                                         */
    /* ------------------------------------------------------------------ */

    /** @return list<array{key:string,label:string,checked:bool}> */
    public function commitments(int $submissionId): array
    {
        $checked = [];
        foreach (Database::select(
            sprintf('SELECT `commitment_key`, `is_checked` FROM `%s` WHERE `submission_id` = ?',
                $this->app->table('submission_commitments')),
            [$submissionId]
        ) as $row) {
            $checked[$row['commitment_key']] = (int) $row['is_checked'] === 1;
        }

        $defined = (array) $this->app->config('result.commitments', []);

        return array_map(
            static fn (array $c): array => [
                'key'     => $c['key'],
                'label'   => $c['label'],
                'checked' => $checked[$c['key']] ?? false,
            ],
            $defined
        );
    }

    /** @param array<string, bool> $values key => checked */
    public function setCommitments(int $submissionId, array $values): void
    {
        $allowed = array_column((array) $this->app->config('result.commitments', []), 'key');
        $table   = $this->app->table('submission_commitments');

        foreach ($values as $key => $checked) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            Database::statement(
                sprintf(
                    'INSERT INTO `%s` (`submission_id`, `commitment_key`, `is_checked`)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE `is_checked` = VALUES(`is_checked`)',
                    $table
                ),
                [$submissionId, $key, $checked ? 1 : 0]
            );
        }
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
            'SELECT i.`id` FROM `%s` i JOIN `%s` c ON c.`id` = i.`competency_id` WHERE c.`instrument_id` = ?',
            $this->app->table('items'),
            $this->app->table('competencies')
        ), [$instrumentId]);

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['id']] = true;
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function competencies(int $instrumentId): array
    {
        return Database::select(
            sprintf('SELECT `id`, `code` FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
                $this->app->table('competencies')),
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
