<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Controllers\Public;

use Apps\AiSovereigntyScorecard\Services\NoticeArchive;
use Apps\AiSovereigntyScorecard\Services\Retention;
use Apps\AiSovereigntyScorecard\Services\ScorecardPage;
use Platform\Core\Controller;
use Platform\Core\PageAssets;
use Platform\Core\Response;

/**
 * GET / — the v6 page, as Shadoka supplied it (line 1023 and the sign-in
 * patches aside).
 *
 * The page is the whole public app: overview, consent, questionnaire, results
 * and its own admin views are all inside it. Anything that used to have its
 * own URL here (/assess, /results/{token}) comes back to it. The previous
 * edition's PageController is kept on disk, unrouted.
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

    /** The previous edition's URLs. There is one page now. */
    public function toPage(): Response
    {
        return $this->redirect($this->app->slug);
    }
}
