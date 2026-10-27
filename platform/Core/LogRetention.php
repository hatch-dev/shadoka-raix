<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Keep every log to its retention limit, and keep email addresses out of it.
 *
 * config/logging.php says which files and for how long. A file named with a
 * date is deleted whole once that date is past the limit; any other file is
 * trimmed in place, dropping each line older than the limit. A line with no
 * date of its own (a stack trace, a wrapped message) goes with the line above.
 * Kept lines have email addresses replaced with [redacted].
 *
 * Trimmed IN PLACE (rewritten through the same file, never renamed) because
 * the web server holds its logs open: a rename would leave it writing to the
 * old file. The server appends, so lines it writes after the rewrite still
 * land at the end.
 *
 * Streams line by line: an access log that was never trimmed runs to tens of
 * megabytes.
 */
final class LogRetention
{
    /** Anything that reads as an address, including URL-encoded @ (%40). */
    private const EMAIL = '/[A-Za-z0-9._%+-]+(?:@|%40)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,24}/i';

    /**
     * @return list<array{file:string, action:string, dropped:int, redacted:int}>
     */
    public static function run(?int $now = null, bool $dryRun = false, ?array $targets = null): array
    {
        $now    ??= time();
        $cutoff = $now - ((int) config('logging.days', 30)) * 86400;
        $report = [];

        foreach ($targets ?? (array) config('logging.targets', []) as $target) {
            $dir = rtrim((string) ($target['dir'] ?? ''), '/\\');

            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            $files = [];
            foreach ((array) ($target['patterns'] ?? []) as $pattern) {
                foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
                    $files[$file] = true;
                }
            }

            foreach (array_keys($files) as $file) {
                if (is_file($file)) {
                    $report[] = self::apply($file, $cutoff, $dryRun);
                }
            }
        }

        return $report;
    }

    /** @return array{file:string, action:string, dropped:int, redacted:int} */
    private static function apply(string $file, int $cutoff, bool $dryRun): array
    {
        // A dated file (rotatelogs' access-2026-09-29.log) goes whole.
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', basename($file), $m)) {
            $day = strtotime("{$m[1]}-{$m[2]}-{$m[3]} 23:59:59 UTC");

            if ($day !== false && $day < $cutoff) {
                if (!$dryRun) {
                    @unlink($file);
                }

                return ['file' => $file, 'action' => 'deleted', 'dropped' => 0, 'redacted' => 0];
            }
        }

        $in = @fopen($file, 'rb');

        if ($in === false) {
            return ['file' => $file, 'action' => 'unreadable', 'dropped' => 0, 'redacted' => 0];
        }

        $tmp      = fopen('php://temp/maxmemory:8388608', 'w+b');
        $keep     = true;
        $dropped  = 0;
        $redacted = 0;

        while (($line = fgets($in)) !== false) {
            $at = self::lineTime($line);

            if ($at !== null) {
                $keep = $at >= $cutoff;
            }

            if (!$keep) {
                $dropped++;
                continue;
            }

            $clean = preg_replace(self::EMAIL, '[redacted]', $line, -1, $count);

            if ($count > 0) {
                $redacted += $count;
                $line      = (string) $clean;
            }

            fwrite($tmp, $line);
        }

        fclose($in);

        if ($dropped === 0 && $redacted === 0) {
            fclose($tmp);

            return ['file' => $file, 'action' => 'unchanged', 'dropped' => 0, 'redacted' => 0];
        }

        if (!$dryRun) {
            // Same file, truncated and refilled: the server's open handle stays valid.
            $out = @fopen($file, 'r+b');

            if ($out === false) {
                fclose($tmp);

                return ['file' => $file, 'action' => 'locked', 'dropped' => $dropped, 'redacted' => $redacted];
            }

            flock($out, LOCK_EX);
            ftruncate($out, 0);
            rewind($tmp);
            stream_copy_to_stream($tmp, $out);
            fflush($out);
            flock($out, LOCK_UN);
            fclose($out);
        }

        fclose($tmp);

        return ['file' => $file, 'action' => $dryRun ? 'would trim' : 'trimmed', 'dropped' => $dropped, 'redacted' => $redacted];
    }

    /**
     * When a log line was written, from any of the formats in use here, or
     * null for a line that carries no time of its own.
     *
     *   Apache access / ssl_request   [02/Feb/2026:09:36:02 +0530]
     *   Apache error                  [Mon Feb 02 09:21:28.289589 2026]
     *   platform app.log              [2026-09-22T05:40:42+00:00]
     *   PHP error_log                 [29-Sep-2026 09:36:02 UTC]
     */
    public static function lineTime(string $line): ?int
    {
        if (preg_match('#\[(\d{2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]#', $line, $m)) {
            $d = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[1]);

            return $d === false ? null : $d->getTimestamp();
        }

        if (preg_match('/^\[[A-Za-z]{3} ([A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)? (\d{4})\]/', $line, $m)) {
            $t = strtotime($m[1] . ' ' . $m[2]);

            return $t === false ? null : $t;
        }

        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^\]]*)\]/', $line, $m)
            || preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}[^\]]*)\]/', $line, $m)) {
            $t = strtotime($m[1]);

            return $t === false ? null : $t;
        }

        return null;
    }
}
