<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Services;

use Apps\EnterpriseAiScorecard\Services\Export\DatasetExporter;
use Apps\EnterpriseAiScorecard\Services\Scoring\Scorer;
use Platform\Assessment\OrganisationResolver;
use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * es_records, mirrored into the console's es_submissions family.
 *
 * Since the v32 page, es_records is where scorecards are kept (directive v32,
 * Part B): the page writes it, Requests correct, restrict and delete in it,
 * and the 24-month job deletes from it. The admin console's screens
 * (Analytics, Research data, exports, the organisation view) read the
 * relational es_submissions / submission_responses / submission_scores
 * tables. This class keeps the second in step with the first, so every
 * console screen shows exactly what es_records holds — and nothing it no
 * longer holds:
 *
 *   - one es_submissions row per record, tagged with `record_id`;
 *   - a RESTRICTED real record has no mirror (C4: excluded from every export
 *     and view, as from B2);
 *   - a deleted or expired record loses its mirror.
 *
 * Marks come from the record's own `responses` and are scored by the console's
 * Scorer, so a console figure is always the scoring rules' and never a value
 * the browser asserted.
 *
 * The other direction exists for demonstration data only: the console's Data
 * seeder writes es_submissions rows, and adoptSeedRun() gives each one a
 * synthetic record, so the page and the console show one demo set.
 *
 * Rows with no record_id are the previous edition's own submissions. Nothing
 * here touches them.
 */
final class RecordMirror
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @var array{instrument:int, items:array<string, list<int>>}|null */
    private ?array $instrument = null;

    public function __construct(private readonly AppManifest $app)
    {
    }

    private function submissions(): string
    {
        return $this->app->table('submissions');
    }

    private function records(): string
    {
        return $this->app->table('records');
    }

    /* ------------------------------------------------------------------ */
    /* es_records -> console                                              */
    /* ------------------------------------------------------------------ */

    /** Bring one record's mirror in step: written, rewritten, or removed. */
    public function sync(string $id): void
    {
        $row = Database::selectOne(
            sprintf('SELECT `id`, `synthetic`, `restricted`, `payload` FROM `%s` WHERE `id` = ?', $this->records()),
            [$id]
        );

        if ($row === null || ((int) $row['synthetic'] === 0 && (int) $row['restricted'] === 1)) {
            $this->remove($id);

            return;
        }

        $record = RecordStore::decodeObject((string) $row['payload']);

        if ($record === null) {
            $this->remove($id);

            return;
        }

        Database::transaction(function () use ($id, $record, $row): void {
            $this->remove($id);
            $this->write($id, $record, (int) $row['synthetic'] === 1);
        });
    }

    /** Remove one record's mirror. */
    public function remove(string $id): void
    {
        $this->deleteCopies('s.`record_id` = ?', [$id]);
    }

    /**
     * Delete console copies, and then every organisation they named that no
     * app's submissions reference any more. An organisation name is something
     * a person typed: once the record it came from is deleted (on request, or
     * after 24 months), the name must not outlive it in the shared table.
     *
     * @param list<mixed> $params
     * @return int copies deleted
     */
    private function deleteCopies(string $where, array $params = []): int
    {
        $organisations = array_map('intval', array_column(Database::select(
            sprintf('SELECT DISTINCT s.`organisation_id` FROM `%s` s WHERE s.`organisation_id` IS NOT NULL AND %s', $this->submissions(), $where),
            $params
        ), 'organisation_id'));

        $deleted = Database::statement(sprintf('DELETE s FROM `%s` s WHERE %s', $this->submissions(), $where), $params);

        if ($organisations !== []) {
            self::dropUnreferenced($organisations);
        }

        return $deleted;
    }

    /**
     * Of these organisations, delete the ones no app's submissions reference.
     * `organisations` is shared, and every app's {slug}_submissions can point
     * at it, so every one is checked — the same rule the seeder's purge uses.
     *
     * @param list<int> $ids
     */
    private static function dropUnreferenced(array $ids): void
    {
        $inUse = [];
        foreach (\Platform\Core\AppRegistry::all() as $app) {
            $table = $app->table('submissions');

            if (Database::hasTable($table)) {
                $inUse[] = sprintf('SELECT 1 FROM `%s` x WHERE x.`organisation_id` = o.`id`', $table);
            }
        }

        $unused = implode(' AND ', array_map(static fn (string $q): string => 'NOT EXISTS (' . $q . ')', $inUse));

        Database::statement(
            sprintf(
                'DELETE o FROM `organisations` o WHERE o.`id` IN (%s)%s',
                implode(',', array_fill(0, count($ids), '?')),
                $unused === '' ? '' : ' AND ' . $unused
            ),
            $ids
        );
    }

    /**
     * Mirror the whole synthetic set again, after the page replaced it (B3) or
     * deleted it (B4). Every mirrored synthetic row goes, then each synthetic
     * record is written back — so a demo row the page no longer holds is never
     * left behind in the console.
     */
    public function syncSynthetic(): void
    {
        $this->deleteCopies('s.`record_id` IS NOT NULL AND s.`is_synthetic` = 1');

        foreach (Database::select(sprintf('SELECT `id`, `payload` FROM `%s` WHERE `synthetic` = 1', $this->records())) as $row) {
            $record = RecordStore::decodeObject((string) $row['payload']);

            if ($record !== null) {
                $this->write((string) $row['id'], $record, true);
            }
        }

        $this->pruneOrganisations();
    }

    /**
     * Drop every mirror whose record is gone (deleted, expired) or now
     * restricted. Cheap enough to run after any bulk change.
     */
    public function prune(): int
    {
        return $this->deleteCopies(sprintf(
            's.`record_id` IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM `%s` r WHERE r.`id` = s.`record_id` AND (r.`synthetic` = 1 OR r.`restricted` = 0))',
            $this->records()
        ));
    }

    /**
     * Mirror every record that has none yet. Used once to bring existing
     * records in, and safe to re-run: it never rewrites a mirror that exists.
     *
     * @return int how many were written
     */
    public function backfill(): int
    {
        $rows = Database::select(sprintf(
            'SELECT r.`id` FROM `%1$s` r
          LEFT JOIN `%2$s` s ON s.`record_id` = r.`id`
              WHERE s.`id` IS NULL AND (r.`synthetic` = 1 OR r.`restricted` = 0)',
            $this->records(),
            $this->submissions()
        ));

        foreach ($rows as $row) {
            $this->sync((string) $row['id']);
        }

        $this->prune();

        return count($rows);
    }

    /** One record as a submission row, its marks, and its scores. */
    private function write(string $id, object $record, bool $synthetic): void
    {
        $instrument = $this->instrument();
        $org        = isset($record->org) && is_object($record->org) ? $record->org : new \stdClass();
        $consent    = isset($org->consent) && is_object($org->consent) ? $org->consent : null;

        $text = static function ($value, int $max, bool $nullable = false): ?string {
            if ($value === null || (!is_string($value) && !is_int($value) && !is_float($value))) {
                return $nullable ? null : '';
            }

            $value = trim((string) $value);

            return $nullable && $value === '' ? null : mb_substr($value, 0, $max);
        };
        $whole = static fn ($value, int $max): ?int => is_numeric($value) && (float) $value >= 0 ? min($max, (int) round((float) $value)) : null;

        $completed = self::localTime($record->timestamp ?? null) ?? date('Y-m-d H:i:s');
        $year      = $whole($org->year ?? $record->year ?? null, 65535) ?: (int) substr($completed, 0, 4);
        $orgName   = $text($org->org ?? null, 191);
        $sector    = $text($org->sector ?? null, 100, true);
        $size      = $text($org->size ?? null, 50);

        $submissionId = Database::insert($this->submissions(), [
            'public_token'      => bin2hex(random_bytes(16)),
            'record_id'         => $id,
            'instrument_id'     => $instrument['instrument'],
            // No organisation name means no organisation (requirement 9): such a
            // record is never counted as one.
            'organisation_id'   => OrganisationResolver::resolve($orgName, $sector, $size, $synthetic),

            'respondent_name'   => $text($record->userName ?? $org->name ?? null, 120),
            'respondent_email'  => $text($record->userEmail ?? $org->email ?? null, 254),
            'respondent_phone'  => $text($record->userPhone ?? $org->phone ?? null, 40),
            'organisation_name' => $orgName,
            'organisation_size' => $size,

            'role'              => $text($org->role ?? null, 100, true),
            'tenure'            => $text($org->tenure ?? null, 50, true),
            'sector'            => $sector,
            'assessment_year'   => $year,

            'systems_inventory' => $whole($org->systems ?? null, 65535),
            'agenda_meetings'   => $whole($org->agenda ?? null, 65535),
            'education_hours'   => is_numeric($org->edu ?? null) && (float) $org->edu >= 0 ? min(9999.9, round((float) $org->edu, 1)) : null,

            'consent_agreed_at' => $synthetic ? null : self::localTime($consent->at ?? null),
            'consent_terms_url' => $synthetic ? null : $text($consent->terms ?? null, 255, true),

            'status'            => 'complete',
            'is_synthetic'      => $synthetic ? 1 : 0,
            'archetype'         => $synthetic ? $text($record->archetype ?? null, 40, true) : null,
            'submitted_at'      => $completed,
            // The v32 page has no separate "accept this scorecard" step: reaching
            // the results page is what sends it.
            'confirmed_at'      => $synthetic ? null : $completed,
            'created_at'        => $completed,
        ]);

        $responses = isset($record->responses) && is_object($record->responses) ? $record->responses : new \stdClass();
        $now       = date('Y-m-d H:i:s');

        foreach ($instrument['items'] as $code => $itemIds) {
            $marks = isset($responses->{$code}) && (is_object($responses->{$code}) || is_array($responses->{$code}))
                ? (array) $responses->{$code}
                : [];

            foreach ($itemIds as $index => $itemId) {
                $mark = $marks[(string) $index] ?? $marks[$index] ?? null;

                if (!is_int($mark) && !(is_numeric($mark) && (int) $mark == $mark)) {
                    continue;
                }

                $mark = (int) $mark;

                if ($mark < 1 || $mark > 5) {
                    continue;
                }

                Database::insert($this->app->table('submission_responses'), [
                    'submission_id' => $submissionId,
                    'item_id'       => $itemId,
                    'mark'          => $mark,
                    'answered_at'   => $now,
                ]);
            }
        }

        (new Scorer($this->app))->score($submissionId);
    }

    /* ------------------------------------------------------------------ */
    /* console demo data -> es_records                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Give every synthetic console row that has no record one (the rows a
     * Data seeder run just wrote, or those from before the mirror existed),
     * so the page's admin views show the same demo set. The record is the
     * console's own JSON export of the row — the page's record shape — with
     * an id the page's own `syn-` ids cannot collide with.
     *
     * @return int how many were adopted
     */
    public function adoptSynthetic(?int $seedRunId = null): int
    {
        $where  = 's.`is_synthetic` = 1 AND s.`record_id` IS NULL AND s.`status` = \'complete\'';
        $params = [];

        if ($seedRunId !== null) {
            $where   .= ' AND s.`seed_run_id` = ?';
            $params[] = $seedRunId;
        }

        $rows = DatasetExporter::pageRecords($this->app, $where, $params);

        if ($rows === []) {
            return 0;
        }

        $received = gmdate('Y-m-d H:i:s');

        Database::transaction(function () use ($rows, $received): void {
            foreach ($rows as $submissionId => $record) {
                $id           = 'syn-s' . $submissionId;
                $record['id'] = $id;
                $completed    = self::utcTime($record['timestamp'] ?? null) ?? $received;

                Database::statement(
                    sprintf(
                        'INSERT INTO `%s` (`id`, `synthetic`, `restricted`, `email`, `completed_at`, `payload`, `received_at`)
                         VALUES (?, 1, 0, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE `payload` = IF(`synthetic` = 1, VALUES(`payload`), `payload`)',
                        $this->records()
                    ),
                    [
                        $id,
                        is_string($record['userEmail'] ?? null) ? mb_substr(strtolower($record['userEmail']), 0, 254) : null,
                        min($completed, $received),
                        json_encode($record, self::JSON_FLAGS),
                        $received,
                    ]
                );

                Database::statement(
                    sprintf('UPDATE `%s` SET `record_id` = ? WHERE `id` = ? AND `record_id` IS NULL', $this->submissions()),
                    [$id, $submissionId]
                );
            }
        });

        return count($rows);
    }

    /**
     * Before the console deletes synthetic rows (Data seeder purge, Wipe), the
     * records they mirror go too — otherwise the page would keep showing them
     * and the next sync would write them straight back.
     */
    public function forgetSynthetic(?int $seedRunId = null): void
    {
        if ($seedRunId === null) {
            Database::statement(sprintf('DELETE FROM `%s` WHERE `synthetic` = 1', $this->records()));

            return;
        }

        Database::statement(
            sprintf(
                'DELETE r FROM `%1$s` r JOIN `%2$s` s ON s.`record_id` = r.`id`
                  WHERE r.`synthetic` = 1 AND s.`is_synthetic` = 1 AND s.`seed_run_id` = ?',
                $this->records(),
                $this->submissions()
            ),
            [$seedRunId]
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * The published instrument and its item ids in order, per dimension code.
     * A record's responses are keyed by code, then by question index "0".."4".
     *
     * @return array{instrument:int, items:array<string, list<int>>}
     */
    private function instrument(): array
    {
        if ($this->instrument !== null) {
            return $this->instrument;
        }

        $instrumentId = (int) Database::scalar(sprintf(
            "SELECT `id` FROM `%s` WHERE `status` = 'published' ORDER BY `published_at` DESC LIMIT 1",
            $this->app->table('instruments')
        ));

        if ($instrumentId === 0) {
            throw new \RuntimeException('No published instrument to mirror records against.');
        }

        $items = [];
        foreach (Database::select(sprintf(
            'SELECT d.`code`, i.`id` FROM `%s` i JOIN `%s` d ON d.`id` = i.`dimension_id`
              WHERE d.`instrument_id` = ? ORDER BY d.`position`, i.`position`',
            $this->app->table('items'),
            $this->app->table('dimensions')
        ), [$instrumentId]) as $row) {
            $items[$row['code']][] = (int) $row['id'];
        }

        return $this->instrument = ['instrument' => $instrumentId, 'items' => $items];
    }

    /** Demo organisations nothing references any more (the seeder's own rule). */
    private function pruneOrganisations(): void
    {
        \Apps\EnterpriseAiScorecard\Services\Synthetic\SeedGenerator::pruneOrganisations();
    }

    /** An ISO 8601 time from the page as the console's stored DATETIME (app timezone). */
    private static function localTime(mixed $iso): ?string
    {
        $time = is_string($iso) && $iso !== '' ? strtotime($iso) : false;

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    /** An ISO 8601 time as a UTC DATETIME, as es_records keeps its clock. */
    private static function utcTime(mixed $iso): ?string
    {
        $time = is_string($iso) && $iso !== '' ? strtotime($iso) : false;

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
