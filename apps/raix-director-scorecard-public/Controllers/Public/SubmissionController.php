<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Public;

use Apps\RaixDirectorScorecardPublic\Services\RespondentIdentity;
use Apps\RaixDirectorScorecardPublic\Services\SubmissionService;
use Platform\Core\Controller;
use Platform\Core\Database;
use Platform\Core\RateLimiter;
use Platform\Core\Response;
use Platform\Validation\Validator;

/**
 * The public scorecard endpoints.
 *
 * No authentication: a director reaches their own result with an unguessable
 * token. This edition asks for their name and contact details, but still no
 * account — an account holding an unflattering self-assessment would be both a
 * worse experience and a larger breach.
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

        if (RateLimiter::tooManyAttempts('dsp.submission.start', $this->request->ipHash(), $limit, 3600)) {
            return $this->fail('Too many scorecards started from here. Try again later.', 429);
        }

        $checked = $this->checkProfile();

        if ($checked instanceof Response) {
            return $checked;
        }

        $started = $this->service()->start($checked, $this->request->ipHash());

        return $this->ok([
            'token'  => $started['token'],
            'status' => 'in_progress',
        ], ['message' => 'Scorecard started.']);
    }

    /**
     * The profile, checked — or the Response explaining why it was not.
     *
     * Shared by start() and by save(), which takes a profile when the director
     * pressed Back to step 0 and changed something. Both must apply exactly the
     * same rules: an edit that skipped the consent gate or the identity checks
     * would put into the record precisely what start() refuses.
     *
     * @param  array<string, mixed>|null $body the profile fields; the whole request body when null
     * @return array<string, mixed>|Response
     */
    private function checkProfile(?array $body = null): array|Response
    {
        $body ??= $this->request->body;

        $reference = $this->referenceValues();
        $year      = (int) date('Y');

        /*
         * Types and lengths first, then the identity rules. The five required
         * fields are `nullable` here and required by RespondentIdentity, so a
         * blank name comes back as the reference's "Enter your full name." next
         * to the field rather than as "Director name is required."
         */
        $validator = Validator::make($body, [
            'director_name'   => 'nullable|string|max:120',
            'director_email'  => 'nullable|string|max:254',
            'director_phone'  => 'nullable|string|max:40',
            'board_name'      => 'nullable|string|max:191',
            'company_size'    => 'nullable|string|max:50',
            'seat_role'       => 'nullable|string|max:100',
            'tenure'          => 'nullable|string|max:50',
            'sector'          => 'nullable|string|max:100',
            'assessment_year' => 'required|integer|between:' . ($year - 5) . ',' . ($year + 1),
            'systems_seen'    => 'nullable|integer|between:0,10000',
            'board_seats'     => 'nullable|integer|between:1,100',
            'agenda_meetings' => 'nullable|integer|between:0,100',
            'education_hours' => 'nullable|numeric|between:0,2000',
            'remit'           => 'nullable|array',
            'consent'         => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->fail((string) $validator->firstError(), 422, $validator->errors());
        }

        $profile = $validator->validated();

        /*
         * The consent gate, enforced. In the reference it was a checkbox whose
         * only consequence was a clickable button. Here nothing is written until
         * the server has been told the terms were accepted: otherwise the record
         * would hold contact details collected under terms nobody agreed to.
         */
        if (($profile['consent'] ?? false) !== true) {
            return $this->fail(
                'The terms have to be accepted before the scorecard can begin.',
                422,
                ['consent' => ['The terms have to be accepted before the scorecard can begin.']]
            );
        }

        $identityErrors = RespondentIdentity::check($profile, $reference['company_sizes'] ?? []);

        if ($identityErrors !== []) {
            return $this->fail(
                (string) reset($identityErrors),
                422,
                array_map(static fn (string $message): array => [$message], $identityErrors)
            );
        }

        foreach (['director_name', 'director_email', 'director_phone'] as $field) {
            $profile[$field] = trim((string) $profile[$field]);
        }

        // Optional: a blank is stored as NULL, not as an empty string.
        foreach (['board_name', 'company_size'] as $field) {
            $value = trim((string) ($profile[$field] ?? ''));
            $profile[$field] = $value === '' ? null : $value;
        }

        // The optional dropdowns are checked against the instrument's own
        // reference lists, so a client cannot invent a sector and pollute the
        // sector breakdown with a value nothing else uses.
        foreach (['sector' => 'sectors', 'seat_role' => 'roles', 'tenure' => 'tenures'] as $field => $group) {
            if (!empty($profile[$field]) && !in_array($profile[$field], $reference[$group] ?? [], true)) {
                return $this->fail(ucfirst(str_replace('_', ' ', $field)) . ' is not one of the listed options.', 422);
            }
        }

        $profile['remit'] = $this->validRemitCodes($profile['remit'] ?? []);

        return $profile;
    }

    /* ------------------------------------------------------------------ */

    /**
     * PATCH /api/submissions/{token} — save marks as the director goes, and the
     * profile when they pressed Back to step 0 and changed it.
     *
     * A profile edit comes here rather than to start() because the scorecard
     * already exists: posting it again would open a SECOND record under a new
     * token and strand every answer already saved against the first.
     */
    public function save(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        $profile = $this->request->input('profile');

        if ($profile !== null) {
            if (!is_array($profile)) {
                return $this->fail('Send a "profile" object.', 422);
            }

            // Contact details do not move under a finished, reported result.
            if (($submission['status'] ?? '') !== 'in_progress') {
                return $this->fail('That scorecard is already complete.', 422);
            }

            $checked = $this->checkProfile($profile);

            if ($checked instanceof Response) {
                return $checked;
            }

            $this->service()->updateProfile($submission, $checked);
        }

        $responses = $this->request->input('responses') ?? [];

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
            // So Back to step 0 on a RESUMED scorecard shows what was typed,
            // rather than an empty form whose required fields block Continue.
            // The token is the credential, and these are the holder's own.
            'profile'    => $this->service()->profile($submission),
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
