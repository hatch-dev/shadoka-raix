<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Controllers\Admin;

use Apps\EnterpriseAiScorecard\Services\Analytics;
use Apps\EnterpriseAiScorecard\Services\RecordMirror;
use Platform\Admin\AdminController;
use Platform\Core\Response;

/**
 * What the dataset shows.
 *
 * The reference's four panes, with its synthetic-data trap closed: demo records
 * are opt-in here, and never included in correlations at all.
 *
 * Each pane is a URL rather than a JavaScript variable, so a pane can be linked
 * to, reloaded and bookmarked — which `F.tab` could not be.
 */
final class AnalyticsController extends AdminController
{
    /** The four panes, in the order the reference's tab strip shows them. */
    private const TABS = [
        'stats' => 'Overview',
        'yoy'   => 'Year-over-year',
        'dims'  => 'Dimensions',
        'corr'  => 'Correlation',
    ];

    /** GET /admin/enterprise-ai-scorecard/analytics */
    public function index(): Response
    {
        if ($denied = $this->guard('es.view')) {
            return $denied;
        }

        // The v32 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $query = $this->request->query;

        /*
         * Synthetic records are opt-in, with one exception carried over from the
         * reference build: a dataset holding nothing BUT demo records would
         * otherwise open on an empty screen, which reads as a broken page rather
         * than an empty one. Switched on only when the parameter is absent
         * entirely, so unticking the box still empties the view.
         */
        $counts = (new Analytics($this->app, true))->counts();

        $includeSynthetic = array_key_exists('synthetic', $query)
            ? ($query['synthetic'] === '1')
            : ($counts['real'] === 0 && $counts['synthetic'] > 0);

        $filters = [
            'year'   => (string) ($query['year'] ?? 'all'),
            'sector' => (string) ($query['sector'] ?? 'all'),
            'band'   => (string) ($query['band'] ?? 'all'),
        ];

        $tab = (string) ($query['tab'] ?? 'stats');
        if (!array_key_exists($tab, self::TABS)) {
            $tab = 'stats';
        }

        $analytics  = new Analytics($this->app, $includeSynthetic, $filters);
        $dimensions = $analytics->dimensions();

        /*
         * Which dimension the Dimensions pane opens on.
         *
         * The reference opened on the first one and always had one selected;
         * asking the visitor to pick before the pane shows anything makes the
         * tab look empty when it is not. An unknown code falls back the same
         * way, so a stale link cannot produce a blank pane.
         */
        $wanted = strtoupper(trim((string) ($query['dimension'] ?? '')));
        $codes  = array_column($dimensions, 'code');
        $code   = in_array($wanted, $codes, true) ? $wanted : (string) ($codes[0] ?? '');

        return $this->view()->response('admin/analytics', [
            'title'            => 'Analytics · The Enterprise Scorecard',
            'includeSynthetic' => $includeSynthetic,
            'filters'          => $filters,
            'filterOptions'    => $analytics->filterOptions(),
            'rungs'            => $analytics->rungs(),
            'bander'           => $analytics->bander(),
            'tab'              => $tab,
            'tabs'             => self::TABS,
            'counts'           => $counts,
            'overview'         => $analytics->overview(),
            'aggregateFloor'   => $analytics->aggregateFloor($dimensions),
            'dimensions'       => $dimensions,
            'levels'           => $analytics->levelDistribution(),
            'sectors'          => $analytics->bySector(),
            'code'             => $code,
            'minimumN'         => (int) $this->appConfig('scoring.correlation_min_n', 30),

            /*
             * Aggregates only one pane each needs, computed for that pane alone.
             * dimension(), rescoredOrganisations() and correlations() are each
             * further round trips, and the Overview — the tab every visit lands
             * on — has no use for any of them.
             */
            'reading'      => $tab === 'stats' ? $analytics->datasetReading() : '',
            'systems'      => $tab === 'stats' ? $analytics->systemsInventory() : null,
            'yearSeries'   => $tab === 'yoy' ? $analytics->yearOverYear() : [],
            'rescored'     => $tab === 'yoy' ? $analytics->rescoredOrganisations() : [],
            'years'        => $tab === 'yoy' ? $analytics->years() : [],
            'detail'       => $tab === 'dims' ? $analytics->dimension($code) : null,
            'correlations' => $tab === 'corr' ? $analytics->correlations() : [],
        ]);
    }

    /**
     * GET /admin/enterprise-ai-scorecard/analytics/organisation/{id}
     *
     * One organisation: every executive who scored it, its six dimension means
     * with the spread between its respondents, and its movement year on year.
     * This is the view the platform-level `organisations` table exists to make
     * possible — three spellings of one company name are one row here.
     */
    public function organisation(string $id): Response
    {
        if ($denied = $this->guard('es.view')) {
            return $denied;
        }

        // The v32 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $organisation     = $analytics->organisation((int) $id);

        if ($organisation === null) {
            return $this->redirect('admin/' . $this->app->slug . '/analytics');
        }

        /*
         * ?fragment=1 returns the profile alone, for the dialog the dashboard
         * opens over itself — which is what clicking an organisation did in the
         * reference. Served from the same controller and the same figures as the
         * page behind it, so the dialog and the page cannot disagree.
         */
        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_organisation-modal', [
                'slug'             => $this->app->slug,
                'includeSynthetic' => $includeSynthetic,
                'org'              => $organisation,
                'bander'           => $analytics->bander(),
            ]))->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/organisation', [
            'title'            => $organisation['organisation']['name'] . ' · The Enterprise Scorecard',
            'includeSynthetic' => $includeSynthetic,
            'org'              => $organisation,
            'bander'           => $analytics->bander(),
        ]);
    }

    /**
     * GET /admin/enterprise-ai-scorecard/analytics/organisation/{id}/report-data
     *
     * The figures behind the organisation dialog's PDF — the reference's
     * `exportOrgPDF()`, which had them in memory.
     *
     * Aggregated over EVERY scorecard the organisation has, not just its latest
     * year, as the reference's did. The dialog on screen shows the latest year,
     * so the document says which span it covers rather than leaving a reader to
     * wonder why its numbers are not the ones they were just looking at.
     */
    public function organisationReportData(string $id): Response
    {
        if ($denied = $this->guard('es.export')) {
            return $denied;
        }

        // The v32 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $bander           = $analytics->bander();
        $organisation     = $analytics->organisation((int) $id);

        if ($organisation === null) {
            return Response::json(['success' => false, 'message' => 'No such organisation.'], 404);
        }

        // Every year at once, which is not the same set as `latest`.
        $all = $this->allYears($analytics, (int) $id, $organisation['years']);

        $level = $bander->band($all['mean']);
        $years = $organisation['years'];

        $this->audit('es.organisation.report', 'organisation', $id, [
            'respondents' => count($organisation['submissions']),
        ]);

        return Response::json(['success' => true, 'data' => [
            'name'        => $organisation['organisation']['name'],
            'sector'      => $organisation['sector'],
            'size'        => $organisation['size'],
            'respondents' => count($organisation['submissions']),
            'years'       => $years === []
                ? null
                : (count($years) === 1 ? (string) $years[0] : $years[0] . '–' . end($years)),
            'synthetic'   => $organisation['synthetic'],
            // Level from the average, floor reported beside it — labelled, so
            // the PDF cannot present one as the other.
            'mean'        => $all['mean'],
            'floor'       => $all['floor'],
            'floor_code'  => $all['floor_code'],
            'level'       => $level === null ? null : ['value' => $level['value'], 'name' => $level['name']],
            'footnote'    => $this->footnote($bander),
            'dimensions'  => array_map(
                static function (array $dimension) use ($bander): array {
                    $band = $bander->band($dimension['mean']);

                    return [
                        'code'   => $dimension['code'],
                        'name'   => $dimension['name'],
                        'mean'   => $dimension['mean'],
                        'min'    => $dimension['min'],
                        'max'    => $dimension['max'],
                        'n'      => $dimension['n'],
                        'colour' => $band['colour'] ?? null,
                        'rung'   => $band['name'] ?? null,
                    ];
                },
                $all['dimensions']
            ),
        ]]);
    }

    /**
     * Dimension means across every year an organisation has scored in.
     *
     * @param list<int> $years
     * @return array{dimensions:list<array<string,mixed>>, mean:?float, floor:?float, floor_code:?string}
     */
    private function allYears(Analytics $analytics, int $organisationId, array $years): array
    {
        $totals = [];

        foreach ($years as $year) {
            foreach ($analytics->organisationDimensions($organisationId, $year)['dimensions'] as $dimension) {
                $code = $dimension['code'];

                $totals[$code] ??= [
                    'code' => $code,
                    'name' => $dimension['name'],
                    'sum'  => 0.0,
                    'n'    => 0,
                    'min'  => null,
                    'max'  => null,
                ];

                if ($dimension['mean'] === null) {
                    continue;
                }

                // Weighted by how many respondents each year had, so a year with
                // five executives is not worth the same as a year with one.
                $totals[$code]['sum'] += $dimension['mean'] * max(1, $dimension['n']);
                $totals[$code]['n']   += max(1, $dimension['n']);
                $totals[$code]['min']  = $totals[$code]['min'] === null
                    ? $dimension['min'] : min($totals[$code]['min'], (float) $dimension['min']);
                $totals[$code]['max']  = $totals[$code]['max'] === null
                    ? $dimension['max'] : max($totals[$code]['max'], (float) $dimension['max']);
            }
        }

        $dimensions = array_values(array_map(static fn (array $t): array => [
            'code' => $t['code'],
            'name' => $t['name'],
            'mean' => $t['n'] > 0 ? round($t['sum'] / $t['n'], 2) : null,
            'min'  => $t['min'],
            'max'  => $t['max'],
            'n'    => $t['n'],
        ], $totals));

        $scored = array_values(array_filter(
            array_column($dimensions, 'mean'),
            static fn (?float $m): bool => $m !== null
        ));

        $floor     = null;
        $floorCode = null;
        foreach ($dimensions as $dimension) {
            if ($dimension['mean'] === null) {
                continue;
            }
            if ($floor === null || $dimension['mean'] < $floor) {
                $floor     = $dimension['mean'];
                $floorCode = $dimension['code'];
            }
        }

        return [
            'dimensions' => $dimensions,
            'mean'       => $scored === [] ? null : round(array_sum($scored) / count($scored), 2),
            'floor'      => $floor,
            'floor_code' => $floorCode,
        ];
    }

    /** The level legend printed on a generated PDF, built from the instrument. */
    private function footnote(\Platform\Assessment\Bander $bander): string
    {
        $levels = implode(' · ', array_map(
            static fn (array $rung): string => sprintf(
                '%d %s (%s)',
                $rung['value'],
                $rung['name'],
                strtolower(rtrim($rung['description'], '.'))
            ),
            $bander->rungs()
        ));

        return strtr((string) $this->appConfig('result.pdf_footnote'), [
            '{copyright}' => (string) config('app.copyright'),
            '{levels}'    => $levels,
        ]);
    }
}
