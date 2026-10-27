<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Controllers\Admin;

use Apps\HumanEdgeScorecard\Services\Analytics;
use Apps\HumanEdgeScorecard\Services\Instrument;
use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Apps\HumanEdgeScorecard\Services\Records;
use Apps\HumanEdgeScorecard\Services\Stats;
use Platform\Admin\AdminController;
use Platform\Core\Auth;
use Platform\Core\Csrf;
use Platform\Core\Response;

/**
 * The research dataset — the page's renderData(), its admin console
 * (adminPanel: Latest · Find · Data · Export) and its record dialog
 * (openRecord).
 *
 * Nothing here deletes a real record: the page's console cannot, and the
 * privacy directive allows no bulk deletion of real records. A person's
 * record is corrected, restricted or deleted from Requests.
 */
final class DatasetController extends AdminController
{
    private const TABS = ['records' => 'Records', 'dims' => 'Dimensions', 'levels' => 'Levels'];

    /** GET /admin/human-edge-scorecard/research-data */
    public function index(): Response
    {
        if ($denied = $this->guard('he.view')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $query     = $this->request->query;
        $analytics = new Analytics($this->app);
        $stats     = $analytics->stats();
        $all       = $analytics->all();
        $included  = $analytics->includeSynthetic($query);
        $recs      = array_values(array_filter($all, static fn (array $r): bool => $included || !$r['synthetic']));
        $real      = array_values(array_filter($all, static fn (array $r): bool => !$r['synthetic']));

        $tab = (string) ($query['tab'] ?? 'records');
        if (!isset(self::TABS[$tab])) {
            $tab = 'records';
        }

        $levels = [];
        foreach ($recs as $r) {
            $key          = $r['level'] ?? 'Unscored';
            $levels[$key] = ($levels[$key] ?? 0) + 1;
        }

        $sectors = array_unique(array_filter(array_map(
            static fn (array $r): string => (string) ($r['org']['sector'] ?? ''),
            $recs
        ), static fn (string $s): bool => $s !== '' && $s !== 'Not specified'));

        $agg = $stats->aggregate($recs);

        return $this->view()->response('admin/dataset', [
            'title'            => 'Research data · The Human Edge Scorecard',
            'slug'             => $this->app->slug,
            'tab'              => $tab,
            'tabs'             => self::TABS,
            'includeSynthetic' => $included,
            'syntheticCount'   => count($all) - count($real),
            'realCount'        => count($real),
            'recs'             => Stats::newestFirst($recs),
            'latest'           => Stats::newestFirst($real)[0] ?? null,
            'search'           => (string) ($query['q'] ?? ''),
            'sectorCount'      => count($sectors),
            'median'           => $recs === [] ? null : Stats::quantile(array_column($recs, 'mean'), .5),
            'agg'              => $agg,
            'aggDims'          => Stats::aggDims($agg),
            'levels'           => $levels,
            'stats'            => $stats,
            'dims'             => $analytics->instrument()->dims(),
            'rungs'            => $analytics->instrument()->rungs(),
            'canExport'        => Auth::can('he.export'),
            'canSeed'          => Auth::can('he.seed'),
            'notice'           => $this->notice($query),
        ]);
    }

    /** The one-line outcome of whatever sent the browser here (the page's toasts). */
    private function notice(array $query): string
    {
        return match (true) {
            ($query['done'] ?? '') === 'seeded'   => 'Seeded ' . (int) ($query['records'] ?? 0) . ' scorecards across ' . (int) ($query['orgs'] ?? 0) . ' organizations',
            ($query['done'] ?? '') === 'restored' => 'Default demo set restored',
            ($query['done'] ?? '') === 'purged'   => 'Synthetic records deleted',
            isset($query['empty'])                => 'Nothing to export',
            default                  => '',
        };
    }

    /**
     * GET …/console — adminPanel(): Latest · Find · Data · Export, opening on
     * Latest. ?fragment=1 returns the panel for the dialog; anything else, the page.
     */
    public function console(): Response
    {
        if ($denied = $this->guard('he.view')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $query     = $this->request->query;
        $analytics = new Analytics($this->app);
        $all       = $analytics->all();

        $tab = (string) ($query['tab'] ?? 'latest');
        if (!in_array($tab, ['latest', 'find', 'data', 'export'], true)) {
            $tab = 'latest';
        }

        // Latest: the newest and the five before it; real only unless the
        // pane's own box is ticked (the page's adminLatestSyn).
        $latestSynthetic = ($query['lsyn'] ?? '') === '1';
        $latest          = array_slice(Stats::newestFirst(array_values(array_filter(
            $all,
            static fn (array $r): bool => $latestSynthetic || !$r['synthetic']
        ))), 0, 6);

        // Find: the whole store, synthetic included, the first 40, newest first.
        $find = (string) ($query['q'] ?? '');
        $hits = trim($find) === '' ? [] : Stats::newestFirst(array_values(array_filter(
            $all,
            static fn (array $r): bool => Stats::matches($r, $find)
        )));

        $data = [
            'slug'             => $this->app->slug,
            'tab'              => $tab,
            'csrf'             => Csrf::token(),
            'includeSynthetic' => $analytics->includeSynthetic($query),
            'counts'           => $analytics->counts(),
            'stats'            => $analytics->stats(),
            'canExport'        => Auth::can('he.export'),
            'canSeed'          => Auth::can('he.seed'),
            'latest'           => $latest,
            'latestSynthetic'  => $latestSynthetic,
            'find'             => $find,
            'hits'             => array_slice($hits, 0, 40),
            'hitsTotal'        => count($hits),
        ];

        if ($tab === 'find' && ($query['results'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_console-find-results', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        if (($query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_console', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/console', $data + [
            'title' => 'Console · The Human Edge Scorecard',
        ]);
    }

    /** GET …/research-data/{id} — openRecord(): one scorecard, as a dialog or a page. */
    public function show(string $id): Response
    {
        if ($denied = $this->guard('he.view')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $record = (new Records($this->app))->find((int) $id);

        if ($record === null) {
            return (($this->request->query['fragment'] ?? '') === '1')
                ? $this->fail('Record not found', 404)
                : $this->view()->response('errors/forbidden', ['title' => 'Not found · RAI-X admin', 'capability' => null], 404);
        }

        $instrument = new Instrument($this->app);

        $data = [
            'slug'      => $this->app->slug,
            'record'    => $record,
            'stats'     => new Stats($instrument),
            'dims'      => $instrument->dims(),
            'canExport' => Auth::can('he.export'),
        ];

        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_record-modal', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/record', $data + [
            'title' => ($record['userName'] ?: 'Respondent') . ' · The Human Edge Scorecard',
        ]);
    }

    /**
     * GET …/research-data/{id}/scorecard-data — the record the respondent's
     * own PDF (exportPDF) is drawn from. Behind he.export: it names them.
     */
    public function scorecardData(string $id): Response
    {
        if ($denied = $this->guard('he.export')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $record = (new Records($this->app))->find((int) $id);

        if ($record === null) {
            return $this->fail('Record not found', 404);
        }

        $this->audit('he.record.report', 'submission', (string) $record['_id'], ['contains_contact_details' => true]);

        return $this->ok((new Instrument($this->app))->forBrowser() + ['record' => Records::export($record)]);
    }
}
