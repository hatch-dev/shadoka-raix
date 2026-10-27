<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Admin;

use Apps\RaixDirectorScorecardPublic\Services\Analytics;
use Apps\RaixDirectorScorecardPublic\Services\RecordMirror;
use Platform\Admin\AdminController;
use Platform\Core\Response;

/**
 * What the dataset shows.
 *
 * Replaces the legacy Analytics view, with the synthetic-data trap closed: demo
 * records are opt-in here, and never included in correlations at all.
 */
final class AnalyticsController extends AdminController
{
    /** The four panes of the dashboard, in the order the tab strip shows them. */
    private const TABS = [
        'stats' => 'Overview',
        'yoy'   => 'Year-over-year',
        'dims'  => 'Competencies',
        'corr'  => 'Correlation',
    ];

    /** GET /admin/raix-director-scorecard-public/analytics */
    public function index(): Response
    {
        if ($denied = $this->guard('dsp.view')) {
            return $denied;
        }

        // The v13 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $query = $this->request->query;

        /*
         * Synthetic records are opt-in, with one exception carried over from
         * the single-file build: a dataset holding nothing BUT demo records
         * would otherwise open on an empty screen, which reads as a broken
         * page rather than an empty one. Switched on only when the parameter
         * is absent entirely, so unticking the box still empties the view.
         */
        $counts = (new Analytics($this->app, true))->counts();

        $includeSynthetic = array_key_exists('synthetic', $query)
            ? ($query['synthetic'] === '1')
            : ((int) ($counts['real'] ?? 0) === 0 && (int) ($counts['synthetic'] ?? 0) > 0);

        $filters = [
            'year'   => (string) ($query['year'] ?? 'all'),
            'sector' => (string) ($query['sector'] ?? 'all'),
            'band'   => (string) ($query['band'] ?? 'all'),
        ];

        $tab = (string) ($query['tab'] ?? 'stats');
        if (!array_key_exists($tab, self::TABS)) {
            $tab = 'stats';
        }

        $analytics    = new Analytics($this->app, $includeSynthetic, $filters);
        $overview     = $analytics->overview();
        $competencies = $analytics->competencies();
        $correlations = $analytics->correlations();

        /*
         * Which competency the Competencies pane is opened on.
         *
         * The reference opened on the first one and always had a competency
         * selected; asking the visitor to pick one before the pane shows
         * anything makes the tab look empty when it is not. An unknown code
         * falls back the same way, so a stale link cannot produce a blank pane.
         */
        $wanted    = strtoupper(trim((string) ($query['competency'] ?? '')));
        $codes     = array_column($competencies, 'code');
        $competency = in_array($wanted, $codes, true) ? $wanted : (string) ($codes[0] ?? '');

        return $this->view()->response('admin/analytics', [
            'title'            => 'Analytics · Director Scorecard',
            'includeSynthetic' => $includeSynthetic,
            'filters'          => $filters,
            'filterOptions'    => $analytics->filterOptions(),
            'rungs'            => $analytics->rungs(),
            'bander'           => $analytics->bander(),
            'tab'              => $tab,
            'tabs'             => self::TABS,
            'counts'           => $counts,
            'overview'         => $overview,
            'systems'          => $analytics->systemsAwareness(),
            'insight'          => $analytics->datasetInsight(),
            'levels'           => $analytics->levelDistribution(),
            'competencies'     => $competencies,
            'sectors'          => $analytics->bySector(),
            'correlations'     => $correlations,
            'years'            => $analytics->yearOverYear(),
            'competency'       => $competency,

            /*
             * Four aggregates only one pane each needs. Computed for that pane
             * alone: items(), boardYears() and competencyBySector() are each
             * further round trips, and the Overview — the tab every visit lands
             * on — has no use for any of them.
             */
            'items'            => $tab === 'dims' ? $analytics->items($competency) : null,
            'boardYears'       => $tab === 'yoy' ? $analytics->boardYears() : [],
            'dimSectors'       => $tab === 'dims' ? $analytics->competencyBySector($competency) : [],
            'corrInsight'      => $tab === 'corr' ? $analytics->correlationInsight($correlations) : '',
        ]);
    }

    /**
     * GET /admin/raix-director-scorecard-public/analytics/board/{id}
     *
     * One board: every director who scored it, and which competencies the board
     * as a whole reaches Practiced on. This is the view the platform-level
     * `organisations` table exists to make possible.
     */
    public function board(string $id): Response
    {
        if ($denied = $this->guard('dsp.view')) {
            return $denied;
        }

        // The v13 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $board            = $analytics->board((int) $id);

        if ($board === null) {
            return $this->redirect('admin/' . $this->app->slug . '/analytics');
        }

        /*
         * ?fragment=1 returns the board profile alone, for the dialog the
         * dashboard opens over itself — which is what clicking a board did in
         * the reference. Served from the same controller and the same figures
         * as the page behind it, so the dialog and the page cannot disagree.
         */
        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_board-modal', [
                'slug'             => $this->app->slug,
                'includeSynthetic' => $includeSynthetic,
                'board'            => $board,
                'byYear'           => $analytics->boardByYear((int) $id),
                'bander'           => $analytics->bander(),
            ]))->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/board', [
            'title'            => $board['organisation']['name'] . ' · Director Scorecard',
            'includeSynthetic' => $includeSynthetic,
            'board'            => $board,
            'threshold'        => (float) $this->appConfig('scoring.practiced_threshold', 2.75),
        ]);
    }

    /**
     * GET /admin/raix-director-scorecard-public/analytics/board/{id}/report-data
     *
     * The figures behind the board dialog's PDF — the reference's
     * `exportOrgPDF()`, which had them in memory.
     *
     * Aggregated over EVERY scorecard the board has, not its latest year: the
     * printed profile is the board as the dataset knows it. The floor is the
     * weakest of the seven competency means and the average is the mean of
     * those seven, which is the board-level reading of the floor rule and the
     * same arithmetic the research report's summary line uses.
     */
    public function boardReportData(string $id): Response
    {
        if ($denied = $this->guard('dsp.export')) {
            return $denied;
        }

        // The v13 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $bander           = $analytics->bander();
        $board            = $analytics->board((int) $id);

        if ($board === null) {
            return Response::json(['success' => false, 'error' => 'No such board.'], 404);
        }

        $competencies = $board['competencies'];
        $means        = array_values(array_filter(
            array_column($competencies, 'mean'),
            static fn (?float $m): bool => $m !== null
        ));

        $floor     = $means === [] ? null : round(min($means), 2);
        $floorCode = null;

        foreach ($competencies as $competency) {
            if ($competency['mean'] !== null && $competency['mean'] === $floor) {
                // First in instrument order wins a tie, as the floor rule does.
                $floorCode = $competency['code'];
                break;
            }
        }

        $organisation = $board['organisation'];
        $directors    = count($board['directors']);
        $level        = $bander->band($floor);

        /*
         * Which years this covers, printed on the document.
         *
         * The dialog on screen shows the board's LATEST year; this covers every
         * scorecard it has, as the reference's board PDF did. Two honest
         * readings of one board — so the paper one says which it is, rather
         * than leaving a reader to wonder why its floor is not the one they
         * were just looking at.
         */
        $years = array_column($analytics->boardByYear((int) $id), 'year');

        $this->audit('dsp.board.report', 'organisation', (string) $organisation['id'], [
            'directors' => $directors,
        ]);

        return Response::json(['success' => true, 'data' => [
            'name'         => $organisation['name'],
            'sector'       => $organisation['sector'],
            'size'         => $organisation['size_band'],
            'directors'    => $directors,
            'years'        => $years === []
                ? null
                : (count($years) === 1 ? (string) $years[0] : $years[0] . '–' . end($years)),
            'synthetic'    => (int) $organisation['is_synthetic'] === 1,
            'floor'        => $floor,
            'floor_code'   => $floorCode,
            'mean'         => $means === [] ? null : round(array_sum($means) / count($means), 2),
            'level'        => $level === null ? null : ['value' => $level['value'], 'name' => $level['name']],
            'competencies' => array_map(
                static function (array $competency) use ($bander): array {
                    $band = $bander->band($competency['mean']);

                    return [
                        'code'   => $competency['code'],
                        'name'   => $competency['name'],
                        'mean'   => $competency['mean'],
                        'min'    => $competency['min'],
                        'max'    => $competency['max'],
                        'n'      => $competency['n'],
                        'colour' => $band['colour'] ?? null,
                        'rung'   => $band['name'] ?? null,
                    ];
                },
                $competencies
            ),
        ]]);
    }
}
