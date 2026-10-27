<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Controllers\Admin;

use Apps\AiSovereigntyScorecard\Services\Analytics;
use Apps\AiSovereigntyScorecard\Services\SubmissionService;
use Apps\AiSovereigntyScorecard\Services\RecordMirror;
use Platform\Admin\AdminController;
use Platform\Core\Auth;
use Platform\Core\Csrf;
use Platform\Core\Database;
use Platform\Core\Response;

/**
 * The research dataset: every scorecard, with search, a drilldown and a delete.
 *
 * The search is the reference's, and it is why this screen exists rather than
 * being a table: an admin looking for a submission has a half-remembered name,
 * not an id. It folds accents and ignores punctuation, so "jose" finds "José" and
 * "5551234567" finds "+1 555 123 4567".
 *
 * What is different from the reference: the search runs in SQL, over the whole
 * dataset, instead of over whatever the browser happened to be holding. Its
 * `filterDataRecords` filtered an in-memory array, so it could only ever find
 * records already loaded — which at any size worth searching is not all of them.
 */
final class DatasetController extends AdminController
{
    /**
     * The reference lists every record on one page, so this is set high enough
     * that any realistic dataset does too; the Newer/Older links only appear
     * past it, where rendering everything at once would make the page slow.
     */
    private const PER_PAGE = 500;

    /**
     * The tabs the reference's dataset screen carries, in its order.
     *
     * Its were JavaScript state (`dataTab`), so a pane could not be linked to or
     * reloaded. Here each is a URL, as on the analytics screen.
     */
    private const TABS = ['records' => 'Records', 'dims' => 'Dimensions', 'levels' => 'Levels'];

    /**
     * What has to be typed to empty the dataset.
     *
     * The reference asked confirm('Delete every record? This cannot be undone.')
     * — one keystroke, and nothing at all with scripting off, where the button
     * would simply have done it. Everything else in this console is recoverable:
     * synthetic records regenerate from a seed. This is not, and on this
     * instrument the rows hold named, contactable people.
     */
    private const WIPE_PHRASE = 'WIPE';

    /** GET /admin/ai-sovereignty-scorecard/research-data */
    public function index(): Response
    {
        if ($denied = $this->guard('sov.view')) {
            return $denied;
        }

        // The v6 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $query = $this->request->query;

        $includeSynthetic = $this->includeSynthetic($query);
        $page             = max(1, (int) ($query['page'] ?? 1));
        $search           = trim((string) ($query['q'] ?? ''));

        $tab = (string) ($query['tab'] ?? 'records');
        if (!isset(self::TABS[$tab])) {
            $tab = 'records';
        }

        /*
         * The include-synthetic setting and the search box are the only things
         * that narrow this screen, as in the reference — sector and level are the
         * analytics screen's controls.
         *
         * The synthetic flag is applied in ONE place: the same value goes to
         * Analytics, whose scope() adds the identical clause, so the figures
         * above the list and the list itself can never be counting different
         * records.
         */
        $analytics = new Analytics($this->app, $includeSynthetic);

        [$where, $bindings] = $this->listConditions($includeSynthetic, $search);

        $total = (int) Database::scalar(sprintf(
            'SELECT COUNT(*) FROM `%s` s WHERE %s',
            $this->table('submissions'),
            $where
        ), $bindings);

        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        // LIMIT/OFFSET are integers computed here, never client strings.
        $records = Database::select(sprintf(
            'SELECT s.`id`, s.`public_token`, s.`respondent_name`, s.`respondent_email`,
                    s.`respondent_phone`, s.`organisation_name`, s.`organisation_size`,
                    s.`role`, s.`sector`, s.`assessment_year`,
                    s.`mean_score`, s.`floor_score`, s.`level_value`,
                    s.`is_synthetic`, s.`submitted_at`, s.`confirmed_at`, s.`organisation_id`,
                    fd.`code` AS floor_code,
                    rg.`name` AS level_name, rg.`colour_hex` AS level_colour
               FROM `%s` s
          LEFT JOIN `%s` fd ON fd.`id` = s.`floor_dimension_id`
          LEFT JOIN `%s` rg ON rg.`instrument_id` = s.`instrument_id` AND rg.`value` = s.`level_value`
              WHERE %s
           ORDER BY s.`submitted_at` DESC, s.`id` DESC
              LIMIT %d OFFSET %d',
            $this->table('submissions'),
            $this->table('dimensions'),
            $this->table('rungs'),
            $where,
            self::PER_PAGE,
            $offset
        ), $bindings);

        $dimensions = $analytics->dimensions();

        return $this->view()->response('admin/dataset', [
            'title'            => 'Research data · The AI Sovereignty Scorecard',
            'tab'              => $tab,
            'tabs'             => self::TABS,
            'records'          => $records,
            'scores'           => $this->scores($records),
            'latest'           => $this->latestReal(),
            'search'           => $search,
            'total'            => $total,
            'page'             => $page,
            'pages'            => $pages,
            'perPage'          => self::PER_PAGE,
            'includeSynthetic' => $includeSynthetic,
            'counts'           => $analytics->counts(),
            'overview'         => $analytics->overview(),
            // The reference's Sectors stat counts the sectors actually named, so
            // a record whose respondent skipped the question is not a sector.
            'sectorCount'      => count(array_filter(
                array_column($analytics->bySector(), 'sector'),
                static fn (string $s): bool => $s !== '' && $s !== 'Not specified'
            )),
            'dimensions'       => $dimensions,
            'aggregateFloor'   => $analytics->aggregateFloor($dimensions),
            'bander'           => $analytics->bander(),
            'levels'           => $analytics->levelDistribution(),
            'canExport'        => Auth::can('sov.export'),
            'canSeed'          => Auth::can('sov.seed'),
            'itemCount'        => (int) Database::scalar(sprintf(
                'SELECT COUNT(*) FROM `%s` i
                   JOIN `%s` d ON d.`id` = i.`dimension_id`
                  WHERE d.`instrument_id` = (
                        SELECT `id` FROM `%s` WHERE `status` = \'published\'
                     ORDER BY `published_at` DESC LIMIT 1)',
                $this->table('items'),
                $this->table('dimensions'),
                $this->table('instruments')
            )),
            'wiped'            => (int) ($query['wiped'] ?? 0),
            'deleted'          => ($query['deleted'] ?? '') === '1',
            'seeded'           => (int) ($query['seeded'] ?? 0),
            'purged'           => (int) ($query['purged'] ?? 0),
        ]);
    }

    /**
     * The list's WHERE clause and its bindings.
     *
     * The search folds accents and punctuation the same way on both sides of the
     * comparison: the needle is normalised here, and the haystack by the SQL
     * below. Digits-only needles are matched against the phone number with its
     * own separators stripped, which is what lets "5551234567" find
     * "+1 555 123 4567".
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function listConditions(bool $includeSynthetic, string $search): array
    {
        $where    = ["s.`status` = 'complete'"];
        $bindings = [];

        if (!$includeSynthetic) {
            $where[] = 's.`is_synthetic` = 0';
        }

        if ($search !== '') {
            /*
             * Every word must match, in any order — the reference's rule. Each
             * word is its own AND, so "meridian dana" finds Dana at Meridian and
             * not every record mentioning either.
             */
            foreach (preg_split('/\s+/u', self::fold($search)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }

                $like = '%' . $word . '%';

                $clause = '(LOWER(s.`respondent_name`) LIKE ?'
                        . ' OR LOWER(s.`respondent_email`) LIKE ?'
                        . ' OR LOWER(s.`organisation_name`) LIKE ?'
                        . ' OR LOWER(COALESCE(s.`role`, \'\')) LIKE ?'
                        . ' OR LOWER(COALESCE(s.`sector`, \'\')) LIKE ?';

                array_push($bindings, $like, $like, $like, $like, $like);

                // A digits-only word is a phone search. Compared against the
                // number with every separator removed.
                if (ctype_digit($word)) {
                    $clause .= ' OR REPLACE(REPLACE(REPLACE(REPLACE(s.`respondent_phone`, \' \', \'\'),'
                             . ' \'-\', \'\'), \'(\', \'\'), \')\') LIKE ?';
                    $bindings[] = $like;
                }

                $where[] = $clause . ')';
            }
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * Fold a search term: lowercase, accents stripped, punctuation dropped.
     *
     * MySQL's default collation is accent-insensitive, so "jose" already matches
     * "José" in the LIKE. This folds the NEEDLE for the same reason the reference
     * did — so that a pasted "+1 (555) 123-4567" becomes digits, and a typed
     * "O'Neill" matches a stored "ONeill".
     */
    private static function fold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $value  = mb_strtolower($folded === false ? $value : $value);

        // Keep letters, digits and spaces. Everything else becomes nothing, so
        // punctuation is ignored rather than required.
        return trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value));
    }

    /**
     * Whether synthetic records are in: the `synthetic` parameter when it is
     * given, otherwise ON exactly when the dataset holds nothing but synthetic
     * records — the reference's autoIncludeSynthetic(), and the rule the
     * Analytics screen already follows.
     *
     * Without it, a demo-only dataset opened Research data on an empty list and
     * every export in the Console answered "Nothing to export", while Analytics
     * beside them showed the same records. Unticking the box still sends
     * synthetic=0 and empties the view.
     *
     * @param array<string, mixed> $query
     */
    private function includeSynthetic(array $query): bool
    {
        if (array_key_exists('synthetic', $query)) {
            return $query['synthetic'] === '1';
        }

        $counts = (new Analytics($this->app, true))->counts();

        return $counts['real'] === 0 && $counts['synthetic'] > 0;
    }

    /**
     * The newest records matching the dataset's own conditions, as rows for the
     * console's Latest and Find lists. The limit is an integer set here.
     *
     * @return list<array<string, mixed>>
     */
    private function listed(bool $includeSynthetic, string $search, int $limit): array
    {
        [$where, $bindings] = $this->listConditions($includeSynthetic, $search);

        return Database::select(sprintf(
            'SELECT s.`id`, s.`respondent_name`, s.`respondent_email`, s.`respondent_phone`,
                    s.`organisation_name`, s.`role`, s.`submitted_at`, s.`is_synthetic`,
                    s.`mean_score`, s.`floor_score`, s.`level_value`,
                    rg.`colour_hex` AS level_colour
               FROM `%s` s
          LEFT JOIN `%s` rg ON rg.`instrument_id` = s.`instrument_id` AND rg.`value` = s.`level_value`
              WHERE %s
           ORDER BY s.`submitted_at` DESC, s.`id` DESC
              LIMIT %d',
            $this->table('submissions'),
            $this->table('rungs'),
            $where,
            $limit
        ), $bindings);
    }

    /**
     * The newest REAL submission.
     *
     * Shown at the top of the screen so it is visible without opening the
     * console, as the reference's `renderLatestStrip()` did. Synthetic rows stay
     * out unconditionally: a generated record is not something anybody took, so
     * it is never "the latest submission" however recent its timestamp.
     *
     * @return array<string, mixed>|null
     */
    private function latestReal(): ?array
    {
        return Database::selectOne(sprintf(
            'SELECT s.`id`, s.`respondent_name`, s.`respondent_email`, s.`respondent_phone`,
                    s.`organisation_name`, s.`role`, s.`assessment_year`, s.`submitted_at`,
                    s.`mean_score`, s.`floor_score`, s.`level_value`, s.`organisation_id`,
                    fd.`code` AS floor_code, rg.`name` AS level_name, rg.`colour_hex` AS level_colour
               FROM `%s` s
          LEFT JOIN `%s` fd ON fd.`id` = s.`floor_dimension_id`
          LEFT JOIN `%s` rg ON rg.`instrument_id` = s.`instrument_id` AND rg.`value` = s.`level_value`
              WHERE s.`status` = \'complete\' AND s.`is_synthetic` = 0
           ORDER BY s.`submitted_at` DESC, s.`id` DESC
              LIMIT 1',
            $this->table('submissions'),
            $this->table('dimensions'),
            $this->table('rungs')
        ));
    }

    /**
     * Dimension scores for the records on this page, keyed by submission then by
     * code — the row of pills each record carries in the reference.
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
            'SELECT ss.`submission_id` AS s, d.`code` AS code, ss.`score` AS score
               FROM `%s` ss
               JOIN `%s` d ON d.`id` = ss.`dimension_id`
              WHERE ss.`submission_id` IN (%s)
           ORDER BY d.`position`',
            $this->table('submission_scores'),
            $this->table('dimensions'),
            implode(',', $ids)
        )) as $row) {
            $byRecord[(int) $row['s']][(string) $row['code']] = (float) $row['score'];
        }

        return $byRecord;
    }

    /**
     * GET /admin/ai-sovereignty-scorecard/console
     *
     * The reference's admin console, which the dataset screen opens as a dialog.
     * `?fragment=1` returns the panel alone for that dialog and anything else
     * returns the page, exactly as the seeder does — one partial behind both, so
     * the dialog and the page cannot differ.
     */
    public function console(): Response
    {
        if ($denied = $this->guard('sov.view')) {
            return $denied;
        }

        // The v6 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $query = $this->request->query;

        // The reference's four tabs, opening on Latest as it did.
        $tab = (string) ($query['tab'] ?? 'latest');

        if (!in_array($tab, ['latest', 'find', 'data', 'export'], true)) {
            $tab = 'latest';
        }

        /*
         * Latest: the newest scorecard and the five before it. Real only unless
         * the pane's own box is ticked — its own, not the dataset page's, as in
         * the reference (adminLatestSyn).
         */
        $latestSynthetic = ($query['lsyn'] ?? '') === '1';
        $latest          = $tab === 'latest' ? $this->listed($latestSynthetic, '', 6) : [];

        /*
         * Find: every record, synthetic included, as the reference's adminSearch
         * searched its whole store. The first 40, newest first, and a count of
         * the rest. The box refetches `results=1` as you type.
         */
        $find  = trim((string) ($query['q'] ?? ''));
        $hits  = [];
        $hitsN = 0;

        if ($tab === 'find' && $find !== '') {
            [$where, $bindings] = $this->listConditions(true, $find);

            $hitsN = (int) Database::scalar(sprintf(
                'SELECT COUNT(*) FROM `%s` s WHERE %s',
                $this->table('submissions'),
                $where
            ), $bindings);

            $hits = $this->listed(true, $find, 40);
        }

        $data = [
            'slug'             => $this->app->slug,
            'tab'              => $tab,
            'csrf'             => Csrf::token(),
            'includeSynthetic' => $this->includeSynthetic($query),
            // counts() reports the whole table either way; the flag only picks
            // which records the EXPORT links below will carry.
            'counts'           => (new Analytics($this->app))->counts(),
            'canExport'        => Auth::can('sov.export'),
            'canSeed'          => Auth::can('sov.seed'),
            'canWipe'          => Auth::can('sov.submission.delete'),
            'wipePhrase'       => self::WIPE_PHRASE,
            // Only ever set by a refused wipe, which lands on the page rather
            // than back in the dialog it was pressed in.
            'error'            => (string) ($this->request->query['error'] ?? ''),
            'latest'           => $latest,
            'latestSynthetic'  => $latestSynthetic,
            'find'             => $find,
            'hits'             => $hits,
            'hitsTotal'        => $hitsN,
        ];

        // The Find box's live refresh: the result list alone.
        if ($tab === 'find' && ($query['results'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_console-find-results', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_console', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/console', $data + [
            'title' => 'Console · The AI Sovereignty Scorecard',
        ]);
    }

    /**
     * POST /admin/ai-sovereignty-scorecard/console/wipe
     *
     * Every record, real and synthetic. The demo set does not reseed itself
     * afterwards — the seeder is a deliberate action, as it is everywhere else in
     * this app. The reference tracked a `-cleared` sentinel in storage precisely
     * because its seeder ran on boot; nothing here runs on its own, so there is
     * nothing to suppress.
     */
    public function wipe(): Response
    {
        if ($denied = $this->guard('sov.submission.delete')) {
            return $denied;
        }

        if (strtoupper(trim((string) ($this->request->input('confirm') ?? ''))) !== self::WIPE_PHRASE) {
            return $this->redirect('admin/' . $this->app->slug . '/console?error='
                . urlencode('Type ' . self::WIPE_PHRASE . ' to empty the dataset. Nothing was deleted.'));
        }

        /*
         * What Wipe may delete: every synthetic record, and the previous
         * edition's real submissions (no record_id). Scorecards the v6 page saved
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

        /*
         * Audited BEFORE the delete, as the single-record delete is: afterwards
         * there is no row left to describe, and this is the one action in the
         * console whose only remaining account of itself is the audit entry.
         */
        $this->audit('sov.dataset.wiped', 'dataset', 'all', [
            'records'   => (int) $before['total'],
            'real'      => (int) $before['real_records'],
            'synthetic' => (int) $before['synthetic'],
        ]);

        // The synthetic set (and the demo records it mirrors) through the
        // seeder's own purge, which also removes demo organisations nothing
        // points at any more; then the previous edition's real rows.
        $removed  = \Apps\AiSovereigntyScorecard\Services\Synthetic\SeedGenerator::purge($this->app);
        $removed += Database::statement(sprintf(
            'DELETE FROM `%s` WHERE `record_id` IS NULL',
            $this->table('submissions')
        ));

        return $this->redirect('admin/' . $this->app->slug . '/research-data?wiped=' . $removed);
    }

    /** GET /admin/ai-sovereignty-scorecard/research-data/{id} */
    public function show(string $id): Response
    {
        if ($denied = $this->guard('sov.view')) {
            return $denied;
        }

        // The v6 page's records, in step before anything is read (RecordMirror).
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

        /*
         * Every one of the sixteen marks, with the question it answers and both of
         * that question's written anchors.
         *
         * The anchors are what make this a research record rather than a row of
         * numbers: a 2 on "Decision rights" means "unclear for at least one of
         * these", and without that text beside it the mark is not interpretable
         * by anyone who is not holding the instrument.
         */
        $items = Database::select(sprintf(
            'SELECT d.`code`, d.`name` AS dimension, d.`position` AS d_pos,
                    i.`position` AS i_pos, i.`label`, i.`question`,
                    i.`anchor_low_label`, i.`anchor_low_text`,
                    i.`anchor_high_label`, i.`anchor_high_text`,
                    r.`mark`
               FROM `%s` d
               JOIN `%s` i ON i.`dimension_id` = d.`id`
          LEFT JOIN `%s` r ON r.`item_id` = i.`id` AND r.`submission_id` = ?
              WHERE d.`instrument_id` = ?
           ORDER BY d.`position`, i.`position`',
            $this->table('dimensions'),
            $this->table('items'),
            $this->table('submission_responses')
        ), [$submission['id'], $submission['instrument_id']]);

        $data = [
            'submission'   => $submission,
            'result'       => $submission['status'] === 'complete' ? $service->result($submission) : null,
            'items'        => $items,
            'organisation' => $submission['organisation_id'] === null ? null : Database::selectOne(
                'SELECT `id`, `name`, `sector`, `size_band` FROM `organisations` WHERE `id` = ?',
                [$submission['organisation_id']]
            ),
            'slug'         => $this->app->slug,
        ];

        /*
         * ?fragment=1 returns the scorecard alone, for the dialog the dataset
         * list opens over itself — which is what clicking a record did in the
         * reference. Same controller and same result as the page behind it, so
         * the two cannot disagree.
         */
        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_record-modal', $data + [
                'bander' => (new Analytics($this->app))->bander(),
                'csrf'   => Csrf::token(),
            ]))->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/record', $data + [
            'title'     => 'Scorecard · The AI Sovereignty Scorecard',
            'canDelete' => Auth::can('sov.submission.delete'),
            'bander'    => (new Analytics($this->app))->bander(),
        ]);
    }

    /**
     * GET /admin/ai-sovereignty-scorecard/research-data/{id}/scorecard-data
     *
     * One respondent's result, for the PDF the record dialog downloads — the
     * reference's `exportPDF()` called from `openRecord()`, which had the record
     * in memory.
     *
     * It returns the SAME payload SubmissionService::result() renders the
     * respondent's own results page from, so the copy an admin downloads is the
     * document that respondent received rather than a second rendering of it
     * that could drift.
     *
     * Behind sov.export rather than sov.view: that document carries the
     * respondent's name, email address and phone number.
     */
    public function scorecardData(string $id): Response
    {
        if ($denied = $this->guard('sov.export')) {
            return $denied;
        }

        // The v6 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $submission = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ?', $this->table('submissions')),
            [(int) $id]
        );

        if ($submission === null) {
            return $this->fail('That scorecard could not be found.', 404);
        }

        if ($submission['status'] !== 'complete') {
            return $this->fail('That scorecard has not been finished, so it has no result to print.', 409);
        }

        $this->audit('sov.record.report', 'submission', (string) $submission['id'], [
            'contains_contact_details' => true,
        ]);

        return $this->ok((new SubmissionService($this->app))->result($submission));
    }

    /** POST /admin/ai-sovereignty-scorecard/research-data/{id}/delete */
    public function destroy(string $id): Response
    {
        if ($denied = $this->guard('sov.submission.delete')) {
            return $denied;
        }

        $submission = Database::selectOne(
            sprintf(
                'SELECT `id`, `public_token`, `record_id`, `is_synthetic`, `respondent_email`, `organisation_name`
                   FROM `%s` WHERE `id` = ?',
                $this->table('submissions')
            ),
            [(int) $id]
        );

        if ($submission === null) {
            return $this->redirect('admin/' . $this->app->slug . '/research-data');
        }

        // Audited BEFORE the delete: afterwards there is no row to describe, and
        // a deletion nobody can account for is the one that matters. The email is
        // recorded because a deletion request under the privacy notice arrives as
        // an address, and this is the only place that will still show it.
        $this->audit('sov.submission.deleted', 'submission', (string) $submission['id'], [
            'token'        => $submission['public_token'],
            'record'       => $submission['record_id'],
            'synthetic'    => (bool) $submission['is_synthetic'],
            'email'        => $submission['respondent_email'],
            'organisation' => $submission['organisation_name'],
        ]);

        // A scorecard the v6 page saved is deleted where it is kept, in
        // sov_records, which takes its console copy with it.
        if ($submission['record_id'] !== null) {
            $store = new \Apps\AiSovereigntyScorecard\Services\RecordStore($this->app);
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
