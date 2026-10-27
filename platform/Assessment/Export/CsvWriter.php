<?php

declare(strict_types=1);

namespace Platform\Assessment\Export;

/**
 * RFC 4180 CSV, built in memory.
 *
 * Every field is quoted and embedded quotes are doubled, which is what makes a
 * board name like `Ac"me, Inc` survive the round trip. The legacy build got
 * this right and it is worth keeping right: a research export that silently
 * mangles one row in a thousand is worse than one that fails.
 *
 * A UTF-8 BOM is written by default because Excel on Windows — where these
 * files are opened — otherwise reads the en dashes in "251–1,000" as mojibake.
 */
final class CsvWriter
{
    /** @var list<string> */
    private array $lines = [];

    private int $columns = 0;

    public function __construct(private readonly bool $withBom = true)
    {
    }

    /** @param list<string> $headers */
    public function headers(array $headers): self
    {
        $this->columns = count($headers);

        return $this->row($headers);
    }

    /** @param list<mixed> $values */
    public function row(array $values): self
    {
        $this->lines[] = implode(',', array_map([self::class, 'cell'], $values));

        return $this;
    }

    /**
     * Quote one value.
     *
     * null becomes an empty field rather than the string "null"; a bool becomes
     * 1/0, because a spreadsheet reading "true" as text and 1 as a number is a
     * difference that shows up much later, in someone's pivot table.
     */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            $value = '';
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return '"' . str_replace('"', '""', (string) $value) . '"';
    }

    /** How many rows have been written, excluding the header. */
    public function count(): int
    {
        return max(0, count($this->lines) - 1);
    }

    public function columnCount(): int
    {
        return $this->columns;
    }

    public function toString(): string
    {
        // CRLF: the line ending RFC 4180 specifies, and the one Excel expects.
        return ($this->withBom ? "\xEF\xBB\xBF" : '') . implode("\r\n", $this->lines) . "\r\n";
    }
}
