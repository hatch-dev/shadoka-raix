<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;

/**
 * The Human Edge scorecard's server side: every rule of Part B of
 * the privacy directive the RAIX scorecards share, in one place.
 *
 * The page owns the record's shape and the server stores it exactly as
 * received (B1). This class only checks the few fields the directive names,
 * derives the columns it filters on (see the he_records migration), and never
 * returns more than each endpoint is allowed to.
 *
 * As in every scorecard since the automatic-saving directive, a repeat of a stored
 * id REPLACES the stored copy: the page re-sends the same id after "Review
 * answers", and the newer copy carries the person's corrected answers.
 *
 * JSON is decoded to OBJECTS, never to associative arrays, wherever it is
 * re-encoded: an array decode turns {"0":3,"1":2} into [3,2] and {} into [],
 * which would change the record the page wrote.
 *
 * Nothing here logs a body. The payloads hold names and email addresses (C6).
 */
final class RecordStore
{
    /** B1: larger bodies are refused with 413. */
    public const MAX_BODY_BYTES = 262144;

    /** B3 carries a whole generated demonstration set; bounded so one request cannot fill the disk. */
    public const MAX_SYNTHETIC_BODY_BYTES = 33554432;

    /** C3: records are deleted this many months after completion. */
    public const RETENTION_MONTHS = 24;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(private readonly AppManifest $app)
    {
    }

    private function table(): string
    {
        return $this->app->table('records');
    }

    /* ------------------------------------------------------------------ */
    /* B1 — save one scorecard                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Store one real scorecard, replacing a stored copy with the same id.
     *
     * @return array{status:int, error:?string} 200 on success
     */
    public function save(string $raw): array
    {
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return ['status' => 413, 'error' => 'The scorecard is too large.'];
        }

        $record = self::decodeObject($raw);

        if ($record === null) {
            return ['status' => 400, 'error' => 'Send one scorecard as a JSON object.'];
        }

        if ($problem = self::realRecordProblem($record)) {
            return ['status' => 400, 'error' => $problem];
        }

        $now = gmdate('Y-m-d H:i:s');

        $result = Database::transaction(function () use ($record, $raw, $now): array {
            $existing = Database::selectOne(
                sprintf('SELECT `synthetic`, `completed_at` FROM `%s` WHERE `id` = ? FOR UPDATE', $this->table()),
                [$record->id]
            );

            $derived = self::derive($record, $now);

            if ($existing === null) {
                Database::statement(
                    sprintf(
                        'INSERT INTO `%s` (`id`, `synthetic`, `restricted`, `email`, `completed_at`, `payload`, `received_at`)
                         VALUES (?, 0, 0, ?, ?, ?, ?)',
                        $this->table()
                    ),
                    [$record->id, $derived['email'], $derived['completed_at'], $raw, $now]
                );

                return ['status' => 200, 'error' => null];
            }

            // A real save must never overwrite demonstration data, nor be
            // shadowed by it: the id is the page's own random text, so a clash
            // is a malformed request rather than a person's correction.
            if ((int) $existing['synthetic'] === 1) {
                return ['status' => 400, 'error' => 'That scorecard id is already in use.'];
            }

            // The person's corrected copy. A restriction stays in force, and
            // the retention clock never moves later than it already was.
            Database::statement(
                sprintf(
                    'UPDATE `%s` SET `payload` = ?, `email` = ?, `completed_at` = LEAST(`completed_at`, ?), `updated_at` = ?
                      WHERE `id` = ? AND `synthetic` = 0',
                    $this->table()
                ),
                [$raw, $derived['email'], $derived['completed_at'], $now, $record->id]
            );

            return ['status' => 200, 'error' => null];
        });

        if ($result['status'] === 200) {
            $this->mirror()->sync($record->id);
        }

        return $result;
    }

    /** The admin console's copy of these records (Services/RecordMirror). */
    private function mirror(): RecordMirror
    {
        return new RecordMirror($this->app);
    }

    /**
     * Why an object is not an acceptable REAL record, or null when it is.
     * Public so a later staff correction (C4) can hold a corrected record to
     * the same rules B1 does.
     */
    public static function realRecordProblem(object $record): ?string
    {
        if (!property_exists($record, 'synthetic') || $record->synthetic !== false) {
            return 'Only real scorecards (synthetic: false) are accepted here.';
        }

        $consent = isset($record->org) && is_object($record->org) && isset($record->org->consent) && is_object($record->org->consent)
            ? $record->org->consent
            : null;

        if ($consent === null || ($consent->scorecard ?? null) !== true) {
            return 'The scorecard consent is missing.';
        }

        if (($consent->research ?? null) !== true) {
            return 'Research consent is required to store a scorecard.';
        }

        return self::commonProblem($record);
    }

    /** Checks every stored record must pass, real or synthetic. */
    private static function commonProblem(object $record): ?string
    {
        if (!isset($record->id) || !is_string($record->id) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $record->id)) {
            return 'The scorecard id is missing or malformed.';
        }

        if (!isset($record->timestamp) || !is_string($record->timestamp) || strtotime($record->timestamp) === false) {
            return 'The completion time is missing or malformed.';
        }

        // The organization name is optional (requirement 9): empty or absent is
        // fine, but it must not arrive as something that is not text.
        if (isset($record->org) && is_object($record->org) && property_exists($record->org, 'org')
            && $record->org->org !== null && !is_string($record->org->org)) {
            return 'The organization name must be text.';
        }

        return null;
    }

    /**
     * The filter columns, derived from a record.
     *
     * @return array{email:?string, completed_at:string}
     */
    private static function derive(object $record, string $receivedAt): array
    {
        $email = null;
        foreach ([$record->userEmail ?? null, (isset($record->org) && is_object($record->org)) ? ($record->org->email ?? null) : null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $email = mb_substr(strtolower(trim($candidate)), 0, 254);
                break;
            }
        }

        // The earlier of "completed" and "arrived": a clock set in the future
        // must not stretch the 24-month limit.
        $arrived   = (int) strtotime($receivedAt . ' UTC');
        $stamp     = strtotime((string) ($record->timestamp ?? '')) ?: $arrived;
        $completed = gmdate('Y-m-d H:i:s', min($stamp, $arrived));

        return ['email' => $email, 'completed_at' => $completed];
    }

    /** A JSON object, decoded to stdClass, or null for anything else (an array, a scalar, invalid JSON). */
    public static function decodeObject(string $raw): ?object
    {
        try {
            $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $value instanceof \stdClass ? $value : null;
    }

    /* ------------------------------------------------------------------ */
    /* B2 — stored records, for the signed-in admin                         */
    /* ------------------------------------------------------------------ */

    /**
     * Every synthetic record and every unrestricted real one, as a JSON array
     * assembled from the stored bodies — so what comes back is what was sent.
     */
    public function visibleRecordsJson(): string
    {
        $rows = Database::select(sprintf(
            'SELECT `payload` FROM `%s` WHERE `synthetic` = 1 OR `restricted` = 0 ORDER BY `completed_at`, `id`',
            $this->table()
        ));

        return '[' . implode(',', array_column($rows, 'payload')) . ']';
    }

    /* ------------------------------------------------------------------ */
    /* B3 / B4 — demonstration records                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Replace the whole synthetic set. Real records are never touched.
     *
     * @return array{status:int, error:?string, count:int}
     */
    public function replaceSynthetic(string $raw, ?int $seedRunId = null): array
    {
        if (strlen($raw) > self::MAX_SYNTHETIC_BODY_BYTES) {
            return ['status' => 413, 'error' => 'Too many demonstration records in one request.', 'count' => 0];
        }

        $body = self::decodeObject($raw);

        if ($body === null || !isset($body->records) || !is_array($body->records)) {
            return ['status' => 400, 'error' => 'Send {"records": [ ... ]}.', 'count' => 0];
        }

        $received = gmdate('Y-m-d H:i:s');
        $rows     = [];
        $seen     = [];

        foreach ($body->records as $i => $record) {
            if (!$record instanceof \stdClass || !property_exists($record, 'synthetic') || $record->synthetic !== true) {
                return ['status' => 400, 'error' => 'Record ' . $i . ' is not marked synthetic: true.', 'count' => 0];
            }

            if ($problem = self::commonProblem($record)) {
                return ['status' => 400, 'error' => 'Record ' . $i . ': ' . $problem, 'count' => 0];
            }

            if (isset($seen[$record->id])) {
                return ['status' => 400, 'error' => 'Record id ' . $record->id . ' appears twice.', 'count' => 0];
            }

            $seen[$record->id] = true;
            $rows[]            = [$record, json_encode($record, self::JSON_FLAGS)];
        }

        // An id already held by a REAL record: storing this one would mean
        // overwriting or shadowing real data, so the whole request is refused.
        if ($seen !== []) {
            $ids       = array_map('strval', array_keys($seen));
            $collision = Database::scalar(
                sprintf(
                    'SELECT `id` FROM `%s` WHERE `synthetic` = 0 AND `id` IN (%s) LIMIT 1',
                    $this->table(),
                    implode(',', array_fill(0, count($ids), '?'))
                ),
                $ids
            );

            if ($collision !== null && $collision !== false) {
                return ['status' => 400, 'error' => 'A demonstration record reuses a real record\'s id.', 'count' => 0];
            }
        }

        Database::transaction(function () use ($rows, $received): void {
            Database::statement(sprintf('DELETE FROM `%s` WHERE `synthetic` = 1', $this->table()));

            foreach ($rows as [$record, $payload]) {
                $derived = self::derive($record, $received);

                Database::statement(
                    sprintf(
                        'INSERT INTO `%s` (`id`, `synthetic`, `restricted`, `email`, `completed_at`, `payload`, `received_at`)
                         VALUES (?, 1, 0, ?, ?, ?, ?)',
                        $this->table()
                    ),
                    [$record->id, $derived['email'], $derived['completed_at'], $payload, $received]
                );
            }
        });

        $this->mirror()->syncSynthetic($seedRunId);

        return ['status' => 200, 'error' => null, 'count' => count($rows)];
    }

    /** B4. Returns how many demonstration records were removed. */
    public function deleteSynthetic(): int
    {
        $deleted = Database::statement(sprintf('DELETE FROM `%s` WHERE `synthetic` = 1', $this->table()));

        $this->mirror()->syncSynthetic();

        return $deleted;
    }

    /* ------------------------------------------------------------------ */
    /* C4 — requests from individuals (admin Requests screen only)          */
    /* ------------------------------------------------------------------ */

    /**
     * Real records whose email matches, restricted ones included: a person
     * asking about their data is owed the restricted copy too.
     *
     * @return list<array<string, mixed>>
     */
    public function findByEmail(string $email): array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return [];
        }

        return Database::select(
            sprintf(
                'SELECT `id`, `restricted`, `email`, `completed_at`, `received_at`, `updated_at`, `payload`
                   FROM `%s` WHERE `synthetic` = 0 AND `email` = ? ORDER BY `completed_at` DESC',
                $this->table()
            ),
            [$email]
        );
    }

    /** One real record, or null. @return array<string, mixed>|null */
    public function findReal(string $id): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ? AND `synthetic` = 0', $this->table()),
            [$id]
        );
    }

    /**
     * Correct the identity fields. The page writes each of them twice (top
     * level and inside org), so a correction must change both copies or the
     * exports would disagree with each other.
     *
     * @param array{name?:string, email?:string, phone?:string, organization?:string} $fields
     * @return string|null an error, or null on success
     */
    public function correctIdentity(string $id, array $fields): ?string
    {
        $row = $this->findReal($id);

        if ($row === null) {
            return 'That record no longer exists.';
        }

        $record = self::decodeObject((string) $row['payload']);

        if ($record === null) {
            return 'The stored record could not be read.';
        }

        if (!isset($record->org) || !is_object($record->org)) {
            $record->org = new \stdClass();
        }

        $map = [
            'name'         => ['userName', 'name'],
            'email'        => ['userEmail', 'email'],
            'phone'        => ['userPhone', 'phone'],
            'organization' => [null, 'org'],
        ];

        foreach ($map as $field => [$top, $inOrg]) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }

            $value = trim((string) $fields[$field]);

            if ($field === 'email' && $value === '') {
                return 'An email address is required: it is how the record is found.';
            }

            if ($field === 'name' && $value === '') {
                return 'A name is required.';
            }

            if ($top !== null) {
                // An emptied optional field keeps the form the page wrote it in.
                $record->{$top} = $value === '' && property_exists($record, $top) && $record->{$top} === null ? null : $value;
            }

            $record->org->{$inOrg} = $value;
        }

        return $this->replacePayload($id, json_encode($record, self::JSON_FLAGS));
    }

    /**
     * Replace a real record's whole body with a corrected one. It must still
     * be a record B1 would accept, with the same id.
     *
     * @return string|null an error, or null on success
     */
    public function replacePayload(string $id, string $json): ?string
    {
        $current = $this->findReal($id);

        if ($current === null) {
            return 'That record no longer exists.';
        }

        if (strlen($json) > self::MAX_BODY_BYTES) {
            return 'The corrected record is larger than 256 KB.';
        }

        $record = self::decodeObject($json);

        if ($record === null) {
            return 'The corrected record is not a single JSON object.';
        }

        if (($record->id ?? null) !== $id) {
            return 'The record id cannot be changed.';
        }

        if ($problem = self::realRecordProblem($record)) {
            return $problem;
        }

        $derived = self::derive($record, (string) $current['received_at']);

        // As on a re-save (B1): a correction never moves the deletion date later.
        Database::statement(
            sprintf(
                'UPDATE `%s` SET `payload` = ?, `email` = ?, `completed_at` = LEAST(`completed_at`, ?), `updated_at` = ?
                  WHERE `id` = ? AND `synthetic` = 0',
                $this->table()
            ),
            [$json, $derived['email'], $derived['completed_at'], gmdate('Y-m-d H:i:s'), $id]
        );

        $this->mirror()->sync($id);

        return null;
    }

    /** Restrict or lift a restriction. Returns false when there is no such real record. */
    public function setRestricted(string $id, bool $restricted): bool
    {
        $found = Database::statement(
            sprintf('UPDATE `%s` SET `restricted` = ?, `updated_at` = ? WHERE `id` = ? AND `synthetic` = 0', $this->table()),
            [$restricted ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]
        ) > 0 || $this->findReal($id) !== null;

        // Restricted: gone from every console screen and export; lifted: back.
        $this->mirror()->sync($id);

        return $found;
    }

    /** Delete one demonstration record (the console's per-record delete). */
    public function deleteSyntheticOne(string $id): bool
    {
        $deleted = Database::statement(
            sprintf('DELETE FROM `%s` WHERE `id` = ? AND `synthetic` = 1', $this->table()),
            [$id]
        ) > 0;

        $this->mirror()->remove($id);

        return $deleted;
    }

    /** Delete one real record, whole (a deletion request, or consent withdrawn). */
    public function deleteReal(string $id): bool
    {
        $deleted = Database::statement(
            sprintf('DELETE FROM `%s` WHERE `id` = ? AND `synthetic` = 0', $this->table()),
            [$id]
        ) > 0;

        $this->mirror()->remove($id);

        return $deleted;
    }

    /* ------------------------------------------------------------------ */
    /* C3 — 24-month deletion                                               */
    /* ------------------------------------------------------------------ */

    /** The oldest completion time a record may have and still be kept. */
    public static function retentionCutoff(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $now->modify('-' . self::RETENTION_MONTHS . ' months')->format('Y-m-d H:i:s');
    }

    /** The date a record completed at $completedAt (UTC) will be deleted. */
    public static function deleteBy(string $completedAt): string
    {
        return (new \DateTimeImmutable($completedAt, new \DateTimeZone('UTC')))
            ->modify('+' . self::RETENTION_MONTHS . ' months')
            ->format('Y-m-d H:i:s');
    }

    /** Delete every record, real or synthetic, completed more than 24 months ago. Returns how many. */
    public function purgeExpired(?\DateTimeImmutable $now = null): int
    {
        $deleted = Database::statement(
            sprintf('DELETE FROM `%s` WHERE `completed_at` < ?', $this->table()),
            [self::retentionCutoff($now)]
        );

        // Their console copies go with them.
        $this->mirror()->prune();

        return $deleted;
    }
}
