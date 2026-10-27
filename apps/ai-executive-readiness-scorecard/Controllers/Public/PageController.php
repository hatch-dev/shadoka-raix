<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Controllers\Public;

use Apps\AiExecutiveReadinessScorecard\Services\InstrumentData;
use Apps\AiExecutiveReadinessScorecard\Services\InstrumentPayload;
use Apps\AiExecutiveReadinessScorecard\Services\SubmissionService;
use Platform\Core\Controller;
use Platform\Core\Response;
use Platform\Core\View;

/**
 * The public pages.
 *
 * Every page is rendered server-side from the published instrument, so the four
 * dimensions and their one-line summaries exist in exactly one place — the
 * database — instead of being duplicated between the markup and a JavaScript
 * array as they were in the reference build.
 */
final class PageController extends Controller
{
    private const PRODUCT = 'AI Executive Readiness — The Enterprise Scorecard';

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
        $data       = new InstrumentData($this->app);
        $instrument = $data->published();
        $dimensions = $data->dimensions((int) $instrument['id']);

        return $this->view()->response('public/hero', [
            // The reference's <title>, verbatim.
            'title'       => self::PRODUCT,
            'description' => (string) $this->app->description,
            'instrument'  => $instrument,
            'dimensions'  => $dimensions,
            'itemCount'   => array_sum(array_map(static fn (array $d): int => count($d['items']), $dimensions)),
        ]);
    }

    /**
     * GET /assess — the questionnaire.
     *
     * The instrument is inlined, not fetched: fetching it meant the page painted
     * its header and footer against an empty body and filled in half a second
     * later. The same builder feeds /api/instrument, so the two cannot disagree.
     */
    public function assess(): Response
    {
        return $this->view()->response('public/assess', [
            'title'      => self::PRODUCT,
            'instrument' => (new InstrumentPayload($this->app))->build(),
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
                'title' => 'Scorecard not found · ' . self::PRODUCT,
            ], 404);
        }

        if ($submission['status'] !== 'complete') {
            return $this->redirect($this->app->slug . '/assess?resume=' . urlencode($token));
        }

        return $this->view()->response('public/results', [
            'title'  => self::PRODUCT,
            'result' => $this->service()->result($submission),
        ]);
    }
}
