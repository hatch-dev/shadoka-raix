<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Admin;

use Apps\RaixDirectorScorecardPublic\Services\Analytics;
use Apps\RaixDirectorScorecardPublic\Services\RecordMirror;
use Apps\RaixDirectorScorecardPublic\Services\SubmissionService;
use Apps\RaixDirectorScorecardPublic\Services\Synthetic\SeedGenerator;
use Platform\Admin\AdminController;
use Platform\Core\Csrf;
use Platform\Core\Database;
use Platform\Core\Response;

/**
 * The research dataset: every scorecard, with a drilldown and a delete.
 */
final class DatasetController extends AdminController
{
    private const PER_PAGE = 40;

    /**
     * The tabs the reference's dataset screen carries, in its order.
     *
     * Its were JavaScript state (`dataTab`), so a pane could not be linked to
     * or reloaded. Here each is a URL, as on the analytics screen.
     */
    private const TABS = ['records' => 'Records', 'dims' => 'Competencies', 'levels' => 'Levels'];

    /**
     * What has to be typed to empty the dataset.
     *
     * The reference asked confirm('Delete every record? This cannot be
     * undone.') — one keystroke, and nothing at all with scripting off, where
     * the button would simply have done it. Everything else in this console is
     * recoverable: synthetic records regenerate from a seed. This is not.
     */
    private const WIPE_PHRASE = 'WIPE';

    /** GET /admin/raix-director-scorecard-public/research-data */
    public function index(): Response
    {
        if ($denied = $this->guard('dsp.view')) {
            return $denied;
        }

        // The v13 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $page             = max(1, (int) ($this->request->query['page'] ?? 1));
        $tab              = (string) ($this->request->query['tab'] ?? 'records');

        if (!isset(self::TABS[$tab])) {
            $tab = 'records';
        }

        /*
         * The include-synthetic setting is the only thing that narrows this
         * screen, as in the reference — sector and level are the analytics
         * screen's controls, and a second set here was mine, not that build's.
         *
         * It is applied in ONE place: the same value goes to Analytics, whose
         * scope() adds the identical clause, so the figures above the list and
         * the list itself can never be counting different records.
         */
        $analytics = new Analytics($this->app, $includeSynthetic);

        $where = ["s.`status` = 'complete'"];

        if (!$includeSynthetic) {
            $where[] = 's.`is_synthetic` = 0';
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(sprintf(
            'SELECT COUNT(*) FROM `%s` s LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id` WHERE %s',
            $this->table('submissions'),
            $clause
        ));

        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        // LIMIT/OFFSET are integers computed here, never client strings.
        $records = Database::select(sprintf(
            'SELECT s.`id`, s.`public_token`, s.`director_name`, s.`seat_role`,
                    s.`assessment_year`, s.`floor_score`, s.`mean_score`, s.`level_value`,
                    s.`is_synthetic`, s.`submitted_at`,
                    s.`organisation_id`,
                    COALESCE(s.`board_name`, o.`name`) AS board,
                    COALESCE(s.`sector`, o.`sector`) AS sector,
                    COALESCE(s.`company_size`, o.`size_band`) AS size_band,
                    fc.`code` AS floor_code,
                    rg.`name` AS level_name, rg.`colour_hex` AS level_colour
               FROM `%s` s
          LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id`
          LEFT JOIN `%s` fc ON fc.`id` = s.`floor_competency_id`
          LEFT JOIN `%s` rg ON rg.`instrument_id` = s.`instrument_id`
                           AND rg.`value` = s.`level_value`
              WHERE %s
           ORDER BY s.`submitted_at` DESC, s.`id` DESC
              LIMIT %d OFFSET %d',
            $this->table('submissions'),
            $this->table('competencies'),
            $this->table('rungs'),
            $clause,
            self::PER_PAGE,
            $offset
        ));

        $competencies = $analytics->competencies();

        return $this->view()->response('admin/dataset', [
            'title'            => 'Research data · Director Scorecard',
            'tab'              => $tab,
            'tabs'             => self::TABS,
            'records'          => $records,
            'scores'           => $this->scores($records),
            'total'            => $total,
            'page'             => $page,
            'pages'            => $pages,
            'includeSynthetic' => $includeSynthetic,
            'counts'           => $analytics->counts(),
            'overview'         => $analytics->overview(),
            // The reference's Sectors stat counts the sectors actually named,
            // so a record whose director skipped the question is not a sector.
            'sectorCount'      => count(array_filter(
                array_column($analytics->bySector(), 'sector'),
                static fn (string $s): bool => $s !== '' && $s !== 'Not specified'
            )),
            'competencies'     => $competencies,
            'bander'           => $analytics->bander(),
            'levels'           => $analytics->levelDistribution(),
            'canExport'        => \Platform\Core\Auth::can('dsp.export'),
            'canSeed'          => \Platform\Core\Auth::can('dsp.seed'),
            'itemCount'        => (int) Database::scalar(sprintf(
                'SELECT COUNT(*) FROM `%s` i
                   JOIN `%s` c ON c.`id` = i.`competency_id`
                  WHERE c.`instrument_id` = (
                        SELECT `id` FROM `%s` WHERE `status` = \'published\'
                     ORDER BY `published_at` DESC LIMIT 1)',
                $this->table('items'),
                $this->table('competencies'),
                $this->table('instruments')
            )),
        ]);
    }

    /**
     * Competency scores for the records on this page, keyed by submission then
     * by code — the row of pills each record carries in the reference.
     *
     * One query for the page rather than one per record. The ids are integers
     * this class just read out of its own table, so nothing from the request
     * reaches the SQL.
     *
     * @param  list<array<string, mixed>> $records
     * @return array<int, array<string, float>>
     */
    private function scores(array $records): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $records);

        if ($ids === []) {
            return [];
        }

        $byRecord = [];

        foreach (Database::select(sprintf(
            'SELECT ss.`submission_id` AS s, c.`code` AS code, ss.`score` AS score
               FROM `%s` ss
               JOIN `%s` c ON c.`id` = ss.`competency_id`
              WHERE ss.`submission_id` IN (%s)',
            $this->table('submission_scores'),
            $this->table('competencies'),
            implode(',', $ids)
        )) as $row) {
            $byRecord[(int) $row['s']][(string) $row['code']] = (float) $row['score'];
        }

        return $byRecord;
    }

    /**
     * GET /admin/raix-director-scorecard-public/console
     *
     * The reference's admin console, which the dataset screen opens as a
     * dialog. `?fragment=1` returns the panel alone for that dialog and
     * anything else returns the page, exactly as the seeder does — one
     * partial behind both, so the dialog and the page cannot differ.
     */
    public function console(): Response
    {
        if ($denied = $this->guard('dsp.view')) {
            return $denied;
        }

        // The v13 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $data = [
            'slug'             => $this->app->slug,
            'tab'              => ($this->request->query['tab'] ?? '') === 'export' ? 'export' : 'data',
            'csrf'             => Csrf::token(),
            'includeSynthetic' => ($this->request->query['synthetic'] ?? '') === '1',
            // counts() reports the whole table either way; the flag only picks
            // which records the EXPORT links below will carry.
            'counts'           => (new Analytics($this->app))->counts(),
            'canExport'        => \Platform\Core\Auth::can('dsp.export'),
            'canSeed'          => \Platform\Core\Auth::can('dsp.seed'),
            'canWipe'          => \Platform\Core\Auth::can('dsp.submission.delete'),
            'wipePhrase'       => self::WIPE_PHRASE,
            // Only ever set by a refused wipe, which lands on the page rather
            // than back in the dialog it was pressed in.
            'error'            => (string) ($this->request->query['error'] ?? ''),
        ];

        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_console', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/console', $data + [
            'title' => 'Console · Director Scorecard',
        ]);
    }

    /**
     * POST /admin/raix-director-scorecard-public/console/wipe
     *
     * Every synthetic record, and the previous edition's real submissions (no
     * record_id). Scorecards the v14 page saved are NOT deleted here: the
     * directive allows no bulk deletion of real records. Each is deleted on its
     * own, from Requests or from its own record screen. The demo set does not
     * reseed itself afterwards — the seeder is a deliberate action, as it is
     * everywhere else in this app.
     */
    public function wipe(): Response
    {
        if ($denied = $this->guard('dsp.submission.delete')) {
            return $denied;
        }

        if (strtoupper(trim((string) ($this->request->input('confirm') ?? ''))) !== self::WIPE_PHRASE) {
            return $this->redirect('admin/' . $this->app->slug . '/console?error='
                . urlencode('Type ' . self::WIPE_PHRASE . ' to empty the dataset. Nothing was deleted.'));
        }

        $before = Database::selectOne(sprintf(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN `is_synthetic` = 0 THEN 1 ELSE 0 END) AS real_records,
                    SUM(CASE WHEN `is_synthetic` = 1 THEN 1 ELSE 0 END) AS synthetic
               FROM `%s` WHERE `is_synthetic` = 1 OR `record_id` IS NULL',
            $this->table('submissions')
        ));

        /*
         * Audited BEFORE the delete, as the single-record delete is: afterwards
         * there is no row left to describe, and this is the one action in the
         * console whose only remaining account of itself is the audit entry.
         */
        $this->audit('dsp.dataset.wiped', 'dataset', 'all', [
            'records'   => (int) $before['total'],
            'real'      => (int) $before['real_records'],
            'synthetic' => (int) $before['synthetic'],
        ]);

        // The synthetic set (and the demo records it mirrors) through the
        // seeder's own purge, which also removes demo boards nothing points at
        // any more; then the previous edition's real rows.
        $removed  = SeedGenerator::purge($this->app);
        $removed += Database::statement(sprintf(
            'DELETE FROM `%s` WHERE `record_id` IS NULL',
            $this->table('submissions')
        ));

        return $this->redirect('admin/' . $this->app->slug . '/research-data?wiped=' . $removed);
    }

    /** GET /admin/raix-director-scorecard-public/research-data/{id} */
    public function show(string $id): Response
    {
        if ($denied = $this->guard('dsp.view')) {
            return $denied;
        }

        // The v13 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $submission = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ?', $this->table('submissions')),
            [(int) $id]
        );

        if ($submission === null) {
            return $this->view()->response('errors/forbidden', [
                'title'      => 'Not found · RAI-X admin',
                'capability' => null,
            ], 404);
        }

        $service = new SubmissionService($this->app);

        // Item-level marks, which is what makes this a research record rather
        // than a score. Ordered the way the director answered them.
        $items = Database::select(sprintf(
            'SELECT c.`code`, c.`position` AS c_pos, i.`position` AS i_pos, i.`statement`,
                    r.`mark`, r.`evidence_note`
               FROM `%s` c
               JOIN `%s` i ON i.`competency_id` = c.`id`
          LEFT JOIN `%s` r ON r.`item_id` = i.`id` AND r.`submission_id` = ?
              WHERE c.`instrument_id` = ?
           ORDER BY c.`position`, i.`position`',
            $this->table('competencies'),
            $this->table('items'),
            $this->table('submission_responses')
        ), [$submission['id'], $submission['instrument_id']]);

        $data = [
            'submission'   => $submission,
            'result'       => $submission['status'] === 'complete' ? $service->result($submission) : null,
            'items'        => $items,
            'organisation' => $submission['organisation_id'] === null ? null : Database::selectOne(
                'SELECT `name`, `sector`, `size_band` FROM `organisations` WHERE `id` = ?',
                [$submission['organisation_id']]
            ),
        ];

        /*
         * ?fragment=1 returns the scorecard alone, for the dialog the dataset
         * list opens over itself. Every row there opens its BOARD's profile;
         * a scorecard with no board has none, so its row opens this instead of
         * being the one row on the screen that navigates away. Same controller
         * and same result as the page behind it, so the two cannot disagree.
         */
        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_record-modal', $data + [
                'bander' => (new Analytics($this->app))->bander(),
            ]))->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/record', $data + [
            'title'     => 'Scorecard · Director Scorecard',
            'canDelete' => \Platform\Core\Auth::can('dsp.submission.delete'),
        ]);
    }

    /** POST /admin/raix-director-scorecard-public/research-data/{id}/delete */
    public function destroy(string $id): Response
    {
        if ($denied = $this->guard('dsp.submission.delete')) {
            return $denied;
        }

        $submission = Database::selectOne(
            sprintf('SELECT `id`, `public_token`, `record_id`, `is_synthetic` FROM `%s` WHERE `id` = ?', $this->table('submissions')),
            [(int) $id]
        );

        if ($submission === null) {
            return $this->redirect('admin/' . $this->app->slug . '/research-data');
        }

        // Audited BEFORE the delete: afterwards there is no row to describe,
        // and a deletion nobody can account for is the one that matters.
        $this->audit('dsp.submission.deleted', 'submission', (string) $submission['id'], [
            'token'     => $submission['public_token'],
            'record'    => $submission['record_id'],
            'synthetic' => (bool) $submission['is_synthetic'],
        ]);

        // A scorecard the v14 page saved is deleted where it is kept, in
        // dsp_records, which takes its console copy with it.
        if ($submission['record_id'] !== null) {
            $store = new \Apps\RaixDirectorScorecardPublic\Services\RecordStore($this->app);
            (int) $submission['is_synthetic'] === 1
                ? $store->deleteSyntheticOne((string) $submission['record_id'])
                : $store->deleteReal((string) $submission['record_id']);
        }

        Database::statement(
            sprintf('DELETE FROM `%s` WHERE `id` = ?', $this->table('submissions')),
            [(int) $id]
        );

        return $this->redirect('admin/' . $this->app->slug . '/research-data?deleted=1');
    }
}
