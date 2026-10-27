<?php

declare(strict_types=1);

/**
 * The few pieces the ops scripts share: options, a thin AWS CLI wrapper that
 * refuses to change anything unless --apply was given, and plain reporting.
 *
 * The AWS CLI (v2) must be installed and signed in with an identity that may
 * read, and for --apply change, the services each script names. AWS_CLI may
 * point at another binary (the tests use a stand-in).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class AwsError extends RuntimeException
{
    public function __construct(string $message, public readonly string $stderr)
    {
        parent::__construct($message);
    }

    /** True when AWS answered with this error code (e.g. NoSuchLifecycleConfiguration). */
    public function is(string $code): bool
    {
        return str_contains($this->stderr, $code);
    }
}

final class Ops
{
    public bool $apply = false;
    public string $region = '';

    /** @var array<string, list<string>> every --name=value given, by name */
    public array $options = [];

    private int $changes = 0;
    private int $problems = 0;

    /** @param list<string> $argv */
    public function __construct(array $argv)
    {
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--apply') {
                $this->apply = true;
            } elseif (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
                $this->options[$m[1]][] = $m[2] ?? '1';
            } else {
                $this->fail("Unknown argument: {$arg}");
            }
        }

        $this->region = $this->one('region') ?? '';

        if ($this->region === '') {
            $this->fail('Give --region=us-… (the region the scorecard data lives in).');
        }

        // C1: everything is in the United States. A script pointed elsewhere is
        // pointed at the wrong account section, not at the scorecard.
        if (!str_starts_with($this->region, 'us-')) {
            $this->fail("Region {$this->region} is not a United States region (directive C1).");
        }
    }

    public function one(string $name): ?string
    {
        return $this->options[$name][0] ?? null;
    }

    /** @return list<string> */
    public function all(string $name): array
    {
        return $this->options[$name] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * Run a read-only AWS CLI command and return its decoded JSON.
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    public function read(array $args, bool $global = false): array
    {
        return $this->run($args, $global);
    }

    /**
     * Run a command that CHANGES something — only with --apply. Without it the
     * command is printed and nothing is sent.
     *
     * @param list<string> $args
     * @param array<string, string> $files name => JSON body; "{file:name}" in $args is replaced by a file:// path
     */
    public function change(string $what, array $args, array $files = [], bool $global = false): void
    {
        $this->changes++;

        if (!$this->apply) {
            $this->line('  would ' . $what);

            return;
        }

        $temp = [];
        foreach ($files as $name => $body) {
            $path = tempnam(sys_get_temp_dir(), 'raix-ops-');
            file_put_contents($path, $body);
            $temp[$name] = $path;
        }

        try {
            $args = array_map(static function (string $a) use ($temp): string {
                return preg_replace_callback('/\{file:([a-z0-9-]+)\}/', static fn ($m) => 'file://' . str_replace('\\', '/', $temp[$m[1]]), $a) ?? $a;
            }, $args);

            $this->run($args, $global);
            $this->line('  done:  ' . $what);
        } finally {
            foreach ($temp as $path) {
                @unlink($path);
            }
        }
    }

    /** @param list<string> $args @return array<string, mixed> */
    private function run(array $args, bool $global): array
    {
        $cli = getenv('AWS_CLI') ?: 'aws';
        $cmd = $cli . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ($global ? '' : ' --region ' . escapeshellarg($this->region))
            . ' --output json --no-cli-pager';

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($proc)) {
            throw new AwsError('Could not start the AWS CLI.', '');
        }

        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            throw new AwsError('aws ' . implode(' ', array_slice($args, 0, 2)) . ' failed: ' . trim($err), $err);
        }

        $data = trim($out) === '' ? [] : json_decode($out, true);

        return is_array($data) ? $data : [];
    }

    /** Something that does not meet the directive and this run did not fix. */
    public function problem(string $text): void
    {
        $this->problems++;
        $this->line('  NOT MET  ' . $text);
    }

    public function ok(string $text): void
    {
        $this->line('  ok       ' . $text);
    }

    public function note(string $text): void
    {
        $this->line('  note     ' . $text);
    }

    public function heading(string $text): void
    {
        $this->line('');
        $this->line('== ' . $text);
    }

    public function line(string $text): void
    {
        echo $text, "\n";
    }

    public function fail(string $text): never
    {
        fwrite(STDERR, $text . "\n");
        exit(2);
    }

    /**
     * Exit code: 0 when everything met the directive (or was just fixed),
     * 1 when something still does not — so a scheduler or a person can tell.
     */
    public function finish(): never
    {
        $this->line('');

        if (!$this->apply && $this->changes > 0) {
            $this->line(sprintf('Dry run: %d change(s) listed above, nothing sent. Re-run with --apply to make them.', $this->changes));
        }

        if ($this->problems > 0) {
            $this->line(sprintf('Result: %d item(s) do not meet the directive (NOT MET above).', $this->problems));
        } elseif (!$this->apply && $this->changes > 0) {
            $this->line('Result: not met until the changes above are applied.');
        } else {
            $this->line('Result: everything checked meets the directive.');
        }

        exit($this->problems === 0 && ($this->apply || $this->changes === 0) ? 0 : 1);
    }

    /** Whole days since an ISO 8601 / AWS timestamp. */
    public static function ageDays(string $when): float
    {
        $t = is_numeric($when) ? (float) $when : (float) strtotime($when);

        return (time() - $t) / 86400;
    }
}
