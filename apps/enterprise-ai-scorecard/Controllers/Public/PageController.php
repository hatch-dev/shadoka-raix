<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Controllers\Public;

use Apps\EnterpriseAiScorecard\Services\InstrumentPayload;
use Apps\EnterpriseAiScorecard\Services\SubmissionService;
use Platform\Core\Controller;
use Platform\Core\Database;
use Platform\Core\Response;
use Platform\Core\View;

/**
 * The public pages.
 *
 * Every page is rendered server-side from the published instrument, so the six
 * dimensions, their one-line summaries and the five levels exist in exactly one
 * place — the database — instead of being duplicated between the markup and a
 * JavaScript array as they were in the reference build.
 */
final class PageController extends Controller
{
    private function view(): View
    {
        return (new View($this->app))->share('slug', $this->app->slug);
    }

    private function service(): SubmissionService
    {
        return new SubmissionService($this->app);
    }

    /** GET / — the overview. */
    public function hero(): Response
    {
        $instrument = $this->service()->publishedInstrument();

        $dimensions = Database::select(
            sprintf(
                'SELECT `id`, `code`, `name`, `summary_line`, `pressing_question`
                   FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
                $this->table('dimensions')
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
                   JOIN `%s` d ON d.`id` = i.`dimension_id`
                  WHERE d.`instrument_id` = ?',
                $this->table('items'),
                $this->table('dimensions')
            ),
            [$instrument['id']]
        );

        return $this->view()->response('public/hero', [
            'title'       => 'AI Readiness · ' . ($instrument['subtitle'] ?? 'The Enterprise Scorecard'),
            'description' => 'A self-assessment of an organisation\'s readiness for AI across '
                           . count($dimensions) . ' dimensions.',
            'active'      => 'hero',
            'instrument'  => $instrument,
            'dimensions'  => $dimensions,
            'rungs'       => $rungs,
            'itemCount'   => $itemCount,
        ]);
    }

    /**
     * GET /assess — the assessment.
     *
     * The instrument is inlined, not fetched. Fetching it meant the page painted
     * its header and footer against an empty body and only filled in half a
     * second later, which reads as a broken page rather than a loading one. The
     * same builder feeds /api/instrument, so the two cannot disagree.
     */
    public function assess(): Response
    {
        $instrument = (new InstrumentPayload($this->app))->build();

        return $this->view()->response('public/assess', [
            'title'      => 'Score your organization · The Enterprise Scorecard',
            'active'     => 'assess',
            'instrument' => $instrument,
        ]);
    }

    /**
     * GET /results/{token} — a respondent's own result.
     *
     * The token is the only credential. An unknown one gets the same page as an
     * expired one, with no hint about whether it ever existed.
     */
    public function results(string $token): Response
    {
        $submission = $this->service()->findByToken($token);

        if ($submission === null) {
            return $this->view()->response('public/not-found', [
                'title' => 'Scorecard not found · The Enterprise Scorecard',
            ], 404);
        }

        if ($submission['status'] !== 'complete') {
            return $this->redirect($this->app->slug . '/assess?resume=' . urlencode($token));
        }

        return $this->view()->response('public/results', [
            'title'  => 'Your result · The Enterprise Scorecard',
            'active' => 'results',
            'result' => $this->service()->result($submission),
        ]);
    }
}
