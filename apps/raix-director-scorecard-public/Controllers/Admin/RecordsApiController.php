<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Admin;

use Apps\RaixDirectorScorecardPublic\Controllers\Public\ScorecardApiController;
use Platform\Core\Auth;
use Platform\Core\Response;

/**
 * The three staff endpoints of directive v13, Part B, called by the page's own
 * admin views with credentials: 'include'.
 *
 *   B3  GET    /api/records           synthetic + unrestricted real records
 *   B4  PUT    /api/admin/synthetic   replace the demonstration records
 *   B5  DELETE /api/admin/synthetic   delete the demonstration records
 *
 * Signed in to this app's admin, checked by AdminApiController::gate().
 */
final class RecordsApiController extends AdminApiController
{

    /** B3 */
    public function index(): Response
    {
        if ($denied = $this->gate('dsp.view')) {
            return $denied;
        }

        return Response::make($this->store()->visibleRecordsJson(), 200, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** B4 */
    public function replaceSynthetic(): Response
    {
        if ($denied = $this->gate('dsp.seed')) {
            return $denied;
        }

        $result = $this->store()->replaceSynthetic(
            ScorecardApiController::$rawBody ?? (string) file_get_contents('php://input')
        );

        if ($result['status'] !== 200) {
            return Response::json(['error' => $result['error']], $result['status']);
        }

        Auth::audit('dsp.synthetic.replace', $this->app->slug, 'records', null, ['count' => $result['count']], $this->request->ipHash());

        return Response::json(new \stdClass());
    }

    /** B5 */
    public function deleteSynthetic(): Response
    {
        if ($denied = $this->gate('dsp.seed')) {
            return $denied;
        }

        $deleted = $this->store()->deleteSynthetic();

        Auth::audit('dsp.synthetic.delete', $this->app->slug, 'records', null, ['deleted' => $deleted], $this->request->ipHash());

        return Response::json(new \stdClass());
    }
}
