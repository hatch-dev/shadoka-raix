<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Controllers\Admin;

use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Apps\HumanEdgeScorecard\Services\Synthetic\SeedGenerator;
use Platform\Admin\AdminController;
use Platform\Core\Csrf;
use Platform\Core\Database;
use Platform\Core\Response;

/**
 * The Data seeder — the page's seederForm(), runSeeder() and applySeed(),
 * plus the console's Default and Delete rows (adminAct('seed'|'syn')).
 *
 * Every write REPLACES the synthetic set, as the page's applySeed() does, and
 * never touches a real record.
 */
final class SeederController extends AdminController
{
    /** GET /admin/human-edge-scorecard/data-seeder — the form, as a dialog or a page. */
    public function index(): Response
    {
        if ($denied = $this->guard('he.seed')) {
            return $denied;
        }

        $generator = new SeedGenerator($this->app);
        $data      = [
            'slug'       => $this->app->slug,
            'csrf'       => Csrf::token(),
            'error'      => (string) ($this->request->query['error'] ?? ''),
            'archetypes' => $generator->archetypes(),
            // The page's default seed is Math.floor(Math.random()*1e6).
            'seed'       => random_int(0, 999999),
        ];

        if (($this->request->query['fragment'] ?? '') === '1') {
            return Response::make($this->view()->render('admin/_seeder-form', $data))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        (new RecordMirror($this->app))->backfill();

        return $this->view()->response('admin/seeder', $data + [
            'title' => 'Data seeder · The Human Edge Scorecard',
            'runs'  => Database::select(sprintf(
                'SELECT r.*, u.`name` AS created_by_name
                   FROM `%s` r
              LEFT JOIN `admin_users` u ON u.`id` = r.`created_by`
               ORDER BY r.`id` DESC LIMIT 10',
                $this->table('seed_runs')
            )),
            'synthetic' => (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s` WHERE `is_synthetic` = 1', $this->table('submissions'))),
            'real'      => (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s` WHERE `is_synthetic` = 0', $this->table('submissions'))),
        ]);
    }

    /** POST …/data-seeder — runSeeder(). */
    public function generate(): Response
    {
        if ($denied = $this->guard('he.seed')) {
            return $denied;
        }

        $input     = $this->request->body;
        $generator = new SeedGenerator($this->app);

        $archetypes = [];
        foreach (array_keys($generator->archetypes()) as $key) {
            $archetypes[$key] = max(0, min(100, (int) ($input['archetypes'][$key] ?? 0))) / 100;
        }

        if (array_sum($archetypes) <= 0) {
            return $this->redirect('admin/' . $this->app->slug . '/data-seeder?error=' . urlencode('Give at least one archetype a weight.'));
        }

        [$min, $max] = array_map('intval', explode(',', (string) ($input['raters'] ?? '3,5')) + [1 => 5]);

        $count = (int) ($input['count'] ?? 0);
        $count = $count > 0 ? $count : 30;   // parseInt(…)||30, then clamp(…,1,500)

        $options = [
            'count'      => max(1, min(500, $count)),
            'seed'       => (int) ($input['seed'] ?? 0) ?: 1,
            'raters'     => ['min' => $min, 'max' => $max],
            'boardShare' => (float) ($input['board_share'] ?? .35),
            'spread'     => (float) ($input['spread'] ?? .5),
            'archetypes' => $archetypes,
        ];

        try {
            $result = $generator->generate($options);
        } catch (\RuntimeException $error) {
            return $this->redirect('admin/' . $this->app->slug . '/data-seeder?error=' . urlencode($error->getMessage()));
        }

        $this->audit('he.seed.generated', 'seed_run', (string) $result['run'], [
            'seed' => $options['seed'], 'records' => $result['records'], 'organisations' => $result['orgs'],
        ]);

        // runSeeder() then go('data'), with the generated records on screen.
        return $this->redirect(sprintf('admin/%s/research-data?done=seeded&records=%d&orgs=%d&synthetic=1', $this->app->slug, $result['records'], $result['orgs']));
    }

    /** POST …/data-seeder/default — adminAct('seed'): applySeed({count:14, seed:20260908}). */
    public function restoreDefault(): Response
    {
        if ($denied = $this->guard('he.seed')) {
            return $denied;
        }

        $result = (new SeedGenerator($this->app))->generate(SeedGenerator::DEFAULT_OPTIONS);

        $this->audit('he.seed.generated', 'seed_run', (string) $result['run'], [
            'seed' => SeedGenerator::DEFAULT_OPTIONS['seed'], 'records' => $result['records'], 'organisations' => $result['orgs'], 'default_set' => true,
        ]);

        return $this->redirect('admin/' . $this->app->slug . '/research-data?done=restored&synthetic=1');
    }

    /** POST …/data-seeder/purge — adminAct('syn'): synthetic records only. */
    public function purge(): Response
    {
        if ($denied = $this->guard('he.seed')) {
            return $denied;
        }

        $removed = SeedGenerator::purge($this->app);

        $this->audit('he.seed.purged', 'dataset', 'synthetic', ['records_removed' => $removed]);

        return $this->redirect('admin/' . $this->app->slug . '/research-data?done=purged');
    }
}
