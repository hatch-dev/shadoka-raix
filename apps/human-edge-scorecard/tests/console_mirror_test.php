<?php

declare(strict_types=1);

use Apps\HumanEdgeScorecard\Services\Instrument;
use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Apps\HumanEdgeScorecard\Services\Records;
use Apps\HumanEdgeScorecard\Services\RecordStore;
use Apps\HumanEdgeScorecard\Services\Stats;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * he_records → the console's he_submissions family (Services/RecordMirror).
 *
 * Every change the page or Requests makes in he_records must show in the
 * console, and nothing he_records no longer holds may stay there: a save is
 * copied with its marks and the console's own scores, a restriction removes
 * the copy and lifting it brings it back, a correction rewrites it, a deletion
 * removes it and the organization name it brought, and anything changed behind
 * the store's back is repaired by backfill().
 *
 * Shared database: every record this test writes has an id starting "thm-" and
 * is removed at the end; the synthetic set is saved first and put back.
 */
return static function (Assert $t): void {
    $app    = AppRegistry::get('human-edge-scorecard');
    $store  = new RecordStore($app);
    $mirror = new RecordMirror($app);
    $stats  = new Stats(new Instrument($app));
    $tag    = 'thm-' . bin2hex(random_bytes(3)) . '-';
    $orgTag = 'Mirror Probe ' . bin2hex(random_bytes(3));
    $subs   = $app->table('submissions');
    $recs   = $app->table('records');

    $marks = ['JDG' => [3, 4, 4], 'ADP' => [3, 3, 3], 'EMP' => [4, 4, 4], 'CON' => [3, 3, 3], 'IMG' => [3, 3, 3], 'AUT' => [4, 4, 4]];
    $record = static function (string $id, array $over = []) use ($tag, $orgTag, $marks): array {
        $r = [
            'id' => $tag . $id, 'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'year' => (int) gmdate('Y'),
            'org' => [
                'consent' => ['notice' => 'HE-PN-1', 'at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'scorecard' => true, 'research' => true, 'terms' => 'https://shadoka.com/terms/'],
                'org' => $orgTag, 'name' => 'Mirror Person', 'email' => 'mirror@raix-tests.org', 'phone' => '+353 1 234 5678',
                'role' => 'Manager', 'sector' => 'Not specified', 'size' => '51–250', 'year' => (int) gmdate('Y'), 'aiuse' => 7.5, 'tenure' => '1–3 years', 'edu' => 12,
            ],
            'dims' => ['JDG' => 3.67, 'ADP' => 3, 'EMP' => 4, 'CON' => 3, 'IMG' => 3, 'AUT' => 4], 'mean' => 3.45, 'level' => 'Consistent',
            'responses' => array_map(static fn (array $m): object => (object) $m, $marks),
            'artifacts' => array_map(static fn (): object => new stdClass(), $marks),
            'synthetic' => false, 'userName' => 'Mirror Person', 'userEmail' => 'mirror@raix-tests.org', 'userPhone' => '+353 1 234 5678',
        ];

        return array_replace_recursive($r, $over);
    };
    $json = static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $copy = static fn (string $id): ?array => Database::selectOne("SELECT * FROM `{$subs}` WHERE `record_id` = ?", [$id]);
    $find = static function (string $id) use ($app): ?array {
        Records::flush();
        foreach ((new Records($app))->all() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    };

    $savedSynthetic = Database::select("SELECT * FROM `{$recs}` WHERE `synthetic` = 1");
    $seedRun        = Database::scalar("SELECT MAX(`seed_run_id`) FROM `{$subs}` WHERE `is_synthetic` = 1");

    try {
        /* ---------- a save is copied ---------- */

        $one = $record('one');
        $t->same('B1 accepts the record', 200, $store->save($json($one))['status']);
        $row = $copy($one['id']);
        $t->true('a console copy is written', $row !== null);
        $t->same('identity copied', ['Mirror Person', 'mirror@raix-tests.org', '+353 1 234 5678', $orgTag], [$row['respondent_name'], $row['respondent_email'], $row['respondent_phone'], $row['organisation_name']]);
        $t->same('profile copied (AI hours, education, tenure, role)', ['7.5', '12.0', '1–3 years', 'Manager'], [$row['ai_hours_per_week'], $row['education_hours'], $row['tenure'], $row['role']]);
        $t->same('"Not specified" is kept as NULL, not as a sector', null, $row['sector']);
        $t->same('consent copied (notice, research)', ['HE-PN-1', 1], [$row['consent_notice'], (int) $row['consent_research']]);
        $t->true('the organization is resolved to a shared row', $row['organisation_id'] !== null);
        $t->same('all eighteen marks copied', 18, (int) Database::scalar('SELECT COUNT(*) FROM `' . $app->table('submission_responses') . '` WHERE `submission_id` = ?', [$row['id']]));
        $t->same('six skill scores derived', 6, (int) Database::scalar('SELECT COUNT(*) FROM `' . $app->table('submission_scores') . '` WHERE `submission_id` = ?', [$row['id']]));
        $t->same('the console\'s own overall score', '3.45', (string) $row['mean_score']);
        $t->same('the console\'s own level (Consistent = 4)', 4, (int) $row['level_value']);

        $r = $find($one['id']);
        $t->true('Records gives it back in the page\'s shape', $r !== null);
        $t->same('… with the page\'s dims, mean and level', [array_map('floatval', $one['dims']), 3.45, 'Consistent'], [array_map('floatval', $r['dims']), $r['mean'], $r['level']]);
        $t->same('… its marks keyed by question', [3, 4, 4], array_values($r['responses']['JDG']));
        $t->same('… and the sector the page wrote', 'Not specified', $r['org']['sector']);

        /* ---------- a score the browser asserts is not believed ---------- */

        $lie = $record('lie', ['mean' => 5, 'level' => 'Habitual', 'dims' => ['JDG' => 5]]);
        $store->save($json($lie));
        $t->same('a browser-asserted score is not copied: the marks decide', '3.45', (string) $copy($lie['id'])['mean_score']);

        /* ---------- a re-save replaces the copy ---------- */

        $again = $record('one', ['responses' => ['JDG' => (object) [1, 1, 1]]]);
        $store->save($json($again));
        $t->same('a re-save rewrites the copy (one copy, new score)', [1, '1.00'], [
            (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `record_id` = ?", [$one['id']]),
            (string) Database::scalar('SELECT s.`score` FROM `' . $app->table('submission_scores') . "` s JOIN `{$subs}` x ON x.`id` = s.`submission_id` JOIN `" . $app->table('dimensions') . "` d ON d.`id` = s.`dimension_id` WHERE x.`record_id` = ? AND d.`code` = 'JDG'", [$one['id']]),
        ]);

        /* ---------- restriction, correction, deletion ---------- */

        $store->setRestricted($one['id'], true);
        $t->same('a restricted record has no console copy', null, $copy($one['id']));
        $t->same('… and is in no console screen', null, $find($one['id']));
        $store->setRestricted($one['id'], false);
        $t->true('lifting the restriction brings it back', $copy($one['id']) !== null);

        $store->correctIdentity($one['id'], ['name' => 'Corrected Person']);
        $t->same('a correction rewrites the copy', 'Corrected Person', $copy($one['id'])['respondent_name']);

        $orgId = (int) $copy($one['id'])['organisation_id'];
        $store->deleteReal($one['id']);
        $store->deleteReal($lie['id']);
        $t->same('a deleted record loses its copy', null, $copy($one['id']));
        $t->same('… and the organization name it brought is gone from the shared table', 0, (int) Database::scalar('SELECT COUNT(*) FROM `organisations` WHERE `id` = ?', [$orgId]));

        /* ---------- no organization name, no organization ---------- */

        $anon = $record('anon', ['org' => ['org' => '']]);
        $store->save($json($anon));
        $t->same('a record with no organization name has no organization', [null, null], [$copy($anon['id'])['organisation_id'], $copy($anon['id'])['organisation_name']]);

        /* ---------- changes behind the store's back are repaired ---------- */

        Database::statement("INSERT INTO `{$recs}` (`id`, `synthetic`, `restricted`, `email`, `completed_at`, `payload`, `received_at`) VALUES (?, 0, 0, ?, ?, ?, ?)",
            [$tag . 'raw', 'mirror@raix-tests.org', gmdate('Y-m-d H:i:s'), $json($record('raw')), gmdate('Y-m-d H:i:s')]);
        $t->same('backfill() copies a record written directly', 1, $mirror->backfill());
        Database::statement("DELETE FROM `{$recs}` WHERE `id` = ?", [$tag . 'raw']);
        $mirror->prune();
        $t->same('prune() drops a copy whose record is gone', null, $copy($tag . 'raw'));

        /* ---------- the demonstration set follows B3 / B4 ---------- */

        $store->deleteSynthetic();
        $t->same('B4: no synthetic copies remain', 0, (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `is_synthetic` = 1"));
        $t->true('B4: the real copies are untouched', $copy($anon['id']) !== null);
    } finally {
        Database::statement("DELETE FROM `{$recs}` WHERE `id` LIKE ?", [$tag . '%']);
        Database::statement("DELETE FROM `{$recs}` WHERE `synthetic` = 1");
        foreach ($savedSynthetic as $r) {
            Database::insert($recs, $r);
        }
        $mirror->syncSynthetic($seedRun === null ? null : (int) $seedRun);
        $mirror->prune();
        Records::flush();
    }

    $t->same('cleaned up: no test copies left', 0, (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `record_id` LIKE ?", [$tag . '%']));
    $t->same('cleaned up: the demonstration set is back', count($savedSynthetic), (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `is_synthetic` = 1"));
    unset($stats);
};
