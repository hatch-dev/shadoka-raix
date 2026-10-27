<?php

declare(strict_types=1);

/**
 * Directive C6 — every log that records requests is kept 30 days: CloudFront,
 * API Gateway, load balancer, S3 access logs, and Lambda or application logs
 * in CloudWatch. (The privacy notice: "Server logs, which record the address
 * of the device you connect from, are kept for 30 days.")
 *
 *   php scripts/ops/aws-log-retention.php --region=us-east-1
 *       Survey only. Finds where requests are logged — CloudFront and load
 *       balancer access-log buckets, S3 server access-log targets, API Gateway
 *       execution and access log groups, Lambda log groups — and every
 *       CloudWatch log group, with its current retention. Changes nothing.
 *
 *   ... --all-discovered [--apply]
 *       Set 30 days on every log destination the survey found (the access-log
 *       buckets/prefixes and the API Gateway and Lambda log groups).
 *
 *   ... --log-group-prefix=/aws/lambda/raix --bucket=my-logs/cloudfront/ [--apply]
 *       Set 30 days on these only (each option may repeat). Use this when the
 *       account logs other products too, whose logs must not be shortened.
 *
 * CloudWatch log groups get a 30-day retention policy. S3 log buckets get a
 * lifecycle rule (id "raix-log-retention-30d…") that expires objects under the
 * prefix after 30 days and, on a versioned bucket, old versions after 1 day;
 * the bucket's other lifecycle rules are kept. S3 applies lifecycle rules once
 * a day, so a just-set rule takes up to a day to delete what is already old.
 *
 * Exit 0: everything selected meets C6. Exit 1: something still does not (or
 * a dry run found changes to make). Exit 2: bad arguments.
 */

require __DIR__ . '/lib/aws.php';

$ops  = new Ops($argv);
$days = 30;

$ops->line(sprintf('C6 log retention — region %s, %d days%s', $ops->region, $days, $ops->apply ? '' : ' (dry run)'));

/* A section that fails is a note when it was only being surveyed, and NOT MET
   when it holds something named on the command line: that could not be checked. */
$try = static function (string $what, callable $fn, bool $named = false) use ($ops): void {
    try {
        $fn();
    } catch (AwsError $e) {
        $named ? $ops->problem($what . ' could not be checked: ' . $e->getMessage()) : $ops->note($what . ': ' . $e->getMessage());
    }
};

/** @var array<string, string> bucket/prefix => what logs there */
$bucketTargets = [];
/** @var array<string, string> log group => what logs there */
$groupTargets  = [];

$addBucket = static function (string $bucket, string $prefix, string $what) use (&$bucketTargets): void {
    // CloudFront names the bucket as "name.s3.amazonaws.com".
    $bucket = (string) preg_replace('/\.s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com$/', '', $bucket);
    if ($bucket !== '') {
        $bucketTargets[$bucket . '/' . ltrim($prefix, '/')] = $what;
    }
};

/* ------------------------------------------------------------------ */
/* Where are requests logged?                                           */
/* ------------------------------------------------------------------ */

$ops->heading('Where requests are logged');

$try('CloudFront', static function () use ($ops, $addBucket): void {
    foreach ($ops->read(['cloudfront', 'list-distributions'], true)['DistributionList']['Items'] ?? [] as $dist) {
        $config  = $ops->read(['cloudfront', 'get-distribution-config', '--id', (string) $dist['Id']], true)['DistributionConfig'] ?? [];
        $logging = $config['Logging'] ?? [];
        $aliases = implode(', ', $config['Aliases']['Items'] ?? []) ?: (string) $dist['DomainName'];

        if (!empty($logging['Enabled'])) {
            $addBucket((string) $logging['Bucket'], (string) ($logging['Prefix'] ?? ''), "CloudFront {$dist['Id']} ({$aliases})");
            $ops->line(sprintf('  CloudFront %s (%s) -> s3://%s/%s', $dist['Id'], $aliases, preg_replace('/\.s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com$/', '', (string) $logging['Bucket']), $logging['Prefix'] ?? ''));
        } else {
            $ops->line(sprintf('  CloudFront %s (%s): standard logging off', $dist['Id'], $aliases));
        }
    }
});

$try('Load balancers', static function () use ($ops, $addBucket): void {
    foreach ($ops->read(['elbv2', 'describe-load-balancers'])['LoadBalancers'] ?? [] as $lb) {
        $attrs = array_column($ops->read(['elbv2', 'describe-load-balancer-attributes', '--load-balancer-arn', (string) $lb['LoadBalancerArn']])['Attributes'] ?? [], 'Value', 'Key');
        foreach (['access_logs', 'connection_logs'] as $kind) {
            if (($attrs["{$kind}.s3.enabled"] ?? 'false') === 'true') {
                $addBucket((string) $attrs["{$kind}.s3.bucket"], (string) ($attrs["{$kind}.s3.prefix"] ?? ''), "load balancer {$lb['LoadBalancerName']} ({$kind})");
                $ops->line(sprintf('  load balancer %s %s -> s3://%s/%s', $lb['LoadBalancerName'], $kind, $attrs["{$kind}.s3.bucket"], $attrs["{$kind}.s3.prefix"] ?? ''));
            }
        }
    }
    foreach ($ops->read(['elb', 'describe-load-balancers'])['LoadBalancerDescriptions'] ?? [] as $lb) {
        $log = $ops->read(['elb', 'describe-load-balancer-attributes', '--load-balancer-name', (string) $lb['LoadBalancerName']])['LoadBalancerAttributes']['AccessLog'] ?? [];
        if (!empty($log['Enabled'])) {
            $addBucket((string) $log['S3BucketName'], (string) ($log['S3BucketPrefix'] ?? ''), "classic load balancer {$lb['LoadBalancerName']}");
            $ops->line(sprintf('  classic load balancer %s -> s3://%s/%s', $lb['LoadBalancerName'], $log['S3BucketName'], $log['S3BucketPrefix'] ?? ''));
        }
    }
});

$try('S3 server access logging', static function () use ($ops, $addBucket): void {
    foreach ($ops->read(['s3api', 'list-buckets'], true)['Buckets'] ?? [] as $bucket) {
        try {
            $logging = $ops->read(['s3api', 'get-bucket-logging', '--bucket', (string) $bucket['Name']], true)['LoggingEnabled'] ?? null;
        } catch (AwsError) {
            continue; // another region's bucket, or no right to read its settings
        }
        if ($logging !== null) {
            $addBucket((string) $logging['TargetBucket'], (string) ($logging['TargetPrefix'] ?? ''), "S3 access log of {$bucket['Name']}");
            $ops->line(sprintf('  S3 bucket %s access log -> s3://%s/%s', $bucket['Name'], $logging['TargetBucket'], $logging['TargetPrefix'] ?? ''));
        }
    }
});

$try('API Gateway (REST)', static function () use ($ops, &$groupTargets): void {
    foreach ($ops->read(['apigateway', 'get-rest-apis'])['items'] ?? [] as $api) {
        foreach ($ops->read(['apigateway', 'get-stages', '--rest-api-id', (string) $api['id']])['item'] ?? [] as $stage) {
            $label = "API Gateway {$api['name']} / {$stage['stageName']}";
            $groupTargets["API-Gateway-Execution-Logs_{$api['id']}/{$stage['stageName']}"] = $label . ' (execution log)';
            if (isset($stage['accessLogSettings']['destinationArn'])) {
                $groupTargets[preg_replace('/^.*:log-group:([^:]+).*$/', '$1', (string) $stage['accessLogSettings']['destinationArn'])] = $label . ' (access log)';
            }
            foreach ($stage['methodSettings'] ?? [] as $path => $setting) {
                if (!empty($setting['dataTraceEnabled'])) {
                    $ops->problem("{$label} {$path}: full request logging is ON — it writes request bodies (names, emails) to the log. Turn off \"Log full requests/responses data\" (C6).");
                }
            }
        }
    }
});

$try('API Gateway (HTTP)', static function () use ($ops, &$groupTargets): void {
    foreach ($ops->read(['apigatewayv2', 'get-apis'])['Items'] ?? [] as $api) {
        foreach ($ops->read(['apigatewayv2', 'get-stages', '--api-id', (string) $api['ApiId']])['Items'] ?? [] as $stage) {
            if (isset($stage['AccessLogSettings']['DestinationArn'])) {
                $groupTargets[preg_replace('/^.*:log-group:([^:]+).*$/', '$1', (string) $stage['AccessLogSettings']['DestinationArn'])]
                    = "HTTP API {$api['Name']} / {$stage['StageName']} (access log)";
            }
        }
    }
});

$try('Lambda', static function () use ($ops, &$groupTargets): void {
    foreach ($ops->read(['lambda', 'list-functions'])['Functions'] ?? [] as $fn) {
        $group = (string) ($fn['LoggingConfig']['LogGroup'] ?? '/aws/lambda/' . $fn['FunctionName']);
        $groupTargets[$group] = "Lambda {$fn['FunctionName']}";
    }
});

foreach ($groupTargets as $group => $what) {
    $ops->line(sprintf('  %s -> log group %s', $what, $group));
}

/* ------------------------------------------------------------------ */
/* Which ones does this run change?                                     */
/* ------------------------------------------------------------------ */

$allDiscovered = $ops->has('all-discovered');
$prefixes      = $ops->all('log-group-prefix');

foreach ($ops->all('bucket') as $spec) {
    [$b, $p] = array_pad(explode('/', $spec, 2), 2, '');
    $addBucket($b, $p, 'named with --bucket');
}

$selectedBuckets = array_filter(
    $bucketTargets,
    static fn (string $what): bool => $allDiscovered || $what === 'named with --bucket'
);

/* ------------------------------------------------------------------ */
/* CloudWatch log groups                                                */
/* ------------------------------------------------------------------ */

$ops->heading('CloudWatch log groups');
$try('CloudWatch Logs', static function () use ($ops, $days, $groupTargets, $allDiscovered, $prefixes): void {
    $groups = $ops->read(['logs', 'describe-log-groups'])['logGroups'] ?? [];

    if ($groups === []) {
        $ops->note('no log groups in this region');
    }

    foreach ($groups as $group) {
        $name     = (string) $group['logGroupName'];
        $keep     = $group['retentionInDays'] ?? null;
        $selected = ($allDiscovered && isset($groupTargets[$name]))
            || array_filter($prefixes, static fn (string $p): bool => $p !== '' && str_starts_with($name, $p)) !== [];
        $state    = $keep === null ? 'kept FOREVER' : "kept {$keep} days";

        if ($keep !== null && (int) $keep <= $days) {
            $ops->ok("{$name}: {$state}");
        } elseif ($selected) {
            $ops->change("set {$name} retention ({$state}) to {$days} days",
                ['logs', 'put-retention-policy', '--log-group-name', $name, '--retention-in-days', (string) $days]);
        } elseif (isset($groupTargets[$name])) {
            $ops->problem("{$name}: {$state} — it logs {$groupTargets[$name]}; select it (--all-discovered or --log-group-prefix)");
        } else {
            $ops->note("{$name}: {$state} (not known to log scorecard requests; --log-group-prefix to include it)");
        }
    }

    // CloudFront's own CloudWatch logs (real-time, vended) live in us-east-1.
    if ($ops->region !== 'us-east-1') {
        $ops->note('CloudFront and other global services log to CloudWatch in us-east-1: run this again with --region=us-east-1');
    }
}, $prefixes !== []);

/* ------------------------------------------------------------------ */
/* S3 log buckets                                                       */
/* ------------------------------------------------------------------ */

$ops->heading('S3 log buckets');

if ($bucketTargets === []) {
    $ops->note('no access-log buckets found (and none named with --bucket)');
}

$ruleId = static fn (string $prefix): string => 'raix-log-retention-30d' . ($prefix === '' ? '' : '-' . substr(md5($prefix), 0, 8));

foreach ($bucketTargets as $target => $what) {
    [$bucket, $prefix] = array_pad(explode('/', $target, 2), 2, '');

    $try("bucket {$bucket}", static function () use ($ops, $bucket, $prefix, $what, $days, $ruleId, $selectedBuckets, $target): void {
        try {
            $rules = $ops->read(['s3api', 'get-bucket-lifecycle-configuration', '--bucket', $bucket], true)['Rules'] ?? [];
        } catch (AwsError $e) {
            if (!$e->is('NoSuchLifecycleConfiguration')) {
                throw $e;
            }
            $rules = [];
        }

        // Already covered: an enabled rule expiring everything under this
        // prefix (or a shorter one containing it) within 30 days.
        foreach ($rules as $rule) {
            $rulePrefix = (string) ($rule['Filter']['Prefix'] ?? $rule['Prefix'] ?? ($rule['Filter']['And']['Prefix'] ?? ''));
            $hasTags    = isset($rule['Filter']['Tag']) || isset($rule['Filter']['And']['Tags']);
            if (($rule['Status'] ?? '') === 'Enabled' && !$hasTags && isset($rule['Expiration']['Days'])
                && (int) $rule['Expiration']['Days'] <= $days && str_starts_with($prefix, $rulePrefix)) {
                $ops->ok(sprintf('s3://%s/%s (%s): expires after %d days (rule %s)', $bucket, $prefix, $what, $rule['Expiration']['Days'], $rule['ID'] ?? '?'));

                return;
            }
        }

        if (!isset($selectedBuckets[$target])) {
            $ops->problem(sprintf('s3://%s/%s (%s): no expiry within %d days — select it (--all-discovered or --bucket=%s/%s)', $bucket, $prefix, $what, $days, $bucket, $prefix));

            return;
        }

        $versioned = ($ops->read(['s3api', 'get-bucket-versioning', '--bucket', $bucket], true)['Status'] ?? '') !== '';
        $id        = $ruleId($prefix);
        $rules     = array_values(array_filter($rules, static fn (array $r): bool => ($r['ID'] ?? '') !== $id));
        $rules[]   = array_filter([
            'ID'                             => $id,
            'Filter'                         => ['Prefix' => $prefix],
            'Status'                         => 'Enabled',
            'Expiration'                     => ['Days' => $days],
            'NoncurrentVersionExpiration'    => $versioned ? ['NoncurrentDays' => 1] : null,
            'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1],
        ]);

        $ops->change(
            sprintf('add lifecycle rule %s to s3://%s/%s: expire after %d days%s (keeping its %d other rule(s))',
                $id, $bucket, $prefix, $days, $versioned ? ', old versions after 1 day' : '', count($rules) - 1),
            ['s3api', 'put-bucket-lifecycle-configuration', '--bucket', $bucket, '--lifecycle-configuration', '{file:rules}'],
            ['rules' => json_encode(['Rules' => $rules], JSON_UNESCAPED_SLASHES)],
            true
        );
    }, isset($selectedBuckets[$target]));
}

$ops->line('');
$ops->line('Not covered here: logs on the server itself (Apache, PHP). See scripts/ops/server/ and README.md.');

$ops->finish();
