<?php

declare(strict_types=1);

use Apps\AiExecutiveReadinessScorecard\Services\Export\Exports;
use Apps\AiExecutiveReadinessScorecard\Services\RecordMirror;
use Apps\AiExecutiveReadinessScorecard\Services\RecordStore;
use Apps\AiExecutiveReadinessScorecard\Services\Records;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The admin console reads what exr_records holds (Services/RecordMirror):
 * every save, re-save, correction, restriction and deletion reaches the
 * console's tables, a restricted record is in no console screen or export,
 * and a copy whose record is gone does not survive.
 *
 * Shared database: every record this test writes has an id starting "tmir-"
 * and is removed at the end, with its console copy. The demonstration set is
 * not touched.
 */
return static function (Assert $t): void {
    $app    = AppRegistry::get('ai-executive-readiness-scorecard');
    $store  = new RecordStore($app);
    $mirror = new RecordMirror($app);
    $subs   = $app->table('submissions');
    $tag    = 'tmir-' . bin2hex(random_bytes(3)) . '-';
    $mail   = 'mirror-' . bin2hex(random_bytes(3)) . '@raix-tests.org';

    $codes = array_column(Database::select(sprintf(
        "SELECT d.`code` FROM `%s` d WHERE d.`instrument_id` = (SELECT `id` FROM `%s` WHERE `status` = 'published' ORDER BY `published_at` DESC LIMIT 1) ORDER BY d.`position`",
        $app->table('dimensions'),
        $app->table('instruments')
    )), 'code');

    // Two sets of ratings, as the v4 page sends them: every dimension a
    // different mean, and the leaders rated a point lower than the self.
    $responses = ['self' => [], 'leadership' => []];
    foreach ($codes as $d => $code) {
        $responses['self'][$code]       = (object) ['0' => 2 + $d % 4, '1' => 3, '2' => min(5, 3 + $d)];
        $responses['leadership'][$code] = (object) ['0' => 1 + $d % 4, '1' => 2, '2' => min(5, 2 + $d)];
    }

    $record = static function (string $id, array $over = []) use ($tag, $mail, $responses): array {
        return array_replace_recursive([
            'id' => $tag . $id, 'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'year' => (int) gmdate('Y'), 'synthetic' => false,
            'userName' => 'Mirror Tester', 'userEmail' => $mail, 'userPhone' => '',
            'org' => [
                'consent' => ['notice' => 'EXR-PN-2', 'at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'scorecard' => true, 'research' => true, 'terms' => 'https://shadoka.com/terms/'],
                'org' => '', 'name' => 'Mirror Tester', 'email' => $mail, 'phone' => '', 'role' => 'Chief Information Officer',
                'sector' => 'Technology', 'size' => '51–250', 'year' => (int) gmdate('Y'), 'systems' => 12, 'tenure' => null, 'agenda' => 3, 'edu' => 4.5,
            ],
            'instrument' => 'ai-executive-readiness', 'version' => '1.0',
            'responses' => $responses, 'scores' => new stdClass(), 'difference' => new stdClass(),
        ], $over);
    };
    $json  = static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $copy  = static fn (string $id): ?array => Database::selectOne("SELECT * FROM `{$subs}` WHERE `record_id` = ?", [$id]);
    $copies = static fn (string $id): int => (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `record_id` = ?", [$id]);
    $marks = static fn (array $row): array => array_map('intval', array_column(Database::select(
        'SELECT `mark` FROM `' . $app->table('submission_responses') . '` WHERE `submission_id` = ? ORDER BY `scope` = \'leadership\', `item_id`',
        [$row['id']]
    ), 'mark'));
    $exported = static function () use ($app): string {
        Records::flush();
        $exports = new Exports($app);

        return $exports->json($exports->records(false), false);
    };

    try {
        /* ---------- B1 save -> console copy ---------- */

        $one = $record('one');
        $t->same('setup: a real scorecard saved', 200, $store->save($json($one))['status']);
        $row = $copy($one['id']);
        $t->true('a saved scorecard has a console copy', $row !== null);
        $t->same('... exactly one', 1, $copies($one['id']));
        $t->same('... real, complete', [0, 'complete'], [(int) $row['is_synthetic'], $row['status']]);
        $t->same('... with the respondent and profile', ['Mirror Tester', $mail, '', 'Chief Information Officer', 'Technology', '51–250', 12, 3, '4.5'],
            [$row['respondent_name'], $row['respondent_email'], $row['respondent_phone'], $row['role'], $row['sector'], $row['organisation_size'],
             (int) $row['systems_inventory'], (int) $row['agenda_meetings'], (string) (float) $row['education_hours']]);
        $t->same('no organisation name means no organisation (requirement 9)', [null, ''], [$row['organisation_id'], $row['organisation_name']]);
        $t->true('the consent time is carried over', $row['consent_agreed_at'] !== null);

        $all = [];
        foreach (['self', 'leadership'] as $scope) {
            foreach ($responses[$scope] as $set) {
                foreach ((array) $set as $m) {
                    $all[$scope][] = $m;
                }
            }
        }
        $t->same('every rating is carried, both scopes, item by item', count($codes) * 3 * 2, count($marks($row)));
        $t->same('... each under its own scope', [count($codes) * 3, count($codes) * 3], array_map('intval', array_column(Database::select(
            'SELECT `scope`, COUNT(*) AS n FROM `' . $app->table('submission_responses') . '` WHERE `submission_id` = ? GROUP BY `scope` ORDER BY `scope` = \'leadership\'',
            [$row['id']]
        ), 'n')));
        $t->same('... and they are the record\'s ratings', array_merge($all['self'], $all['leadership']), $marks($row));
        $selfMean = array_sum($all['self']) / count($all['self']);
        $leadMean = array_sum($all['leadership']) / count($all['leadership']);
        $t->same('scored by the console\'s Scorer: both profiles\' means', [round($selfMean, 3), round($leadMean, 3)],
            [round((float) $row['self_mean'], 3), round((float) $row['leadership_mean'], 3)]);
        $t->same('... and the difference, self minus leaders', round($selfMean - $leadMean, 3), round((float) $row['difference_mean'], 3));
        $t->true('... each profile with a level and a floor', $row['self_level'] !== null && $row['self_floor_dimension_id'] !== null
            && $row['leadership_level'] !== null && $row['leadership_floor_dimension_id'] !== null);
        $t->true('the console\'s JSON export includes it', str_contains($exported(), $mail));

        /* ---------- re-save (Review answers) ---------- */

        $first = $responses['self'][$codes[0]];
        $changed = $one;
        $changed['responses']['self'][$codes[0]] = (object) ['0' => 5, '1' => $first->{'1'}, '2' => $first->{'2'}];
        $store->save($json($changed));
        $t->same('a re-save leaves one console copy', 1, $copies($one['id']));
        $t->same('... carrying the changed answer', 5, $marks($copy($one['id']))[0]);

        /* ---------- organisation named ---------- */

        $named = $record('named', ['org' => ['org' => 'Mirror Probe Holdings']]);
        $store->save($json($named));
        $t->true('a named organisation is linked', $copy($named['id'])['organisation_id'] !== null);

        /* ---------- C4 ---------- */

        $store->correctIdentity($one['id'], ['name' => 'Corrected Mirror']);
        $t->same('a correction reaches the console', 'Corrected Mirror', $copy($one['id'])['respondent_name']);

        $store->setRestricted($one['id'], true);
        $t->same('a restricted record has no console copy', 0, $copies($one['id']));
        $t->true('... and is in no console export', !str_contains($exported(), 'Corrected Mirror'));
        $store->setRestricted($one['id'], false);
        $t->same('lifting the restriction brings it back', 1, $copies($one['id']));

        $store->deleteReal($one['id']);
        $t->same('a deleted record loses its console copy', 0, $copies($one['id']));

        /* ---------- out-of-band changes are repaired ---------- */

        Database::statement('DELETE FROM `' . $app->table('records') . '` WHERE `id` = ?', [$named['id']]);
        $t->same('setup: a record removed behind the store\'s back still has its copy', 1, $copies($named['id']));
        $mirror->prune();
        $t->same('prune() drops a copy whose record is gone', 0, $copies($named['id']));

        $two = $record('two');
        $store->save($json($two));
        Database::statement("DELETE FROM `{$subs}` WHERE `record_id` = ?", [$two['id']]);
        $t->true('backfill() restores a missing copy', $mirror->backfill() >= 1 && $copies($two['id']) === 1);
        $t->same('... and does not duplicate one on a second run', 1, $mirror->backfill() >= 0 ? $copies($two['id']) : -1);

        /* ---------- the previous edition and the demo set ---------- */

        $t->same('every console demo row mirrors a demo record',
            0, (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` s LEFT JOIN `" . $app->table('records') . "` r ON r.`id` = s.`record_id` WHERE s.`is_synthetic` = 1 AND (r.`id` IS NULL OR r.`synthetic` = 0)"));
    } finally {
        Database::statement('DELETE FROM `' . $app->table('records') . '` WHERE `id` LIKE ?', [$tag . '%']);
        $mirror->prune();
    }
};
