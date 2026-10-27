<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Controllers\Admin;

use Apps\AiExecutiveReadinessScorecard\Services\Analytics;
use Apps\AiExecutiveReadinessScorecard\Services\RecordMirror;
use Apps\AiExecutiveReadinessScorecard\Services\Records;
use Apps\AiExecutiveReadinessScorecard\Services\Stats;
use Apps\AiExecutiveReadinessScorecard\Services\SubmissionService;
use Apps\AiExecutiveReadinessScorecard\Services\Synthetic\SeedGenerator;
use Platform\Admin\AdminController;
use Platform\Core\Auth;
use Platform\Core\Csrf;
use Platform\Core\Database;
use Platform\Core\Response;

/**
 * The research dataset — the reference's renderData(), its admin console
 * (adminPanel) and its record dialog (openRecord) — plus the two deletions.
 */
final class DatasetController extends AdminController
{
    private const TABS = ['records' => 'Records', 'dims' => 'Dimensions', 'levels' => 'Levels'];

    /**
     * What has to be typed to empty the dataset. The reference asked confirm()
     * — one keystroke. This one removes REAL research data holding named,
     * contactable people, and no undo exists anywhere in the app.
     */
    private const WIPE_PHRASE = 'WIPE';

    /** GET /admin/ai-executive-readiness-scorecard/research-data */
    public function index(): Response
    {
        if ($denied = $this->guard('exr.view')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $query     = $this->request->query;
        $analytics = new Analytics($this->app);
        $all       = $analytics->all();
        $included  = $analytics->includeSynthetic($query);
        $recs      = array_values(array_filter($all, static fn (array $r): bool => $included || !$r['synthetic']));

        $tab = (string) ($query['tab'] ?? 'records');
        if (!isset(self::TABS[$tab])) {
            $tab = 'records';
        }

        $real = array_values(array_filter($all, static fn (array $r): bool => !$r['synthetic']));

        return $this->view()->response('admin/dataset', [
            'title'            => 'Research data · AI Executive Readiness — The Enterprise Scorecard',
            'tab'              => $tab,
            'tabs'             => self::TABS,
            'includeSynthetic' => $included,
            'syntheticCount'   => count($all) - count($real),
            'recs'             => Stats::newestFirst($recs),
            'latest'           => Stats::newestFirst($real)[0] ?? null,
            'search'           => (string) ($query['q'] ?? ''),
            'respondents'      => Stats::respondentCount($recs),
            'organisations'    => Stats::orgCount($recs),
            'scores'           => $recs === [] ? null : $analytics->stats()->groupScores($recs),
            'spread'           => $recs === [] ? null : $analytics->stats()->spread($recs),
            'levelRows'        => $analytics->stats()->levelRows($recs),
            'dimensions'       => $analytics->dimensions(),
            'bander'           => $analytics->stats()->bander(),
            'canExport'        => Auth::can('exr.export'),
            'canSeed'          => Auth::can('exr.seed'),
            'notice'           => $this->notice($query),
        ]);
    }

    /** The one-line outcome of whatever sent the browser here. */
    private function notice(array $query): string
    {
        return match (true) {
            isset($query['seeded'])  => 'Seeded ' . (int) $query['seeded'] . ' scorecards.',
            isset($query['purged'])  => 'Synthetic records deleted (' . (int) $query['purged'] . ').',
            isset($query['wiped'])   => 'Dataset wiped (' . (int) $query['wiped'] . ' records).',
            isset($query['deleted']) => 'Scorecard deleted.',
            isset($query['empty'])   => 'Nothing to export',
            default                  => '',
        };
    }

    /**
     * GET /admin/ai-executive-readiness-scorecard/console
     *
     * adminPanel(): Latest · Find · Data · Export, opening on Latest.
     * ?fragment=1 returns the panel for the dialog; anything else, the page.
     */
    public function console(): Response
    {
        if ($denied = $this->guard('exr.view')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $query     = $this->request->query;
        $analytics = new Analytics($this->app);
        $all       = $analytics->all();

        $tab = (string) ($query['tab'] ?? 'latest');
        if (!in_array($tab, ['latest', 'find', 'data', 'export'], true)) {
            $tab = 'latest';
        }

        // Latest: the newest and the five before it. Real only unless the pane's
        // OWN box is ticked (the reference's adminLatestSyn).
        $latestSynthetic = ($query['lsyn'] ?? '') === '1';
        $latest          = array_slice(Stats::newestFirst(array_values(array_filter(
            $all,
            static fn (array $r): bool => $latestSynthetic || !$r['synthetic']
        ))), 0, 6);

        // Find: the whole store, synthetic included, the first 40 newest first.
        $find = trim((string) ($query['q'] ?? ''));
        $hits = $find === '' ? [] : Stats::newestFirst(array_values(array_filter(
            $all,
            static fn (array $r): bool => Analytics::matches($r, $find)
        )));

        $data = [
            'slug'             => $this->app->slug,
            'tab'              => $tab,
            'csrf'             => Csrf::token(),
            'includeSynthetic' => $analytics->includeSynthetic($query),
            'counts'           => $analytics->counts(),
            'canExport'        => Auth::can('exr.export'),
            'canSeed'          => Auth::can('exr.seed'),
            'canWipe'          => Auth::can('exr.submission.delete'),
            'wipePhrase'       => self::WIPE_PHRASE,
            'error'            => (string) ($query['error'] ?? ''),
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
            'title' => 'Console · AI Executive Readiness — The Enterprise Scorecard',
        ]);
    }

    /** POST …/console/wipe — every synthetic record and the previous edition's; never a v4 record. */
    public function wipe(): Response
    {
        if ($denied = $this->guard('exr.submission.delete')) {
            return $denied;
        }

        if (strtoupper(trim((string) ($this->request->input('confirm') ?? ''))) !== self::WIPE_PHRASE) {
            return $this->redirect('admin/' . $this->app->slug . '/console?error='
                . urlencode('Type ' . self::WIPE_PHRASE . ' to empty the dataset. Nothing was deleted.'));
        }

        /*
         * What Wipe may delete: every synthetic record, and the previous
         * edition's real submissions (no record_id). Scorecards the v4 page saved
         * are NOT deleted here: the directive allows no bulk deletion of real
         * records (B4, section 8). Each is deleted on its own, from Requests or
         * from its own record screen.
         */
        $before = Database::selectOne(sprintf(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN `is_synthetic` = 0 THEN 1 ELSE 0 END) AS real_records,
                    SUM(CASE WHEN `is_synthetic` = 1 THEN 1 ELSE 0 END) AS synthetic
               FROM `%s` WHERE `is_synthetic` = 1 OR `record_id` IS NULL',
            $this->table('submissions')
        ));

        // Audited BEFORE the delete: afterwards there is nothing left to describe.
        $this->audit('exr.dataset.wiped', 'dataset', 'all', [
            'records'   => (int) $before['total'],
            'real'      => (int) $before['real_records'],
            'synthetic' => (int) $before['synthetic'],
        ]);

        // The synthetic set (and the demo records it mirrors) through the
        // seeder's own purge, which also removes demo organisations nothing
        // points at any more; then the previous edition's real rows.
        $removed  = SeedGenerator::purge($this->app);
        $removed += Database::statement(sprintf('DELETE FROM `%s` WHERE `record_id` IS NULL', $this->table('submissions')));

        // The previous edition's organisations nothing points at any more.
        SeedGenerator::pruneOrganisations();
        Records::flush();

        return $this->redirect('admin/' . $this->app->slug . '/research-data?wiped=' . $removed);
    }

    /**
     * GET …/research-data/{id} — openRecord(): one questionnaire, both
     * profiles, every answer side by side.
     */
    public function show(string $id): Response
    {
        if ($denied = $this->guard('exr.view')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $record = (new Records($this->app))->find((int) $id);

        if ($record === null) {
            return (($this->request->query['fragment'] ?? '') === '1')
                ? $this->fail('Record not found', 404)
                : $this->view()->response('errors/forbidden', ['title' => 'Not found · RAI-X admin', 'capability' => null], 404);
        }

        $submission = (new SubmissionService($this->app))->find((int) $id);
        $analytics  = new Analytics($this->app);

        $data = [
            'slug'       => $this->app->slug,
            'record'     => $record,
            'result'     => (new SubmissionService($this->app))->result($submission),
            'bander'     => $analytics->stats()->bander(),
            'csrf'       => Csrf::token(),
            'canDelete'  => Auth::can('exr.submission.delete'),
            'canExport'  => Auth::can('exr.export'),
        ];

        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_record-modal', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/record', $data + [
            'title' => $record['userName'] . ' · AI Executive Readiness — The Enterprise Scorecard',
        ]);
    }

    /**
     * GET …/research-data/{id}/scorecard-data — the payload the respondent's own
     * results page and PDF are built from, for the dialog's PDF button. Behind
     * exr.export: that document names the respondent.
     */
    public function scorecardData(string $id): Response
    {
        if ($denied = $this->guard('exr.export')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $submission = (new SubmissionService($this->app))->find((int) $id);

        if ($submission === null || $submission['status'] !== 'complete') {
            return $this->fail('Record not found', 404);
        }

        $this->audit('exr.record.report', 'submission', (string) $submission['id'], ['contains_contact_details' => true]);

        return $this->ok((new SubmissionService($this->app))->result($submission));
    }

    /** POST …/research-data/{id}/delete */
    public function destroy(string $id): Response
    {
        if ($denied = $this->guard('exr.submission.delete')) {
            return $denied;
        }

        $submission = (new SubmissionService($this->app))->find((int) $id);

        if ($submission === null) {
            return $this->redirect('admin/' . $this->app->slug . '/research-data');
        }

        // Audited before the delete. The email is kept because a deletion
        // request under the privacy notice arrives as an address.
        $this->audit('exr.submission.deleted', 'submission', (string) $submission['id'], [
            'token'        => $submission['public_token'],
            'record'       => $submission['record_id'] ?? null,
            'synthetic'    => (bool) $submission['is_synthetic'],
            'email'        => $submission['respondent_email'],
            'organisation' => $submission['organisation_name'],
        ]);

        // A scorecard the v4 page saved is deleted where it is kept, in
        // exr_records, which takes its console copy with it.
        if (($submission['record_id'] ?? null) !== null) {
            $store = new \Apps\AiExecutiveReadinessScorecard\Services\RecordStore($this->app);
            (int) $submission['is_synthetic'] === 1
                ? $store->deleteSyntheticOne((string) $submission['record_id'])
                : $store->deleteReal((string) $submission['record_id']);
        }

        Database::statement(sprintf('DELETE FROM `%s` WHERE `id` = ?', $this->table('submissions')), [(int) $id]);
        Records::flush();

        return $this->redirect('admin/' . $this->app->slug . '/research-data?deleted=1');
    }
}
