<?php

declare(strict_types=1);

namespace Platform\Assessment;

use Platform\Core\Database;

/**
 * Finds or creates the platform-level organisation row for a typed board name.
 *
 * This lives in the platform, not in an app, because it is the join that makes
 * the multi-app design worth having: a company that appears in two apps must be
 * ONE row, or the two datasets can only ever be reconciled by comparing typed
 * names after the fact. Give each app its own copy of this table and that join
 * is gone permanently — which is why it sits here even while one app uses it.
 *
 * Respondents type the name freely — "Meridian Capital Group", "meridian
 * capital group.", "Meridian  Capital  Group" — so the uniqueness lives on a
 * normalised match_key rather than on the display name.
 */
final class OrganisationResolver
{
    /**
     * @param string|null $name     as typed by the respondent
     * @param string|null $sector
     * @param string|null $sizeBand
     * @param bool        $synthetic organisations created by a seeder are flagged,
     *                               so demo boards never pollute a real count
     */
    public static function resolve(
        ?string $name,
        ?string $sector = null,
        ?string $sizeBand = null,
        bool $synthetic = false,
    ): ?int {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $key = self::matchKey($name);

        if ($key === '') {
            return null;
        }

        $existing = Database::selectOne(
            'SELECT `id`, `sector`, `size_band` FROM `organisations` WHERE `match_key` = ?',
            [$key]
        );

        if ($existing !== null) {
            self::backfill((int) $existing['id'], $existing, $sector, $sizeBand);

            return (int) $existing['id'];
        }

        return Database::insert('organisations', [
            'name'         => mb_substr($name, 0, 191),
            'match_key'    => $key,
            'sector'       => $sector,
            'size_band'    => $sizeBand,
            'is_synthetic' => $synthetic ? 1 : 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Fill in sector or size if the existing row is missing them.
     *
     * Deliberately only fills blanks. A later respondent from the same board
     * disagreeing about its sector must not silently overwrite the first
     * answer — that is a data-quality question for a human, not a race.
     */
    private static function backfill(int $id, array $existing, ?string $sector, ?string $sizeBand): void
    {
        $updates  = [];
        $bindings = [];

        if ($existing['sector'] === null && $sector !== null && $sector !== '') {
            $updates[]  = '`sector` = ?';
            $bindings[] = $sector;
        }

        if ($existing['size_band'] === null && $sizeBand !== null && $sizeBand !== '') {
            $updates[]  = '`size_band` = ?';
            $bindings[] = $sizeBand;
        }

        if ($updates === []) {
            return;
        }

        $updates[]  = '`updated_at` = ?';
        $bindings[] = date('Y-m-d H:i:s');
        $bindings[] = $id;

        Database::statement(
            'UPDATE `organisations` SET ' . implode(', ', $updates) . ' WHERE `id` = ?',
            $bindings
        );
    }

    /**
     * Normalise a typed name into the key uniqueness is enforced on.
     *
     * Lowercased, accents left alone, punctuation dropped, whitespace collapsed.
     * Company suffixes are NOT stripped: "Apex Systems" and "Apex Systems Ltd"
     * may genuinely be different entities, and merging two real boards is worse
     * than holding two rows for one.
     */
    public static function matchKey(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $key) ?? '';
        $key = preg_replace('/\s+/u', ' ', $key) ?? '';

        return mb_substr(trim($key), 0, 191);
    }
}
