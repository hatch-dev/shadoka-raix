<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Admin;

use Apps\RaixDirectorScorecardPublic\Services\Synthetic\Archetypes;
use Apps\RaixDirectorScorecardPublic\Services\Synthetic\SeedGenerator;
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
 * dsp_seed_runs with its seed and options, so any batch can be identified and
 * removed in full afterwards.
 */
final class SeederController extends AdminController
{
    /** GET /admin/raix-director-scorecard-public/data-seeder */
    public function index(): Response
    {
        if ($denied = $this->guard('dsp.seed')) {
            return $denied;
        }

        /*
         * ?fragment=1 returns the form alone, with no shell around it, for the
         * dialog the nav opens. The reference shows this as a modal; serving
         * the same partial both ways means the dialog and the page cannot show
         * different forms.
         */
        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_seeder-form', [
                'slug'       => $this->app->slug,
                'csrf'       => Csrf::token(),
                'year'       => (int) date('Y'),
                'error'      => '',
                'archetypes' => Archetypes::DEFINITIONS,
            ]))->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view()->response('admin/seeder', [
            'title'      => 'Data seeder · Director Scorecard',
            'year'       => (int) date('Y'),
            'error'      => (string) ($this->request->query['error'] ?? ''),
            'archetypes' => Archetypes::DEFINITIONS,
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

    /** POST /admin/raix-director-scorecard-public/data-seeder */
    public function generate(): Response
    {
        if ($denied = $this->guard('dsp.seed')) {
            return $denied;
        }

        $validator = Validator::make($this->request->body, [
            'count'       => 'required|integer|between:1,500',
            'seed'        => 'required|integer|between:1,2147483647',
            'years_back'  => 'required|integer|between:1,5',
            'board_share' => 'required|numeric|between:0,1',
            'spread'      => 'required|numeric|between:0.1,1.2',
        ]);

        if ($validator->fails()) {
            return $this->redirect('admin/' . $this->app->slug . '/data-seeder?error='
                . urlencode((string) $validator->firstError()));
        }

        $input = $validator->validated();
        $year  = (int) date('Y');

        /*
         * Directors per board, as "min,max". The generator has always taken it;
         * the form did not ask, so every multi-director board used the default.
         */
        $raters = explode(',', (string) ($this->request->input('raters') ?? '3,5'));
        $min    = max(1, min(20, (int) ($raters[0] ?? 3)));
        $max    = max($min, min(20, (int) ($raters[1] ?? $min)));

        /*
         * Archetype weights, 0–100 from the sliders. All zero means the mix
         * describes nothing, which the reference refuses rather than silently
         * falling back — otherwise you are handed a dataset you did not ask for.
         */
        $archetypes = [];
        foreach ((array) ($this->request->input('archetypes') ?? []) as $key => $weight) {
            if (array_key_exists($key, Archetypes::DEFINITIONS)) {
                $archetypes[$key] = max(0.0, min(1.0, ((float) $weight) / 100));
            }
        }

        if ($archetypes !== [] && array_sum($archetypes) <= 0) {
            return $this->redirect('admin/' . $this->app->slug . '/data-seeder?error='
                . urlencode('Give at least one archetype a weight.'));
        }

        $generator = new SeedGenerator($this->app, (int) $input['seed']);

        $result = $generator->generate([
            'count'       => (int) $input['count'],
            'raters'      => ['min' => $min, 'max' => $max],
            'board_share' => (float) $input['board_share'],
            'spread'      => (float) $input['spread'],
            'years'       => range($year - (int) $input['years_back'] + 1, $year),
        ] + (
            // Only when the sliders actually sent something: passing an empty
            // array would override the built-in mix rather than fall back to it.
            $archetypes === [] ? [] : ['archetypes' => $archetypes]
        ), Auth::id());

        $this->audit('dsp.seed.generated', 'seed_run', (string) $result['seed_run_id'], [
            'seed'    => (int) $input['seed'],
            'records' => $result['records'],
            'boards'  => $result['organisations'],
        ]);

        /*
         * To Research data, as the reference does after seeding — that is
         * where the records it just wrote are, and returning to the empty
         * form would leave you to go and find them.
         */
        return $this->redirect(sprintf(
            'admin/%s/research-data?seeded=%d&boards=%d',
            $this->app->slug,
            $result['records'],
            $result['organisations']
        ));
    }

    /** POST /admin/raix-director-scorecard-public/data-seeder/purge */
    public function purge(): Response
    {
        if ($denied = $this->guard('dsp.seed')) {
            return $denied;
        }

        $runId = $this->request->input('seed_run_id');
        $runId = $runId === null || $runId === '' ? null : (int) $runId;

        // Audited before the delete: afterwards there is nothing left to describe.
        $removed = SeedGenerator::purge($this->app, $runId);

        $this->audit('dsp.seed.purged', 'seed_run', $runId === null ? 'all' : (string) $runId, [
            'records_removed' => $removed,
        ]);

        /*
         * Where to land afterwards. The seeder page's own buttons stay on the
         * seeder page — the runs table they just changed is there. The
         * console's Delete row is pressed from the dataset screen and returns
         * to it, as the Default and Wipe rows beside it already do.
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
