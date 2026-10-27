<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecardPublic\Services\Export\DatasetExporter;
use Apps\RaixDirectorScorecardPublic\Services\RecordMirror;
use Apps\RaixDirectorScorecardPublic\Services\RecordStore;
use Platform\Core\AppRegistry;
use Platform\Core\Assert;
use Platform\Core\Database;

/**
 * The admin console reads what dsp_records holds (Services/RecordMirror):
 * every save, re-save, correction, restriction and deletion reaches the
 * console's tables, a restricted record is in no console screen or export,
 * and a copy whose record is gone does not survive.
 *
 * Shared database: every record this test writes has an id starting "tmir-"
 * and is removed at the end, with its console copy. The demonstration set is
 * not touched.
 */
return static function (Assert $t): void {
    $app    = AppRegistry::get('raix-director-scorecard-public');
    $store  = new RecordStore($app);
    $mirror = new RecordMirror($app);
    $subs   = $app->table('submissions');
    $tag    = 'tmir-' . bin2hex(random_bytes(3)) . '-';
    $mail   = 'mirror-' . bin2hex(random_bytes(3)) . '@raix-tests.org';

    $codes = array_column(Database::select(sprintf(
        "SELECT c.`code` FROM `%s` c WHERE c.`instrument_id` = (SELECT `id` FROM `%s` WHERE `status` = 'published' ORDER BY `published_at` DESC LIMIT 1) ORDER BY c.`position`",
        $app->table('competencies'),
        $app->table('instruments')
    )), 'code');

    // Marks on the page's 1–4 scale, a different mix per competency.
    $responses = [];
    foreach ($codes as $c => $code) {
        $responses[$code] = (object) ['0' => 1 + $c % 4, '1' => 2, '2' => 3, '3' => min(4, 1 + $c), '4' => 3];
    }

    $record = static function (string $id, array $over = []) use ($tag, $mail, $responses, $codes): array {
        return array_replace_recursive([
            'id' => $tag . $id, 'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'year' => (int) gmdate('Y'),
            'org' => [
                'consent' => ['notice' => 'SC-PN-4', 'at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'scorecard' => true, 'research' => true, 'terms' => 'https://shadoka.com/terms/'],
                'org' => '', 'name' => 'Mirror Director', 'email' => $mail, 'phone' => '0871234567', 'role' => 'Board Chair',
                'sector' => 'Technology', 'size' => '51–250', 'year' => (int) gmdate('Y'), 'systems' => 12, 'own' => [$codes[1]],
                'tenure' => null, 'seats' => 9, 'agenda' => 3, 'edu' => 4.5,
            ],
            'dims' => new stdClass(), 'floor' => null, 'floorCode' => null, 'mean' => null, 'level' => null,
            'responses' => $responses,
            'artifacts' => [$codes[0] => ['2' => 'Audit committee minutes, item 4']],
            'synthetic' => false,
            'consequences' => ['register' => true, 'agenda' => false, 'gate' => true],
            'userName' => 'Mirror Director', 'userEmail' => $mail, 'userPhone' => '0871234567',
        ], $over);
    };
    $json   = static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $copy   = static fn (string $id): ?array => Database::selectOne("SELECT * FROM `{$subs}` WHERE `record_id` = ?", [$id]);
    $copies = static fn (string $id): int => (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` WHERE `record_id` = ?", [$id]);
    $marks  = static fn (array $row): array => array_map('intval', array_column(Database::select(
        'SELECT r.`mark` FROM `' . $app->table('submission_responses') . '` r JOIN `' . $app->table('items') . '` i ON i.`id` = r.`item_id`
           JOIN `' . $app->table('competencies') . '` c ON c.`id` = i.`competency_id`
          WHERE r.`submission_id` = ? ORDER BY c.`position`, i.`position`',
        [$row['id']]
    ), 'mark'));
    $exported = static fn (): string => (new DatasetExporter($app, false))->json();

    try {
        /* ---------- B1 save -> console copy ---------- */

        $one = $record('one');
        $t->same('setup: a real scorecard saved', 200, $store->save($json($one))['status']);
        $row = $copy($one['id']);
        $t->true('a saved scorecard has a console copy', $row !== null);
        $t->same('... exactly one', 1, $copies($one['id']));
        $t->same('... real, complete', [0, 'complete'], [(int) $row['is_synthetic'], $row['status']]);
        $t->same('... with the director and profile', ['Mirror Director', $mail, '0871234567', 'Board Chair', 'Technology', '51–250', 12, 9, 3, '4.5'],
            [$row['director_name'], $row['director_email'], $row['director_phone'], $row['seat_role'], $row['sector'], $row['company_size'],
             (int) $row['systems_seen'], (int) $row['board_seats'], (int) $row['agenda_meetings'], (string) (float) $row['education_hours']]);
        $t->same('... and the committee remit', [$codes[1]], json_decode((string) $row['remit'], true));
        $t->same('no board name means no organisation', [null, null], [$row['organisation_id'], $row['board_name']]);
        $t->true('the consent time is carried over', $row['consent_agreed_at'] !== null);

        $all = [];
        foreach ($responses as $set) {
            foreach ((array) $set as $m) {
                $all[] = $m;
            }
        }
        $t->same('every mark is carried, item by item', count($codes) * 5, count($marks($row)));
        $t->same('... and they are the record\'s marks', $all, $marks($row));
        $t->same('scored by the console\'s Scorer: mean of the answers', round(array_sum($all) / count($all), 2), round((float) $row['mean_score'], 2));
        $t->true('... with a level and a floor', $row['level_value'] !== null && $row['floor_competency_id'] !== null);
        $t->same('the evidence note lands on its mark', ['Audit committee minutes, item 4'], array_column(Database::select(
            'SELECT `evidence_note` FROM `' . $app->table('submission_responses') . '` WHERE `submission_id` = ? AND `evidence_note` IS NOT NULL',
            [$row['id']]
        ), 'evidence_note'));
        $commitments = [];
        foreach (Database::select('SELECT `commitment_key`, `is_checked` FROM `' . $app->table('submission_commitments') . '` WHERE `submission_id` = ?', [$row['id']]) as $c) {
            $commitments[$c['commitment_key']] = (int) $c['is_checked'];
        }
        ksort($commitments);
        $t->same('the three commitments are carried', ['agenda' => 0, 'gate' => 1, 'register' => 1], $commitments);
        $t->true('the console\'s JSON export includes it', str_contains($exported(), $mail));

        /* ---------- re-save (Review answers) ---------- */

        $changed = $one;
        $changed['responses'][$codes[0]] = (object) array_replace((array) $responses[$codes[0]], ['0' => 4]);
        $store->save($json($changed));
        $t->same('a re-save leaves one console copy', 1, $copies($one['id']));
        $t->same('... carrying the changed answer', 4, $marks($copy($one['id']))[0]);

        /* ---------- board named ---------- */

        $named = $record('named', ['org' => ['org' => 'Mirror Probe Board']]);
        $store->save($json($named));
        $t->true('a named board is linked', $copy($named['id'])['organisation_id'] !== null);

        /* ---------- C4 ---------- */

        $store->correctIdentity($one['id'], ['name' => 'Corrected Mirror']);
        $t->same('a correction reaches the console', 'Corrected Mirror', $copy($one['id'])['director_name']);

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

        /* ---------- the demo set ---------- */

        $t->same('every console demo row mirrors a demo record',
            0, (int) Database::scalar("SELECT COUNT(*) FROM `{$subs}` s LEFT JOIN `" . $app->table('records') . "` r ON r.`id` = s.`record_id` WHERE s.`is_synthetic` = 1 AND (r.`id` IS NULL OR r.`synthetic` = 0)"));
    } finally {
        Database::statement('DELETE FROM `' . $app->table('records') . '` WHERE `id` LIKE ?', [$tag . '%']);
        $mirror->prune();
    }
};
