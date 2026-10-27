<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Services;

use Apps\AiExecutiveReadinessScorecard\Services\Scoring\Scorer;
use Platform\Assessment\OrganisationResolver;
use Platform\Core\AppManifest;
use Platform\Core\AppRegistry;
use Platform\Core\Database;

/**
 * Everything a submission does, between the controller and the database.
 *
 * The contract with the browser: it sends RATINGS — a question, which of the
 * two scopes, and a number. It never sends a score, a level or a difference;
 * those are derived here by the Scorer. In the reference build the browser
 * computed and stored its own record, so any client could assert any score.
 */
final class SubmissionService
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    private function data(): InstrumentData
    {
        return new InstrumentData($this->app);
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
         * typed name is ALSO kept on the submission: the deduplicated entity is
         * what joins this dataset to any other app's, and the typed name is the
         * self-report this instrument actually collected.
         */
        $organisationId = OrganisationResolver::resolve(
            $profile['organisation_name'] ?? null,
            $profile['sector'] ?? null,
            $profile['organisation_size'] ?? null,
        );

        $token   = bin2hex(random_bytes(16));
        $agreed  = ($profile['consent'] ?? false) === true;

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

            // What they agreed to, and when. The URL is stored so a later
            // revision of the terms cannot be read back onto this consent.
            'consent_agreed_at' => $agreed ? date('Y-m-d H:i:s') : null,
            'consent_terms_url' => $agreed ? (string) $this->app->config('result.consent.terms_url') : null,

            'status'            => 'in_progress',
            'is_synthetic'      => 0,
            'ip_hash'           => $ipHash,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'id' => $id];
    }

    /**
     * Rewrite the profile of a scorecard already open — what Back to step 0
     * leads to. Updating in place rather than start()ing a second record, which
     * would strand every rating already saved under the first token. The consent
     * columns are not touched: an edit is not a fresh agreement.
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
     * Record ratings. Partial by design — the questionnaire saves as it goes.
     *
     * The reference kept a whole scorecard in sessionStorage and lost it when
     * the tab closed. Here every rating is written as it is given.
     *
     * @param list<array{item_id:int, scope:string, mark:int|null}> $responses
     * @return array{saved:int, cleared:int}
     */
    public function saveProgress(array $submission, array $responses): array
    {
        $valid = $this->data()->itemIndex((int) $submission['instrument_id']);
        $table = $this->app->table('submission_responses');
        $now   = date('Y-m-d H:i:s');

        $saved   = 0;
        $cleared = 0;

        Database::transaction(function () use ($responses, $valid, $submission, $table, $now, &$saved, &$cleared): void {
            foreach ($responses as $response) {
                if (!is_array($response)) {
                    continue;
                }

                $itemId = (int) ($response['item_id'] ?? 0);
                $scope  = $response['scope'] ?? null;

                // A question from another instrument (or another app), or a
                // scope that is not one of the two, never attaches here.
                if (!isset($valid[$itemId]) || !Scopes::valid($scope)) {
                    continue;
                }

                $mark = $response['mark'] ?? null;

                // A cleared rating deletes its row rather than storing a zero:
                // unrated is not the same as 1.
                if ($mark === null || $mark === '') {
                    $cleared += Database::statement(
                        sprintf('DELETE FROM `%s` WHERE `submission_id` = ? AND `item_id` = ? AND `scope` = ?', $table),
                        [$submission['id'], $itemId, $scope]
                    );
                    continue;
                }

                if (!is_int($mark) && !(is_string($mark) && ctype_digit($mark))) {
                    continue;
                }

                $mark = (int) $mark;

                if ($mark < 1 || $mark > 5) {
                    continue;
                }

                Database::statement(
                    sprintf(
                        'INSERT INTO `%s` (`submission_id`, `item_id`, `scope`, `mark`, `answered_at`)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE `mark` = VALUES(`mark`),
                                                 `answered_at` = VALUES(`answered_at`)',
                        $table
                    ),
                    [$submission['id'], $itemId, $scope, $mark, $now]
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
     * All twenty-four ratings are required — the reference's makeRecord() threw
     * "All 24 ratings are required." and its nextDim() refused to advance past
     * a question missing either rating.
     *
     * @return array{missing: list<string>}
     */
    public function submit(array $submission): array
    {
        $missing = $this->missingDimensions($submission);

        if ($missing !== []) {
            return ['missing' => $missing];
        }

        (new Scorer($this->app))->score((int) $submission['id']);

        Database::statement(
            sprintf(
                'UPDATE `%s` SET `status` = ?, `submitted_at` = COALESCE(`submitted_at`, ?), `updated_at` = ?
                  WHERE `id` = ?',
                $this->app->table('submissions')
            ),
            ['complete', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $submission['id']]
        );

        Records::flush();

        return ['missing' => []];
    }

    /**
     * Delete an UNFINISHED scorecard — what "Start over" does with the one it
     * abandons.
     *
     * "Start over" throws away the only link to the draft (the token in this
     * browser), so without this the row would sit in the database for good: a
     * name, an email address and a phone number nobody can reach again. A
     * completed scorecard is research data and is NEVER removed this way; the
     * status is re-checked inside the DELETE itself. The organisation row goes
     * too, but only when no scorecard in ANY app still points at it.
     *
     * Audited as the respondent with no personal details, written directly
     * rather than through Auth::audit(), which would start a session and issue
     * an anonymous respondent a cookie.
     */
    public function discard(array $submission, ?string $ipHash = null): bool
    {
        $deleted = 0;

        Database::transaction(function () use ($submission, $ipHash, &$deleted): void {
            $marks = (int) Database::scalar(
                sprintf('SELECT COUNT(*) FROM `%s` WHERE `submission_id` = ?', $this->app->table('submission_responses')),
                [(int) $submission['id']]
            );

            $deleted = Database::statement(
                sprintf("DELETE FROM `%s` WHERE `id` = ? AND `status` = 'in_progress'", $this->app->table('submissions')),
                [(int) $submission['id']]
            );

            if ($deleted === 0) {
                return;
            }

            Database::insert('audit_log', [
                'admin_user_id' => null,
                'actor_label'   => 'respondent',
                'app'           => $this->app->slug,
                'action'        => 'exr.submission.discarded',
                'subject_type'  => 'submission',
                'subject_id'    => (string) $submission['id'],
                'meta'          => json_encode(['reason' => 'start_over', 'marks' => $marks], JSON_THROW_ON_ERROR),
                'ip_hash'       => $ipHash,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $organisationId = $submission['organisation_id'] ?? null;

            if ($organisationId === null) {
                return;
            }

            $unused = [];
            foreach (AppRegistry::all() as $app) {
                if (Database::hasTable($app->table('submissions'))) {
                    $unused[] = sprintf(
                        'NOT EXISTS (SELECT 1 FROM `%s` WHERE `organisation_id` = o.`id`)',
                        $app->table('submissions')
                    );
                }
            }

            Database::statement(
                'DELETE o FROM `organisations` o WHERE o.`id` = ?' . ($unused === [] ? '' : ' AND ' . implode(' AND ', $unused)),
                [(int) $organisationId]
            );
        });

        return $deleted > 0;
    }

    /**
     * Dimension codes with at least one rating missing, in either scope.
     *
     * @return list<string>
     */
    public function missingDimensions(array $submission): array
    {
        $rows = Database::select(sprintf(
            'SELECT d.`code`, COUNT(DISTINCT i.`id`) * 2 AS needed, COUNT(r.`id`) AS answered
               FROM `%s` d
               JOIN `%s` i ON i.`dimension_id` = d.`id`
          LEFT JOIN `%s` r ON r.`item_id` = i.`id` AND r.`submission_id` = ?
              WHERE d.`instrument_id` = ?
           GROUP BY d.`id`, d.`code`, d.`position`
             HAVING answered < needed
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

    /**
     * The ratings recorded so far, as a flat list the questionnaire can put back.
     *
     * @return list<array{item_id:int, scope:string, mark:int}>
     */
    public function answers(int $submissionId): array
    {
        return array_map(
            static fn (array $row): array => [
                'item_id' => (int) $row['item_id'],
                'scope'   => (string) $row['scope'],
                'mark'    => (int) $row['mark'],
            ],
            Database::select(
                sprintf('SELECT `item_id`, `scope`, `mark` FROM `%s` WHERE `submission_id` = ? ORDER BY `id`',
                    $this->app->table('submission_responses')),
                [$submissionId]
            )
        );
    }

    /**
     * The full result: both profiles, the difference, the questions they
     * answer, and everything the page, the PDF and the admin record dialog need
     * to state them — the reference's renderResults(rec) and exportPDF(rec)
     * inputs, in one payload.
     *
     * @return array<string, mixed>
     */
    public function result(array $submission): array
    {
        $data         = $this->data();
        $instrumentId = (int) $submission['instrument_id'];
        $instrument   = $data->instrument($instrumentId);
        $bander       = $data->bander($instrumentId);
        $record       = (new Records($this->app))->fromRow($submission);

        $level = static function (?string $name) use ($bander): ?array {
            foreach ($bander->rungs() as $rung) {
                if ($rung['name'] === $name) {
                    return $rung;
                }
            }

            return null;
        };

        $scores = [];
        foreach ($record['scores'] as $scope => $profile) {
            $scores[$scope] = [
                'dims'      => $profile['dims'],
                'mean'      => $profile['mean'],
                'floor'     => $profile['floor'],
                'floorCode' => $profile['floorCode'],
                'level'     => $level($profile['level']),
            ];
        }

        return [
            'token'        => $submission['public_token'],
            'status'       => $submission['status'],
            'synthetic'    => (bool) $submission['is_synthetic'],
            'year'         => (int) $submission['assessment_year'],
            'respondent'   => $submission['respondent_name'] ?: (string) $this->app->config('result.anonymous_label'),
            'email'        => $submission['respondent_email'],
            'phone'        => $submission['respondent_phone'],
            'organisation' => $submission['organisation_name'],
            'size'         => $submission['organisation_size'],
            'role'         => $submission['role'],
            'tenure'       => $submission['tenure'],
            'sector'       => $record['org']['sector'],
            'timestamp'    => $record['timestamp'],
            'submitted_on' => date(
                (string) config('app.date_format'),
                strtotime((string) ($submission['submitted_at'] ?? $submission['created_at']))
            ),

            'scores'     => $scores,
            'difference' => $record['difference'],
            'responses'  => $record['responses'],

            // For "Review answers": the same ratings as a flat list, which is
            // what the questionnaire puts back into its buttons.
            'answers' => $this->answers((int) $submission['id']),

            'dimensions' => array_map(
                static fn (array $d): array => [
                    'code'  => $d['code'],
                    'name'  => $d['name'],
                    'items' => array_map(
                        static fn (array $item): array => ['label' => $item['label'], 'question' => $item['question']],
                        $d['items']
                    ),
                ],
                $data->dimensions($instrumentId)
            ),
            'rungs'  => $bander->rungs(),
            'scopes' => Scopes::all(),
            'text'   => [
                'instructions'    => $instrument['instructions'],
                'comparison_note' => $instrument['comparison_note'],
                'disclaimer'      => $instrument['disclaimer'],
            ],
            'pdf_footnote' => (string) $this->app->config('result.pdf_footnote'),
            'copyright'    => (string) config('app.copyright'),
        ];
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function publishedInstrument(): array
    {
        return $this->data()->published();
    }
}
