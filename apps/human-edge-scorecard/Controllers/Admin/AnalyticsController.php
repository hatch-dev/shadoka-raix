<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Controllers\Admin;

use Apps\HumanEdgeScorecard\Services\Analytics;
use Apps\HumanEdgeScorecard\Services\Instrument;
use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Apps\HumanEdgeScorecard\Services\Records;
use Platform\Admin\AdminController;
use Platform\Core\Response;

/**
 * What the dataset shows — the page's renderAnalytics() and its six panes.
 *
 * Each pane and every filter is a URL rather than JavaScript state, so a
 * filtered view can be linked to, reloaded and bookmarked.
 */
final class AnalyticsController extends AdminController
{
    /** GET /admin/human-edge-scorecard/analytics */
    public function index(): Response
    {
        if ($denied = $this->guard('he.view')) {
            return $denied;
        }

        // he_records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $analytics = new Analytics($this->app);
        $f         = $analytics->filters($this->request->query);
        $recs      = $analytics->filtered($f);
        $tab       = $f['tab'];
        $any       = $recs !== [];

        return $this->view()->response('admin/analytics', [
            'title'     => 'Analytics · The Human Edge Scorecard',
            'slug'      => $this->app->slug,
            'f'         => $f,
            'recs'      => $recs,
            'counts'    => $analytics->counts(),
            'sectors'   => $analytics->sectorOptions(),
            'tabs'      => Analytics::TABS,
            'analytics' => $analytics,
            'stats'     => $analytics->stats(),
            'dims'      => $analytics->instrument()->dims(),
            'rungs'     => $analytics->instrument()->rungs(),

            // Only the pane on screen is computed.
            'index'     => $any && $tab === 'index' ? $analytics->index($recs) : null,
            'skill'     => $any && $tab === 'skill' ? $analytics->skill($recs, $f['dim']) : null,
            'practices' => $any && $tab === 'practices' ? $analytics->practices($recs) : null,
            'segments'  => $any && $tab === 'segments' ? $analytics->segmentTable($recs, $f['seg']) : null,
            'ai'        => $any && $tab === 'ai' ? $analytics->ai($recs) : null,
            'quality'   => $any && $tab === 'quality' ? $analytics->quality($recs) : null,
        ]);
    }

    /**
     * GET …/analytics/organisation?name=… — openOrg(name): every scorecard
     * naming the organization, as a dialog (fragment=1) or a page.
     */
    public function organisation(): Response
    {
        if ($denied = $this->guard('he.view')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $analytics = new Analytics($this->app);
        $org       = $analytics->organisation((string) ($this->request->query['name'] ?? ''));

        if ($org === null) {
            return $this->fail('No matching organization records', 404);
        }

        $data = [
            'slug'  => $this->app->slug,
            'org'   => $org,
            'stats' => $analytics->stats(),
            'dims'  => $analytics->instrument()->dims(),
        ];

        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_organisation-modal', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/organisation', $data + [
            'title' => $org['name'] . ' · The Human Edge Scorecard',
        ]);
    }

    /**
     * GET …/analytics/organisation/report-data?name=… — exportOrgPDF()'s
     * records, drawn into the PDF by assets/js/admin-pdf.js.
     */
    public function organisationReportData(): Response
    {
        if ($denied = $this->guard('he.export')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $org = (new Analytics($this->app))->organisation((string) ($this->request->query['name'] ?? ''));

        if ($org === null) {
            return $this->fail('No matching organization records', 404);
        }

        $this->audit('he.organisation.report', 'organisation', $org['name'], ['records' => count($org['recs'])]);

        return $this->ok((new Instrument($this->app))->forBrowser() + [
            'name'    => $org['name'],
            'records' => array_map([Records::class, 'export'], $org['recs']),
        ]);
    }
}
