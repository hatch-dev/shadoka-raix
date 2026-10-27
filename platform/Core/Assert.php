<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * A deliberately small assertion collector.
 *
 * The platform has no test framework dependency: a test file returns a callable
 * that receives one of these and records outcomes. Enough to keep the data
 * layer honest without adding Composer packages to a XAMPP deployment.
 */
final class Assert
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    public function __construct(private readonly string $suite)
    {
    }

    public function true(string $label, bool $condition, string $detail = ''): void
    {
        $condition ? $this->pass() : $this->fail($label, $detail);
    }

    public function same(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) {
            $this->pass();
            return;
        }

        $this->fail($label, sprintf(
            "expected %s\n       actual   %s",
            $this->show($expected),
            $this->show($actual)
        ));
    }

    public function count(string $label, int $expected, array|\Countable $actual): void
    {
        $this->same($label, $expected, count($actual));
    }

    private function pass(): void
    {
        $this->passed++;
    }

    private function fail(string $label, string $detail): void
    {
        $this->failures[] = $detail === '' ? $label : $label . "\n       " . $detail;
    }

    private function show(mixed $value): string
    {
        if (is_string($value)) {
            return strlen($value) > 120
                ? json_encode(substr($value, 0, 117) . '...', JSON_UNESCAPED_UNICODE)
                : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: gettype($value);
    }

    public function suite(): string
    {
        return $this->suite;
    }

    public function passedCount(): int
    {
        return $this->passed;
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function ok(): bool
    {
        return $this->failures === [];
    }
}
