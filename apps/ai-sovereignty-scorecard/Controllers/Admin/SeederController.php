<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Controllers\Admin;

use Apps\AiSovereigntyScorecard\Services\Synthetic\Archetypes;
use Apps\AiSovereigntyScorecard\Services\Synthetic\SeedGenerator;
use Platform\Admin\AdminController;
use Platform\Core\Auth;
use Platform\Core\Csrf;
use Platform\Core\Database;
use Platform\Core\Response;
use Platform\Validation\Validator;

/**
 * Generate and remove demo data.
 *
 * Gated behind its own capability, because this is the one admin screen that
 * writes records which look exactly like real ones. Every run is recorded in
 * sov_seed_runs with its seed and its options, so any batch can be identified and
 * removed in full afterwards — and so that a dataset someone is puzzled by can be
 * traced back to the button that made it.
 */
final class SeederController extends AdminController
{
    /** The reference's default demo set: 14 organisations from seed 20260908. */
    private const DEFAULT_COUNT = 14;
    private const DEFAULT_SEED  = 20260908;

    /** GET /admin/ai-sovereignty-scorecard/data-seeder */
    public function index(): Response
    {
        if ($denied = $this->guard('sov.seed')) {
            return $denied;
        }

        /*
         * ?fragment=1 returns the form alone, with no shell around it, for the
         * dialog the nav opens. The reference shows this as a modal; serving the
         * same partial both ways means the dialog and the page cannot show
         * different forms.
         */
        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_seeder-form', [
                'slug'       => $this->app->slug,
                'csrf'       => Csrf::token(),
                'year'       => (int) date('Y'),
                'error'      => '',
                'archetypes' => Archetypes::definitions(),
            ]))->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/seeder', [
            'title'      => 'Data seeder · The AI Sovereignty Scorecard',
            'year'       => (int) date('Y'),
            'error'      => (string) ($this->request->query['error'] ?? ''),
            'archetypes' => Archetypes::definitions(),
            'purged'     => (int) ($this->request->query['purged'] ?? 0),
            'runs'       => Database::select(sprintf(
                'SELECT r.*, u.`name` AS created_by_name
                   FROM `%s` r
              LEFT JOIN `admin_users` u ON u.`id` = r.`created_by`
               ORDER BY r.`id` DESC LIMIT 10',
                $this->table('seed_runs')
            )),
            'synthetic'  => (int) Database::scalar(sprintf(
                'SELECT COUNT(*) FROM `%s` WHERE `is_synthetic` = 1',
                $this->table('submissions')
            )),
            'real'       => (int) Database::scalar(sprintf(
                'SELECT COUNT(*) FROM `%s` WHERE `is_synthetic` = 0',
                $this->table('submissions')
            )),
        ]);
    }

    /** POST /admin/ai-sovereignty-scorecard/data-seeder */
    public function generate(): Response
    {
        if ($denied = $this->guard('sov.seed')) {
            return $denied;
        }

        $validator = Validator::make($this->request->body, [
            'count'       => 'required|integer|between:1,500',
            'seed'        => 'required|integer|between:1,2147483647',
            'years_back'  => 'required|integer|between:1,5',
            'multi_share' => 'required|numeric|between:0,1',
            'spread'      => 'required|numeric|between:0.1,1.2',
        ]);

        if ($validator->fails()) {
            return $this->redirect('admin/' . $this->app->slug . '/data-seeder?error='
                . urlencode((string) $validator->firstError()));
        }

        $input = $validator->validated();
        $year  = (int) date('Y');

        /* Respondents per organisation, as "min,max" — the reference's control. */
        $respondents = explode(',', (string) ($this->request->input('respondents') ?? '3,5'));
        $min         = max(1, min(20, (int) ($respondents[0] ?? 3)));
        $max         = max($min, min(20, (int) ($respondents[1] ?? $min)));

        /*
         * Archetype weights, 0–100 from the sliders. All zero means the mix
         * describes nothing, which the reference refuses rather than silently
         * falling back — otherwise you are handed a dataset you did not ask for.
         */
        $definitions = Archetypes::definitions();
        $archetypes  = [];

        foreach ((array) ($this->request->input('archetypes') ?? []) as $key => $weight) {
            if (array_key_exists((string) $key, $definitions)) {
                $archetypes[(string) $key] = max(0.0, min(1.0, ((float) $weight) / 100));
            }
        }

        if ($archetypes !== [] && array_sum($archetypes) <= 0) {
            return $this->redirect('admin/' . $this->app->slug . '/data-seeder?error='
                . urlencode('Give at least one archetype a weight.'));
        }

        $result = (new SeedGenerator($this->app, (int) $input['seed']))->generate([
            'count'       => (int) $input['count'],
            'respondents' => ['min' => $min, 'max' => $max],
            'multi_share' => (float) $input['multi_share'],
            'spread'      => (float) $input['spread'],
            'years'       => range($year - (int) $input['years_back'] + 1, $year),
        ] + (
            // Only when the sliders actually sent something: passing an empty
            // array would override the built-in mix rather than fall back to it.
            $archetypes === [] ? [] : ['archetypes' => $archetypes]
        ), Auth::id());

        $this->audit('sov.seed.generated', 'seed_run', (string) $result['seed_run_id'], [
            'seed'          => (int) $input['seed'],
            'records'       => $result['records'],
            'organisations' => $result['organisations'],
        ]);

        /*
         * To Research data, as the reference does after seeding — that is where
         * the records it just wrote are, and returning to the empty form would
         * leave you to go and find them.
         */
        return $this->redirect(sprintf(
            'admin/%s/research-data?seeded=%d&synthetic=1',
            $this->app->slug,
            $result['records']
        ));
    }

    /**
     * POST /admin/ai-sovereignty-scorecard/data-seeder/default
     *
     * The reference's console had a "Restore the small default demo set" row,
     * which called applySeed({count: 14, seed: 20260908}) — a fixed seed, so the
     * same fourteen organisations every time. Kept, because it is the fastest way
     * to get a screen with something believable on it.
     */
    public function restoreDefault(): Response
    {
        if ($denied = $this->guard('sov.seed')) {
            return $denied;
        }

        // The reference's applySeed() replaced the synthetic records rather than
        // adding to them (`[...real, ...records]`). Same here, so pressing it
        // twice leaves fourteen organisations and not twenty-eight.
        SeedGenerator::purge($this->app);

        $result = (new SeedGenerator($this->app, self::DEFAULT_SEED))
            ->generate(['count' => self::DEFAULT_COUNT], Auth::id());

        $this->audit('sov.seed.generated', 'seed_run', (string) $result['seed_run_id'], [
            'seed'          => self::DEFAULT_SEED,
            'records'       => $result['records'],
            'organisations' => $result['organisations'],
            'default_set'   => true,
        ]);

        return $this->redirect(sprintf(
            'admin/%s/research-data?seeded=%d&synthetic=1',
            $this->app->slug,
            $result['records']
        ));
    }

    /** POST /admin/ai-sovereignty-scorecard/data-seeder/purge */
    public function purge(): Response
    {
        if ($denied = $this->guard('sov.seed')) {
            return $denied;
        }

        $runId = $this->request->input('seed_run_id');
        $runId = $runId === null || $runId === '' ? null : (int) $runId;

        $removed = SeedGenerator::purge($this->app, $runId);

        $this->audit('sov.seed.purged', 'seed_run', $runId === null ? 'all' : (string) $runId, [
            'records_removed' => $removed,
        ]);

        /*
         * Where to land afterwards. The seeder page's own buttons stay on the
         * seeder page — the runs table they just changed is there. The console's
         * Delete row is pressed from the dataset screen and returns to it.
         *
         * An allow-list rather than the posted value: a redirect target taken
         * from a form field is an open redirect, whoever posted it.
         */
        $return = $this->request->input('return') === 'research-data'
            ? 'research-data'
            : 'data-seeder';

        return $this->redirect('admin/' . $this->app->slug . '/' . $return . '?purged=' . $removed);
    }
}
