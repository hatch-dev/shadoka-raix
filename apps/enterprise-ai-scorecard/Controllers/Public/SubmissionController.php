<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Controllers\Public;

use Apps\EnterpriseAiScorecard\Services\RespondentIdentity;
use Apps\EnterpriseAiScorecard\Services\SubmissionService;
use Platform\Core\Controller;
use Platform\Core\Database;
use Platform\Core\RateLimiter;
use Platform\Core\Response;
use Platform\Validation\Validator;

/**
 * The public scorecard endpoints.
 *
 * No authentication. A respondent reaches their own result with an unguessable
 * token, which is deliberate: the alternative is asking a CIO to create an
 * account holding an unflattering assessment of their own employer, which is both
 * a worse experience and a larger breach.
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

        if (RateLimiter::tooManyAttempts('es.submission.start', $this->request->ipHash(), $limit, 3600)) {
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
     * Shared by start() and by save(), which takes a profile when the respondent
     * pressed Back to step 0 and changed something. The two must apply exactly
     * the same rules: an edit that bypassed the consent gate or the reference
     * lists would put into the record precisely what start() refuses to.
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
         * Types and lengths first, then the identity rules.
         *
         * The five required fields are declared `nullable` here and required by
         * RespondentIdentity, so that a blank name comes back as "Enter your full
         * name." rather than as "Respondent name is required." — the reference's
         * own wording, next to the field it belongs to.
         */
        $validator = Validator::make($body, [
            'respondent_name'   => 'nullable|string|max:120',
            'respondent_email'  => 'nullable|string|max:254',
            'respondent_phone'  => 'nullable|string|max:40',
            'organisation_name' => 'nullable|string|max:191',
            'organisation_size' => 'nullable|string|max:50',
            'role'              => 'nullable|string|max:100',
            'tenure'            => 'nullable|string|max:50',
            'sector'            => 'nullable|string|max:100',
            'assessment_year'   => 'required|integer|between:' . ($year - 5) . ',' . ($year + 1),
            'systems_inventory' => 'nullable|integer|between:0,100000',
            'agenda_meetings'   => 'nullable|integer|between:0,1000',
            'education_hours'   => 'nullable|numeric|between:0,5000',
            'consent'           => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->fail((string) $validator->firstError(), 422, $validator->errors());
        }

        $profile = $validator->validated();

        /*
         * The consent gate, enforced.
         *
         * In the reference this was a checkbox whose only consequence was that a
         * button became clickable, in the browser. Here nothing is written until
         * the server has been told it was accepted — otherwise the record would
         * hold contact details collected under terms the respondent never agreed
         * to, which is the one thing the gate exists to prevent.
         */
        if (($profile['consent'] ?? false) !== true) {
            return $this->fail(
                'The terms have to be accepted before the scorecard can begin.',
                422,
                ['consent' => ['The terms have to be accepted before the scorecard can begin.']]
            );
        }

        $identityErrors = RespondentIdentity::check($profile, $reference['organisation_sizes'] ?? []);

        if ($identityErrors !== []) {
            return $this->fail(
                (string) reset($identityErrors),
                422,
                array_map(static fn (string $message): array => [$message], $identityErrors)
            );
        }

        // The optional dropdowns are checked against the instrument's own
        // reference lists, so a client cannot invent a sector and pollute the
        // sector breakdown with a value nothing else uses.
        foreach (['sector' => 'sectors', 'role' => 'roles', 'tenure' => 'tenures'] as $field => $group) {
            if (!empty($profile[$field]) && !in_array($profile[$field], $reference[$group] ?? [], true)) {
                return $this->fail(
                    ucfirst(str_replace('_', ' ', $field)) . ' is not one of the listed options.',
                    422
                );
            }
        }

        return $profile;
    }

    /* ------------------------------------------------------------------ */

    /**
     * PATCH /api/submissions/{token} — save marks as the respondent goes, and
     * the profile when they pressed Back to step 0 and changed it.
     *
     * Both parts are optional and either may be sent alone. A profile edit
     * comes here rather than to start() because the scorecard already exists:
     * posting it again would open a SECOND record under a new token and strand
     * every answer already saved against the first.
     */
    public function save(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        /*
         * A finished scorecard does not reopen its profile here. submit() is what
         * closes one, and review() is what lets answers change afterwards; letting
         * contact details move under a completed record would change who a result
         * already reported belongs to.
         */
        $profile = $this->request->input('profile');

        if ($profile !== null) {
            if (!is_array($profile)) {
                return $this->fail('Send a "profile" object.', 422);
            }

            if (($submission['status'] ?? '') !== 'in_progress') {
                return $this->fail('That scorecard is already complete.', 422);
            }

            $checked = $this->checkProfile($profile);

            if ($checked instanceof Response) {
                return $checked;
            }

            $this->service()->updateProfile($submission, $checked);
        }

        $responses = $this->request->input('responses');

        if ($responses === null) {
            $responses = [];
        }

        if (!is_array($responses)) {
            return $this->fail('Send a "responses" list.', 422);
        }

        $result  = $this->service()->saveProgress($submission, $responses);
        $missing = $this->service()->missingDimensions($submission);

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
                'Every dimension must be scored before the scorecard can be finished.',
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
            'token'  => $submission['public_token'],
            'status' => $submission['status'],
            // The resume prompt names the organisation being scored.
            'organisation' => $submission['organisation_name'],
            'responses'    => $this->service()->responses((int) $submission['id']),
            'incomplete'   => $this->service()->missingDimensions($submission),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * POST /api/submissions/{token}/confirm — the respondent accepts the result.
     *
     * Only a complete scorecard can be accepted: there is nothing to stand behind
     * until it has been scored.
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
            'message' => $confirmed['already_confirmed'] ? 'Already saved.' : 'Scorecard saved.',
        ]);
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
}
