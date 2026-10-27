<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Controllers\Admin;

use Apps\HumanEdgeScorecard\Controllers\Public\ScorecardApiController;
use Apps\HumanEdgeScorecard\Services\Retention;
use Platform\Core\Auth;
use Platform\Core\Response;

/**
 * The three admin endpoints of the privacy directive, Part B, called by the page's own
 * admin views with credentials: 'include'.
 *
 *   B2  GET    /api/records           synthetic + unrestricted real records
 *   B3  PUT    /api/admin/synthetic   replace the demonstration records
 *   B4  DELETE /api/admin/synthetic   delete the demonstration records
 *
 * Signed in to this app's admin, checked by AdminApiController::gate().
 */
final class RecordsApiController extends AdminApiController
{
    /** B2 */
    public function index(): Response
    {
        if ($denied = $this->gate('he.view')) {
            return $denied;
        }

        // C3 before listing, so an expired record is never shown.
        Retention::runIfDue($this->app);

        return Response::make($this->store()->visibleRecordsJson(), 200, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** B3 */
    public function replaceSynthetic(): Response
    {
        // No JSON Content-Type demanded: a cross-site form cannot send PUT,
        // and the page's own header is not known until the file arrives.
        if ($denied = $this->gate('he.seed')) {
            return $denied;
        }

        $result = $this->store()->replaceSynthetic(
            ScorecardApiController::$rawBody ?? (string) file_get_contents('php://input')
        );

        if ($result['status'] !== 200) {
            return Response::json(['error' => $result['error']], $result['status']);
        }

        Auth::audit('he.synthetic.replace', $this->app->slug, 'records', null, ['count' => $result['count']], $this->request->ipHash());

        return Response::json(new \stdClass());
    }

    /** B4 */
    public function deleteSynthetic(): Response
    {
        if ($denied = $this->gate('he.seed')) {
            return $denied;
        }

        $deleted = $this->store()->deleteSynthetic();

        Auth::audit('he.synthetic.delete', $this->app->slug, 'records', null, ['deleted' => $deleted], $this->request->ipHash());

        return Response::json(new \stdClass());
    }
}
