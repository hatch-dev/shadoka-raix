<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Controllers\Admin;

use Apps\AiExecutiveReadinessScorecard\Services\Analytics;
use Apps\AiExecutiveReadinessScorecard\Services\RecordMirror;
use Apps\AiExecutiveReadinessScorecard\Services\Stats;
use Platform\Admin\AdminController;
use Platform\Core\Response;

/**
 * What the dataset shows — the reference's renderAnalytics() and its five panes.
 *
 * Each pane and every filter is a URL rather than JavaScript state, so a
 * filtered view can be linked to, reloaded and bookmarked.
 */
final class AnalyticsController extends AdminController
{
    /** GET /admin/ai-executive-readiness-scorecard/analytics */
    public function index(): Response
    {
        if ($denied = $this->guard('exr.view')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $analytics = new Analytics($this->app);
        $filters   = $analytics->filters($this->request->query);
        $recs      = $analytics->filtered($filters);
        $tab       = $filters['tab'];

        return $this->view()->response('admin/analytics', [
            'title'         => 'Analytics · AI Executive Readiness — The Enterprise Scorecard',
            'f'             => $filters,
            'recs'          => $recs,
            'options'       => $analytics->filterOptions(),
            'rungs'         => $analytics->stats()->bander()->rungs(),
            'bander'        => $analytics->stats()->bander(),
            'dimensions'    => $analytics->dimensions(),
            'tabs'          => Analytics::TABS,
            'filterSummary' => $analytics->filterDescription($filters),

            // Only the pane on screen is computed.
            'overview'    => $recs !== [] && $tab === 'stats' ? $analytics->overview($recs) : null,
            'yoy'         => $recs !== [] && $tab === 'yoy' ? $analytics->yearOverYear($recs) : null,
            'detail'      => $recs !== [] && $tab === 'dims' ? $analytics->dimensionDetail($recs, $filters['dim']) : [],
            'corr'        => $recs !== [] && $tab === 'corr' ? $analytics->correlations($recs, $filters['scope']) : [],
            'compare'     => $recs !== [] && $tab === 'compare' ? $analytics->comparison($recs) : null,
        ]);
    }

    /**
     * GET /admin/ai-executive-readiness-scorecard/analytics/organisation?name=…
     *
     * openOrg(name, source, forceSynthetic): one organization's pooled profiles.
     * From Analytics it carries the dashboard's filters (source=analytics); from
     * a record it is every year, with synthetic records in when the dataset's
     * toggle is on or the record itself is synthetic.
     */
    public function organisation(): Response
    {
        if ($denied = $this->guard('exr.view')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $view = $this->organisationView();

        if ($view === null) {
            return $this->fail('No matching organization records', 404);
        }

        $data = $view + [
            'slug'   => $this->app->slug,
            'bander' => (new Analytics($this->app))->stats()->bander(),
            'query'  => http_build_query($this->request->query),
        ];

        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_organisation-modal', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/organisation', $data + [
            'title' => $view['name'] . ' · AI Executive Readiness — The Enterprise Scorecard',
        ]);
    }

    /**
     * GET …/analytics/organisation/report-data?name=…
     *
     * exportOrgPDF(): the same records the dialog shows, as the group report.
     */
    public function organisationReportData(): Response
    {
        if ($denied = $this->guard('exr.export')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $view = $this->organisationView();

        if ($view === null) {
            return $this->fail('No matching organization records', 404);
        }

        $this->audit('exr.organisation.report', 'organisation', $view['name'], ['records' => count($view['recs'])]);

        return $this->ok((new Analytics($this->app))->report(
            $view['recs'],
            'Organization report',
            $view['name'] . '. ' . $view['description'],
            'AI_Executive_Readiness_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $view['name']) . '.pdf'
        ));
    }

    /**
     * The records and wording both organisation endpoints share.
     *
     * @return array<string, mixed>|null
     */
    private function organisationView(): ?array
    {
        $query     = $this->request->query;
        $name      = (string) ($query['name'] ?? '');
        $analytics = new Analytics($this->app);
        $stats     = $analytics->stats();

        if (($query['source'] ?? 'data') === 'analytics') {
            $filters     = $analytics->filters($query);
            $pool        = $analytics->filtered($filters);
            $description = $analytics->filterDescription($filters);
        } else {
            $included    = $analytics->includeSynthetic($query) || ($query['force'] ?? '') === '1';
            $pool        = array_values(array_filter($analytics->all(), static fn (array $r): bool => $included || !$r['synthetic']));
            $description = 'All available years. Synthetic records ' . ($included ? 'included' : 'excluded') . '.';
        }

        $recs = Analytics::forOrganisation($pool, $name);

        if ($recs === []) {
            return null;
        }

        return [
            'name'        => $name,
            'recs'        => $recs,
            'description' => $description,
            'respondents' => Stats::respondentCount($recs),
            'scores'      => $stats->groupScores($recs),
            'spread'      => $stats->spread($recs),
            'dimensions'  => $analytics->dimensions(),
        ];
    }
}
