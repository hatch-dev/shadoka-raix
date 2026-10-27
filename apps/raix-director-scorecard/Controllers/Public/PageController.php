<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Controllers\Public;

use Apps\RaixDirectorScorecard\Services\InstrumentPayload;
use Apps\RaixDirectorScorecard\Services\SubmissionService;
use Platform\Core\Controller;
use Platform\Core\Database;
use Platform\Core\Response;
use Platform\Core\View;

/**
 * The public pages.
 *
 * Every page is rendered server-side from the published instrument, so the
 * seven competencies, their board questions and the four rungs exist in exactly
 * one place — the database — instead of being duplicated between the markup and
 * a JavaScript array as they were in the legacy build.
 */
final class PageController extends Controller
{
    private function view(): View
    {
        return (new View($this->app))
            ->share('slug', $this->app->slug);
    }

    private function service(): SubmissionService
    {
        return new SubmissionService($this->app);
    }

    /** GET / — the overview. */
    public function hero(): Response
    {
        $instrument = $this->service()->publishedInstrument();

        $competencies = Database::select(
            sprintf(
                'SELECT `id`, `code`, `name`, `board_question`, `failure_line`
                   FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
                $this->table('competencies')
            ),
            [$instrument['id']]
        );

        $rungs = Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`', $this->table('rungs')),
            [$instrument['id']]
        );

        $itemCount = (int) Database::scalar(
            sprintf(
                'SELECT COUNT(*) FROM `%s` i
                   JOIN `%s` c ON c.`id` = i.`competency_id`
                  WHERE c.`instrument_id` = ?',
                $this->table('items'),
                $this->table('competencies')
            ),
            [$instrument['id']]
        );

        return $this->view()->response('public/hero', [
            'title'        => 'RAI-X · ' . ($instrument['subtitle'] ?? 'The Director Scorecard'),
            'description'  => 'A self-assessment for an individual board member across '
                            . count($competencies) . ' Responsible AI competencies.',
            'active'       => 'hero',
            'instrument'   => $instrument,
            'competencies' => $competencies,
            'rungs'        => $rungs,
            'itemCount'    => $itemCount,
        ]);
    }

    /**
     * GET /assess — the assessment.
     *
     * The page ships empty and is filled by js/assess.js from the instrument
     * endpoint, so the questionnaire has exactly one source.
     */
    public function assess(): Response
    {
        /*
         * The instrument is inlined, not fetched. Fetching it meant the page
         * painted its header and footer against an empty body and only filled
         * in half a second later, which read as a broken page rather than a
         * loading one. The same builder feeds /api/instrument, so the two
         * cannot disagree.
         */
        $instrument = (new InstrumentPayload($this->app))->build();

        return $this->view()->response('public/assess', [
            'title'      => 'Score yourself · RAI-X Director Scorecard',
            'active'     => 'assess',
            'instrument' => $instrument,
        ]);
    }

    /**
     * GET /results/{token} — a director's own result.
     *
     * The token is the only credential. An unknown one gets the same page as an
     * expired one, with no hint about whether it ever existed.
     */
    public function results(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->view()->response('public/not-found', [
                'title' => 'Scorecard not found · RAI-X',
            ], 404);
        }

        if ($submission['status'] !== 'complete') {
            return $this->redirect($this->app->slug . '/assess?resume=' . urlencode($token));
        }

        return $this->view()->response('public/results', [
            'title'  => 'Your result · RAI-X Director Scorecard',
            'active' => 'results',
            'result' => $this->service()->result($submission),
        ]);
    }
}
