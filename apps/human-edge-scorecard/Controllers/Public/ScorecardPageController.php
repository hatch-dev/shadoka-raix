<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Controllers\Public;

use Apps\HumanEdgeScorecard\Services\NoticeArchive;
use Apps\HumanEdgeScorecard\Services\Retention;
use Apps\HumanEdgeScorecard\Services\ScorecardPage;
use Platform\Core\Controller;
use Platform\Core\PageAssets;
use Platform\Core\Response;

/**
 * GET / — the Human Edge page, as Shadoka supplied it (API_BASE and the
 * patches listed in Services/ScorecardPage aside).
 *
 * The page is the whole public app: overview, consent, questionnaire, results
 * and its own admin views are all inside it.
 */
final class ScorecardPageController extends Controller
{
    public function show(): Response
    {
        // The 24-month deletion also runs here, once a day, so it happens on a
        // host with no scheduler too. scripts/retention.php is the cron entry.
        Retention::runIfDue($this->app);

        $page = new ScorecardPage($this->app);
        $html = $page->html();

        // C5: the first time this exact page is served, a dated copy is kept.
        (new NoticeArchive($this->app))->record($html, $page->apiBase());

        // The share card goes on as the page is sent, after the C5 copy
        // (it names the visitor's host; see ScorecardPage::withShareCard()).
        // Sent in cacheable pieces (fonts, hero image, libraries as files);
        // the archived copy above stays the whole page. See PageAssets.
        return Response::make($page->withShareCard(PageAssets::split($html)))
            ->withHeader('Content-Security-Policy', ScorecardPage::CSP)
            ->withHeader('Cache-Control', 'no-cache')
            ->revalidatable($this->request);
    }
}
