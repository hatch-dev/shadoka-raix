<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

use Apps\HumanEdgeScorecard\Services\Scoring\Scorer;
use Platform\Assessment\OrganisationResolver;
use Platform\Core\AppManifest;
use Platform\Core\AppRegistry;
use Platform\Core\Database;

/**
 * he_records, mirrored into the console's he_submissions family.
 *
 * he_records is where scorecards are kept: the page writes it, Requests
 * correct, restrict and delete in it, and the 24-month job deletes from it.
 * The admin console's screens (Analytics, Research data, the Console, exports,
 * the organization view) read the relational he_submissions /
 * submission_responses / submission_scores tables, as every other RAIX
 * scorecard's console does. This class keeps the second in step with the
 * first, so every console screen shows exactly what he_records holds — and
 * nothing it no longer holds:
 *
 *   - one he_submissions row per record, tagged with `record_id`;
 *   - a RESTRICTED real record has no mirror (C4: excluded from every export
 *     and view, as from B2);
 *   - a deleted or expired record loses its mirror.
 *
 * Marks come from the record's own `responses` and are scored by the console's
 * Scorer, so a console figure is always the scoring rules' and never a value
 * the browser asserted.
 *
 * Demonstration data needs no second direction here: the console's Data
 * seeder writes its records into he_records exactly as the page's seeder does
 * (RecordStore::replaceSynthetic), and they reach the console through this.
 */
final class RecordMirror
{
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

        Records::flush();
    }

    /** Remove one record's mirror. */
    public function remove(string $id): void
    {
        $this->deleteCopies('s.`record_id` = ?', [$id]);
        Records::flush();
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
     * `organisations` is shared, so every app's {slug}_submissions is checked.
     *
     * @param list<int> $ids
     */
    private static function dropUnreferenced(array $ids): void
    {
        $inUse = [];
        foreach (AppRegistry::all() as $app) {
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
     * Mirror the whole synthetic set again, after it was replaced (B3, the
     * console's seeder) or deleted (B4). Every mirrored synthetic row goes,
     * then each synthetic record is written back.
     *
     * @return int how many synthetic records were mirrored
     */
    public function syncSynthetic(?int $seedRunId = null): int
    {
        $this->deleteCopies('s.`is_synthetic` = 1');

        $count = 0;
        foreach (Database::select(sprintf('SELECT `id`, `payload` FROM `%s` WHERE `synthetic` = 1 ORDER BY `completed_at`, `id`', $this->records())) as $row) {
            $record = RecordStore::decodeObject((string) $row['payload']);

            if ($record !== null) {
                $this->write((string) $row['id'], $record, true, $seedRunId);
                $count++;
            }
        }

        Records::flush();

        return $count;
    }

    /**
     * Drop every mirror whose record is gone (deleted, expired) or now
     * restricted. Cheap enough to run after any bulk change.
     */
    public function prune(): int
    {
        $deleted = $this->deleteCopies(sprintf(
            'NOT EXISTS (SELECT 1 FROM `%s` r WHERE r.`id` = s.`record_id` AND (r.`synthetic` = 1 OR r.`restricted` = 0))',
            $this->records()
        ));

        Records::flush();

        return $deleted;
    }

    /**
     * Mirror every record that has none yet, and drop mirrors whose record is
     * gone. Every console screen calls it first, so a change made anywhere
     * else (a test, a script, the page) is in step before anything is read.
     * Safe to re-run: it never rewrites a mirror that exists.
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
    private function write(string $id, object $record, bool $synthetic, ?int $seedRunId = null): void
    {
        $instrument = new Instrument($this->app);
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
        $hours = static fn ($value): ?float => is_numeric($value) && (float) $value >= 0 ? min(9999.9, round((float) $value, 1)) : null;

        $completed = self::localTime($record->timestamp ?? null) ?? date('Y-m-d H:i:s');
        $year      = $whole($org->year ?? $record->year ?? null, 65535) ?: (int) substr($completed, 0, 4);
        $orgName   = $text($org->org ?? null, 191, true);
        // The page writes "Not specified" for a sector left blank; the column
        // keeps NULL for that, and the console prints the page's words back.
        $sector    = $text($org->sector ?? null, 100, true);
        $sector    = $sector === 'Not specified' ? null : $sector;
        $size      = $text($org->size ?? null, 50, true);

        $submissionId = Database::insert($this->submissions(), [
            'public_token'      => bin2hex(random_bytes(16)),
            'record_id'         => $id,
            'instrument_id'     => $instrument->id(),
            // No organisation name means no organisation: such a record is
            // never counted as one.
            'organisation_id'   => OrganisationResolver::resolve($orgName, $sector, $size, $synthetic),

            'respondent_name'   => $text($record->userName ?? $org->name ?? null, 120),
            'respondent_email'  => $text($record->userEmail ?? $org->email ?? null, 254),
            'respondent_phone'  => $text($record->userPhone ?? $org->phone ?? null, 40, true),
            'organisation_name' => $orgName,
            'organisation_size' => $size,

            'role'              => $text($org->role ?? null, 100, true),
            'tenure'            => $text($org->tenure ?? null, 50, true),
            'sector'            => $sector,
            'assessment_year'   => $year,

            'ai_hours_per_week' => $hours($org->aiuse ?? null),
            'education_hours'   => $hours($org->edu ?? null),

            'consent_notice'    => $synthetic ? null : $text($consent->notice ?? null, 40, true),
            'consent_agreed_at' => $synthetic ? null : self::localTime($consent->at ?? null),
            'consent_terms_url' => $synthetic ? null : $text($consent->terms ?? null, 255, true),
            'consent_research'  => $synthetic ? null : ((($consent->research ?? null) === true) ? 1 : 0),

            'status'            => 'complete',
            'is_synthetic'      => $synthetic ? 1 : 0,
            'archetype'         => $synthetic ? $text($record->archetype ?? null, 40, true) : null,
            'seed_run_id'       => $synthetic ? $seedRunId : null,
            'submitted_at'      => $completed,
            'created_at'        => $completed,
        ]);

        $responses = isset($record->responses) && is_object($record->responses) ? $record->responses : new \stdClass();
        $now       = date('Y-m-d H:i:s');

        foreach ($instrument->dims() as $dim) {
            $code  = $dim['code'];
            $marks = isset($responses->{$code}) && (is_object($responses->{$code}) || is_array($responses->{$code}))
                ? (array) $responses->{$code}
                : [];

            foreach ($dim['qs'] as $index => $question) {
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
                    'item_id'       => $question['_id'],
                    'mark'          => $mark,
                    'answered_at'   => $now,
                ]);
            }
        }

        (new Scorer($this->app))->score($submissionId);
    }

    /** Demo organisations nothing references any more, across every app. */
    public static function pruneOrganisations(): void
    {
        $referenced = [];
        foreach (AppRegistry::all() as $other) {
            $candidate = $other->table('submissions');

            if (Database::hasTable($candidate)) {
                $referenced[] = sprintf('SELECT DISTINCT `organisation_id` FROM `%s` WHERE `organisation_id` IS NOT NULL', $candidate);
            }
        }

        if ($referenced !== []) {
            Database::statement(sprintf(
                'DELETE FROM `organisations` WHERE `is_synthetic` = 1 AND `id` NOT IN (%s)',
                implode(' UNION ', $referenced)
            ));
        }
    }

    /** An ISO 8601 time from the page as the console's stored DATETIME (app timezone). */
    private static function localTime(mixed $iso): ?string
    {
        $time = is_string($iso) && $iso !== '' ? strtotime($iso) : false;

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }
}
