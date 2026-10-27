<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Public;

use Apps\RaixDirectorScorecardPublic\Services\RecordStore;
use Platform\Core\Controller;
use Platform\Core\Response;

/**
 * The two public endpoints of directive v13, Part B. Neither needs a login and
 * neither ever returns a stored record.
 *
 *   B1  POST /api/scorecards   one scorecard in, {} out
 *   B2  GET  /api/benchmark    {n, median, below} out, nothing else
 */
final class ScorecardApiController extends Controller
{
    /** The request body, unparsed. Tests set it; a web request reads php://input. */
    public static ?string $rawBody = null;

    private function store(): RecordStore
    {
        return new RecordStore($this->app);
    }

    /** B1 */
    public function save(): Response
    {
        // Refuse an oversized body before reading it, where the client said.
        $declared = (int) ($this->request->server['CONTENT_LENGTH'] ?? 0);

        if ($declared > RecordStore::MAX_BODY_BYTES) {
            return Response::json(['error' => 'The scorecard is too large.'], 413);
        }

        $result = $this->store()->save(self::$rawBody ?? (string) file_get_contents('php://input'));

        return $result['status'] === 200
            ? Response::json(new \stdClass(), 200)
            : Response::json(['error' => $result['error']], $result['status']);
    }

    /** B2 */
    public function benchmark(): Response
    {
        return Response::json(
            $this->store()->benchmark($this->request->query['floor'] ?? null, $this->request->query['exclude'] ?? null)
        )->withHeader('Cache-Control', 'no-store');
    }
}
