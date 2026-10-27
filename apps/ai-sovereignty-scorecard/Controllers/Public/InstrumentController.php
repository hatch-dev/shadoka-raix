<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Controllers\Public;

use Apps\AiSovereigntyScorecard\Services\InstrumentPayload;
use Platform\Core\Controller;
use Platform\Core\Response;

/**
 * Serves the published instrument to the browser.
 *
 * The payload itself is built by InstrumentPayload, because the assessment page
 * inlines the same bytes rather than fetching them — see that class. This
 * endpoint remains the source for every other page, where the "How scoring
 * works" dialog needs the five levels but the whole instrument would be dead
 * weight.
 */
final class InstrumentController extends Controller
{
    public function show(): Response
    {
        $payload = (new InstrumentPayload($this->app))->build();

        if ($payload === null) {
            return $this->fail('No published instrument.', 503);
        }

        return $this->ok($payload);
    }
}
