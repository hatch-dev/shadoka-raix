<?php

declare(strict_types=1);

use Platform\Core\Assert;
use Platform\Core\LogRetention;

/**
 * Platform\Core\LogRetention on throwaway files — never on the real logs.
 * Every format the host writes, the 30-day line, continuation lines, dated
 * rotated files, and email redaction.
 */
return static function (Assert $t): void {
    $dir = sys_get_temp_dir() . '/raix-logret-' . bin2hex(random_bytes(4));
    mkdir($dir);

    $now = strtotime('2026-09-29 12:00:00 UTC');
    $old = $now - 40 * 86400;   // past the limit
    $new = $now - 2 * 86400;    // inside it

    try {
        $t->same('access-log time', $old, LogRetention::lineTime('::1 - - [' . date('d/M/Y:H:i:s O', $old) . '] "GET / HTTP/1.1" 200 5'));
        $t->same('app.log time', $new, LogRetention::lineTime('[' . gmdate('c', $new) . '] ERROR: x'));
        $t->true('apache error-log time is read', LogRetention::lineTime('[Mon Feb 02 09:21:28.289589 2026] [ssl:warn] x') !== null);
        $t->true('php error_log time is read', LogRetention::lineTime('[29-Sep-2026 09:36:02 UTC] PHP Warning: x') !== null);
        $t->same('a continuation line has no time', null, LogRetention::lineTime('#0 {main}'));

        $a = static fn (int $ts, string $path): string => '::1 - - [' . date('d/M/Y:H:i:s O', $ts) . '] "GET ' . $path . ' HTTP/1.1" 200 5' . "\n";
        file_put_contents($dir . '/access.log',
            $a($old, '/old') . $a($new, '/admin/x?email=jane.doe%40example.com') . $a($new, '/keep'));
        file_put_contents($dir . '/app.log',
            '[' . gmdate('c', $old) . "] ERROR: old\n#0 old trace line\n"
            . '[' . gmdate('c', $new) . "] ERROR: mail to jane@example.org failed\n#0 new trace line\n");
        file_put_contents($dir . '/access-' . gmdate('Y-m-d', $old) . '.log', "whole old file\n");
        file_put_contents($dir . '/access-' . gmdate('Y-m-d', $new) . '.log', "recent file\n");

        $targets = [['dir' => $dir, 'patterns' => ['*.log']]];

        $dry = LogRetention::run($now, true, $targets);
        $t->same('a dry run changes nothing', 3, substr_count((string) file_get_contents($dir . '/access.log'), "\n"));
        $t->true('a dry run still reports', in_array('would trim', array_column($dry, 'action'), true));

        LogRetention::run($now, false, $targets);

        $access = (string) file_get_contents($dir . '/access.log');
        $t->true('access: the 40-day-old line is gone', !str_contains($access, '/old'));
        $t->true('access: recent lines stay', str_contains($access, '/keep'));
        $t->true('access: a URL-encoded address is redacted', !str_contains($access, 'jane.doe') && str_contains($access, 'email=[redacted]'));

        $app = (string) file_get_contents($dir . '/app.log');
        $t->true('app.log: an old entry goes with its trace line', !str_contains($app, 'old'));
        $t->true('app.log: a recent entry keeps its trace line', str_contains($app, 'new trace line'));
        $t->true('app.log: a plain address is redacted', !str_contains($app, 'jane@') && str_contains($app, 'mail to [redacted] failed'));

        $t->true('a dated file past the limit is deleted whole', !is_file($dir . '/access-' . gmdate('Y-m-d', $old) . '.log'));
        $t->true('a recent dated file is kept', is_file($dir . '/access-' . gmdate('Y-m-d', $new) . '.log'));

        $again = LogRetention::run($now, false, $targets);
        $t->same('a second run finds nothing to do', ['unchanged'], array_values(array_unique(array_column($again, 'action'))));
    } finally {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
};
