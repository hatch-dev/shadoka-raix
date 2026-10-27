<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Controllers\Public;

use Apps\RaixDirectorScorecard\Services\SubmissionService;
use Platform\Core\Controller;
use Platform\Core\Database;
use Platform\Core\RateLimiter;
use Platform\Core\Response;
use Platform\Validation\Validator;

/**
 * The public scorecard endpoints.
 *
 * No authentication: a director scores anonymously and reaches their own
 * result with an unguessable token. That is deliberate — the alternative is
 * asking board members to create accounts holding an unflattering
 * self-assessment, which is both a worse experience and a larger breach.
 *
 * The token IS the credential, so every handler resolves it first and nothing
 * accepts a submission id from the client.
 */
final class SubmissionController extends Controller
{
    private function service(): SubmissionService
    {
        return new SubmissionService($this->app);
    }

    /* ------------------------------------------------------------------ */

    /** POST /api/submissions — open a scorecard, return its token. */
    public function start(): Response
    {
        $limit = (int) $this->appConfig('scoring.start_rate_limit', 20);

        if (RateLimiter::tooManyAttempts('ds.submission.start', $this->request->ipHash(), $limit, 3600)) {
            return $this->fail('Too many scorecards started from here. Try again later.', 429);
        }

        $reference = $this->referenceValues();
        $year      = (int) date('Y');

        $validator = Validator::make($this->request->body, [
            'board_name'      => 'nullable|string|max:191',
            'director_name'   => 'nullable|string|max:120',
            'seat_role'       => 'nullable|string|max:100',
            'tenure'          => 'nullable|string|max:50',
            'sector'          => 'nullable|string|max:100',
            'company_size'    => 'nullable|string|max:50',
            'assessment_year' => 'required|integer|between:' . ($year - 5) . ',' . ($year + 1),
            'systems_seen'    => 'nullable|integer|between:0,10000',
            'board_seats'     => 'nullable|integer|between:1,100',
            'agenda_meetings' => 'nullable|integer|between:0,100',
            'education_hours' => 'nullable|numeric|between:0,2000',
            'remit'           => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->fail((string) $validator->firstError(), 422, $validator->errors());
        }

        $profile = $validator->validated();

        // Free-text dropdowns are checked against the instrument's own
        // reference lists, so a client cannot invent a sector and pollute the
        // sector breakdown with a value nothing else uses.
        foreach (['sector' => 'sectors', 'company_size' => 'company_sizes',
                  'seat_role' => 'roles', 'tenure' => 'tenures'] as $field => $group) {
            if (!empty($profile[$field]) && !in_array($profile[$field], $reference[$group] ?? [], true)) {
                return $this->fail(ucfirst(str_replace('_', ' ', $field)) . ' is not one of the listed options.', 422);
            }
        }

        $profile['remit'] = $this->validRemitCodes($profile['remit'] ?? []);

        $started = $this->service()->start($profile, $this->request->ipHash());

        return $this->ok([
            'token'  => $started['token'],
            'status' => 'in_progress',
        ], ['message' => 'Scorecard started.']);
    }

    /* ------------------------------------------------------------------ */

    /** PATCH /api/submissions/{token} — save marks as the director goes. */
    public function save(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        $responses = $this->request->input('responses');

        if (!is_array($responses)) {
            return $this->fail('Send a "responses" list.', 422);
        }

        $result  = $this->service()->saveProgress($submission, $responses);
        $missing = $this->service()->missingCompetencies($submission);

        return $this->ok([
            'saved'      => $result['saved'],
            'cleared'    => $result['cleared'],
            'incomplete' => $missing,
            'complete'   => $missing === [],
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** POST /api/submissions/{token}/submit — finalise; the server scores it. */
    public function submit(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        $outcome = $this->service()->submit($submission);

        if ($outcome['missing'] !== []) {
            return $this->fail(
                'Every competency must be scored before the scorecard can be finished.',
                422,
                ['incomplete' => $outcome['missing']]
            );
        }

        // Re-read: submit() wrote the derived columns the result is built from.
        $scored = $this->service()->findByToken($token);

        return $this->ok($this->service()->result($scored));
    }

    /* ------------------------------------------------------------------ */

    /**
     * GET /api/submissions/{token} — reopen a scorecard.
     *
     * In progress: returns the marks so far so the assessment can resume.
     * Complete: returns the full result.
     */
    public function show(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        if ($submission['status'] === 'complete') {
            return $this->ok($this->service()->result($submission));
        }

        return $this->ok([
            'token'      => $submission['public_token'],
            'status'     => $submission['status'],
            // The resume prompt names the seat being scored.
            'board'      => $this->service()->boardName($submission),
            'responses'  => $this->service()->responses((int) $submission['id']),
            'incomplete' => $this->service()->missingCompetencies($submission),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * POST /api/submissions/{token}/confirm — the director accepts the result.
     *
     * Only a complete scorecard can be accepted: there is nothing to stand
     * behind until it has been scored.
     */
    public function confirm(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        if ($submission['status'] !== 'complete') {
            return $this->fail('This scorecard has not been finished yet.', 409);
        }

        $confirmed = $this->service()->confirm($submission);

        return $this->ok($confirmed, [
            'message' => $confirmed['already_confirmed']
                ? 'Already saved.'
                : 'Scorecard saved.',
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** PATCH /api/submissions/{token}/commitments — what happens before the next meeting. */
    public function commitments(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        $values = $this->request->input('commitments');

        if (!is_array($values)) {
            return $this->fail('Send a "commitments" object.', 422);
        }

        $this->service()->setCommitments(
            (int) $submission['id'],
            array_map(static fn ($v): bool => in_array($v, [true, 1, '1', 'true', 'on'], true), $values)
        );

        return $this->ok(['commitments' => $this->service()->commitments((int) $submission['id'])]);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, list<string>> */
    private function referenceValues(): array
    {
        $instrument = $this->service()->publishedInstrument();

        $grouped = [];
        foreach (Database::select(
            sprintf('SELECT `group_key`, `value` FROM `%s` WHERE `instrument_id` = ? AND `is_active` = 1',
                $this->table('reference_options')),
            [$instrument['id']]
        ) as $row) {
            $grouped[$row['group_key']][] = $row['value'];
        }

        return $grouped;
    }

    /**
     * Keep only real competency codes.
     *
     * Remit is recorded as context and never changes what a director is asked
     * to score — the instrument is explicit that remit is not an exemption.
     *
     * @return list<string>
     */
    private function validRemitCodes(mixed $remit): array
    {
        if (!is_array($remit) || $remit === []) {
            return [];
        }

        $instrument = $this->service()->publishedInstrument();

        $codes = array_column(
            Database::select(
                sprintf('SELECT `code` FROM `%s` WHERE `instrument_id` = ?', $this->table('competencies')),
                [$instrument['id']]
            ),
            'code'
        );

        return array_values(array_intersect(array_map('strval', $remit), $codes));
    }
}
