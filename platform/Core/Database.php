<?php

declare(strict_types=1);

namespace Platform\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper exposing a single shared connection.
 *
 * Emulated prepares are OFF, so every query below is a real server-side
 * prepared statement. That is the platform's SQL-injection defence, and it is
 * the reason nothing in this codebase interpolates a value into SQL.
 */
final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    /** The shared connection, opened on first use. */
    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $cfg = config('database');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            self::$connection = new PDO($dsn, $cfg['user'], (string) $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Never leak the DSN or credentials to the caller.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        self::alignTimezone(self::$connection);

        return self::$connection;
    }

    /**
     * Make MySQL's clock agree with PHP's.
     *
     * Without this the two disagree by the server's UTC offset: PHP writes
     * datetimes in config('app.timezone') while NOW() returns whatever the
     * MySQL host's system timezone is. On this machine that was 5.5 hours, and
     * it silently broke every comparison between a stored datetime and NOW() —
     * rate-limit rows were deleted as "expired" the instant they were written.
     *
     * A fixed offset is used rather than a named zone because MySQL's named
     * timezone tables are frequently not loaded on a default install.
     */
    private static function alignTimezone(PDO $pdo): void
    {
        try {
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone((string) config('app.timezone', 'UTC'))))
                ->format('P');

            $statement = $pdo->prepare('SET time_zone = ?');
            $statement->execute([$offset]);
        } catch (\Throwable $e) {
            // A server that refuses the setting is a problem worth knowing
            // about, but not one worth refusing to serve over.
            logger('Could not align the database timezone: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * Connect without selecting a database — used only by the installer, which
     * has to create the schema before it can be selected.
     */
    public static function serverConnection(): PDO
    {
        $cfg = config('database');
        $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);

        try {
            return new PDO($dsn, $cfg['user'], (string) $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database server connection failed.', 0, $e);
        }
    }

    /** Run a prepared statement and return it. */
    public static function run(string $sql, array $bindings = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /** All matching rows. */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    /** The first matching row, or null. */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /** The first column of the first row, or null. */
    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Execute a statement and return the number of affected rows. */
    public static function statement(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    /** Insert a row and return its id. */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        self::run($sql, $data);

        return (int) self::connection()->lastInsertId();
    }

    /**
     * Run a callback inside a transaction, rolling back on any exception.
     *
     * Nested calls join the outer transaction rather than opening a second one,
     * which MySQL does not support.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** True when the named table exists in the configured schema. */
    public static function hasTable(string $table): bool
    {
        $found = self::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [config('database.name'), $table]
        );

        return (int) $found > 0;
    }

    /** Close the shared connection. Used by long-running CLI scripts. */
    public static function disconnect(): void
    {
        self::$connection = null;
    }
}
