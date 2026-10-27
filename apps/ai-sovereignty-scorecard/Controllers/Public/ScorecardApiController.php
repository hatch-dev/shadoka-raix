<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Controllers\Public;

use Apps\AiSovereigntyScorecard\Services\RecordStore;
use Apps\AiSovereigntyScorecard\Services\Retention;
use Platform\Core\Controller;
use Platform\Core\Response;

/**
 * The public endpoint of directive v6, Part B. It needs no login and never
 * returns a stored record.
 *
 *   B1  POST /api/scorecards   one scorecard in, {} out
 */
final class ScorecardApiController extends Controller
{
    /** The request body, unparsed. Tests set it; a web request reads php://input. */
    public static ?string $rawBody = null;

    /** B1 */
    public function save(): Response
    {
        // Refuse an oversized body before reading it, where the client said.
        $declared = (int) ($this->request->server['CONTENT_LENGTH'] ?? 0);

        if ($declared > RecordStore::MAX_BODY_BYTES) {
            return Response::json(['error' => 'The scorecard is too large.'], 413);
        }

        // C3 at most once a day, so a host without cron still deletes on time.
        Retention::runIfDue($this->app);

        $result = (new RecordStore($this->app))->save(self::$rawBody ?? (string) file_get_contents('php://input'));

        return $result['status'] === 200
            ? Response::json(new \stdClass(), 200)->withHeader('Cache-Control', 'no-store')
            : Response::json(['error' => $result['error']], $result['status']);
    }
}
