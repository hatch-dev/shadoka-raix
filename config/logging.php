<?php
/**
 * Log retention.
 *
 * The public Director Scorecard's privacy notice says "Server logs, which
 * record the address of the device you connect from, are kept for 30 days",
 * and directive v13 (C6) adds that no log may hold a person's name, email or
 * phone number. Platform\Core\LogRetention keeps both promises for every log
 * listed here: it drops lines older than `days` and redacts email addresses
 * in the lines it keeps. It runs daily with the scorecard's deletion job.
 */
declare(strict_types=1);

return [
    'days' => 30,

    /*
     * Directories and the file names in them that are request or application
     * logs. A pattern is a glob; a file whose name carries a date
     * (access-2026-09-29.log, as rotatelogs writes) is deleted whole once that
     * date is past the limit, any other file is trimmed line by line.
     *
     * SERVER_LOG_DIR is the web server's own log folder (under XAMPP,
     * C:/xampp/apache/logs); PHP_LOG_DIR is PHP's error_log folder. Either may
     * be empty on a host where the platform cannot reach them — there, the
     * host's own retention setting must do this job (CloudWatch, etc.).
     */
    'targets' => array_values(array_filter([
        ['dir' => storage_path('logs'), 'patterns' => ['*.log']],
        env('SERVER_LOG_DIR') ? ['dir' => (string) env('SERVER_LOG_DIR'), 'patterns' => ['access*.log', 'error*.log', 'ssl_request*.log']] : null,
        env('PHP_LOG_DIR') ? ['dir' => (string) env('PHP_LOG_DIR'), 'patterns' => ['php_error_log*']] : null,
    ])),
];
