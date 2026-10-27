<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Key/value settings held in the `settings` table.
 *
 * A key that has no row and a key whose row holds NULL are different things,
 * and has() tells them apart: the Admin PIN uses "no row" to mean "never set,
 * so the installer may import one" and an empty value to mean "switched off
 * on purpose, leave it off".
 */
final class Settings
{
    /** @var array<string, array{value: ?string, updated_by: ?int, updated_at: ?string}|null> */
    private static array $cache = [];

    private function __construct()
    {
    }

    public static function has(string $key): bool
    {
        return self::row($key) !== null;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = self::row($key);

        return $row === null ? $default : $row['value'];
    }

    /** @return array{value: ?string, updated_by: ?int, updated_at: ?string}|null */
    public static function row(string $key): ?array
    {
        if (!array_key_exists($key, self::$cache)) {
            $row = Database::selectOne(
                'SELECT `value`, `updated_by`, `updated_at` FROM `settings` WHERE `key` = ? LIMIT 1',
                [$key]
            );

            self::$cache[$key] = $row === null ? null : [
                'value'      => $row['value'] === null ? null : (string) $row['value'],
                'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
                'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
            ];
        }

        return self::$cache[$key];
    }

    public static function put(string $key, ?string $value, ?int $updatedBy = null): void
    {
        Database::statement(
            'INSERT INTO `settings` (`key`, `value`, `updated_by`, `updated_at`) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_by` = VALUES(`updated_by`),
                                     `updated_at` = VALUES(`updated_at`)',
            [$key, $value, $updatedBy, date('Y-m-d H:i:s')]
        );

        unset(self::$cache[$key]);
    }

    public static function forget(string $key): void
    {
        Database::statement('DELETE FROM `settings` WHERE `key` = ?', [$key]);
        unset(self::$cache[$key]);
    }

    /** Forget the request cache. Tests and long-running CLI scripts need this. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
