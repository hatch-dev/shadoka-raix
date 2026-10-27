<?php

declare(strict_types=1);

/**
 * Directive C3 — backups and snapshots that hold scorecard data expire in
 * under 30 days, so a record deleted on request or after 24 months does not
 * survive in one.
 *
 *   php scripts/ops/aws-backup-retention.php --region=us-east-1
 *       Survey only: every AWS Backup plan and vault, RDS database, EBS
 *       snapshot policy and Lightsail resource in the region, with what each
 *       keeps and for how long. Changes nothing.
 *
 *   php scripts/ops/aws-backup-retention.php --region=us-east-1 \
 *       --backup-plan=<id> --vault=<name> --rds=<db-id> --rds-cluster=<id> \
 *       --volume=<vol-id> --lightsail=<instance> --lightsail-db=<db> [--apply]
 *       For the resources NAMED (each option may repeat): set retention to
 *       --days (default 29) and delete what is already older. Without --apply
 *       it lists exactly what it would do.
 *
 * Only named resources are changed. The account may hold other things whose
 * backups are kept for their own reasons, so nothing is swept account-wide.
 * Name the ones that hold the scorecard's database or the server's disk.
 *
 * Exit 0: everything named meets C3. Exit 1: something still does not (or a
 * dry run found changes to make). Exit 2: bad arguments.
 */

require __DIR__ . '/lib/aws.php';

$ops  = new Ops($argv);
$days = (int) ($ops->one('days') ?? 29);

if ($days < 1 || $days > 29) {
    $ops->fail('--days must be 1 to 29: the directive says "less than 30 days".');
}

$named = static fn (string $option): array => array_values(array_unique(array_filter($ops->all($option))));
$plans      = $named('backup-plan');
$vaults     = $named('vault');
$rdsIds     = $named('rds');
$clusters   = $named('rds-cluster');
$volumes    = $named('volume');
$lightsail  = $named('lightsail');
$lightDbs   = $named('lightsail-db');
$anyNamed   = $plans || $vaults || $rdsIds || $clusters || $volumes || $lightsail || $lightDbs;

$ops->line(sprintf('C3 backup retention — region %s, limit %d days%s', $ops->region, $days, $ops->apply ? '' : ' (dry run)'));

/* Each service is tried on its own: an account without Lightsail, or an
   identity without RDS rights, must not stop the rest of the survey. */
/* A section that fails is a note when it was only being surveyed, and NOT MET
   when it holds something named on the command line: that could not be checked. */
$try = static function (string $what, callable $fn, bool $named = false) use ($ops): void {
    try {
        $fn();
    } catch (AwsError $e) {
        $named ? $ops->problem($what . ' could not be checked: ' . $e->getMessage()) : $ops->note($what . ': ' . $e->getMessage());
    }
};

/* ------------------------------------------------------------------ */
/* AWS Backup                                                          */
/* ------------------------------------------------------------------ */

$ops->heading('AWS Backup — plans');
$try('AWS Backup plans', static function () use ($ops, $plans, $days): void {
    $list = $ops->read(['backup', 'list-backup-plans'])['BackupPlansList'] ?? [];

    if ($list === []) {
        $ops->note('no backup plans in this region');
    }

    foreach ($list as $summary) {
        $id   = (string) $summary['BackupPlanId'];
        $plan = $ops->read(['backup', 'get-backup-plan', '--backup-plan-id', $id]);
        $body = $plan['BackupPlan'] ?? [];
        $mine = in_array($id, $plans, true);
        $bad  = [];

        foreach ($body['Rules'] ?? [] as $rule) {
            $keep = $rule['Lifecycle']['DeleteAfterDays'] ?? null;
            $ops->line(sprintf('  plan %s (%s) rule "%s": %s', $body['BackupPlanName'] ?? '?', $id, $rule['RuleName'] ?? '?',
                $keep === null ? 'kept FOREVER' : "deleted after {$keep} days"));

            if ($keep === null || (int) $keep > $days || isset($rule['Lifecycle']['MoveToColdStorageAfterDays'])) {
                $bad[] = $rule['RuleName'] ?? '?';
            }
            foreach ($rule['CopyActions'] ?? [] as $copy) {
                $ck = $copy['Lifecycle']['DeleteAfterDays'] ?? null;
                if ($ck === null || (int) $ck > $days) {
                    $bad[] = ($rule['RuleName'] ?? '?') . ' (copy to ' . ($copy['DestinationBackupVaultArn'] ?? '?') . ')';
                }
            }
        }

        if (!$mine) {
            if ($bad !== []) {
                $ops->note("plan {$id} keeps backups {$days}+ days; if it backs up scorecard data, re-run with --backup-plan={$id}");
            }
            continue;
        }

        if ($bad === []) {
            $ops->ok("plan {$id}: every rule deletes within {$days} days");
            continue;
        }

        // update-backup-plan takes the plan without its read-only fields.
        $rules = [];
        foreach ($body['Rules'] ?? [] as $rule) {
            unset($rule['RuleId']);
            $rule['Lifecycle'] = ['DeleteAfterDays' => $days]; // cold storage needs 90+ days, so it goes
            foreach ($rule['CopyActions'] ?? [] as $i => $copy) {
                $rule['CopyActions'][$i]['Lifecycle'] = ['DeleteAfterDays' => $days];
            }
            $rules[] = $rule;
        }
        $new = ['BackupPlanName' => $body['BackupPlanName'], 'Rules' => $rules];
        if (isset($body['AdvancedBackupSettings'])) {
            $new['AdvancedBackupSettings'] = $body['AdvancedBackupSettings'];
        }

        $ops->change(
            "set plan {$id} rules " . implode(', ', $bad) . " to delete after {$days} days",
            ['backup', 'update-backup-plan', '--backup-plan-id', $id, '--backup-plan', '{file:plan}'],
            ['plan' => json_encode($new, JSON_UNESCAPED_SLASHES)]
        );
    }

    foreach (array_diff($plans, array_column($list, 'BackupPlanId')) as $missing) {
        $ops->problem("backup plan {$missing} not found in {$ops->region}");
    }
}, $plans !== []);

$ops->heading('AWS Backup — recovery points already taken');
$try('AWS Backup vaults', static function () use ($ops, $vaults, $days): void {
    $list = $ops->read(['backup', 'list-backup-vaults'])['BackupVaultList'] ?? [];

    foreach ($list as $vault) {
        $name = (string) $vault['BackupVaultName'];
        $ops->line(sprintf('  vault %s: %d recovery point(s)', $name, (int) ($vault['NumberOfRecoveryPoints'] ?? 0)));

        if (!in_array($name, $vaults, true)) {
            continue;
        }

        foreach ($ops->read(['backup', 'list-recovery-points-by-backup-vault', '--backup-vault-name', $name])['RecoveryPoints'] ?? [] as $point) {
            $arn  = (string) $point['RecoveryPointArn'];
            $age  = Ops::ageDays((string) $point['CreationDate']);
            $keep = $point['Lifecycle']['DeleteAfterDays'] ?? null;

            if (($point['Status'] ?? '') === 'DELETING') {
                continue;
            }

            if ($age >= $days) {
                $ops->change(sprintf('delete recovery point %s (%.0f days old)', $arn, $age),
                    ['backup', 'delete-recovery-point', '--backup-vault-name', $name, '--recovery-point-arn', $arn]);
            } elseif ($keep === null || (int) $keep > $days) {
                $ops->change(sprintf('set recovery point %s to delete at %d days (now %s)', $arn, $days, $keep === null ? 'never' : $keep),
                    ['backup', 'update-recovery-point-lifecycle', '--backup-vault-name', $name, '--recovery-point-arn', $arn,
                     '--lifecycle', 'DeleteAfterDays=' . $days]);
            }
        }
    }

    foreach (array_diff($vaults, array_column($list, 'BackupVaultName')) as $missing) {
        $ops->problem("backup vault {$missing} not found in {$ops->region}");
    }
}, $vaults !== []);

/* ------------------------------------------------------------------ */
/* RDS                                                                 */
/* ------------------------------------------------------------------ */

$ops->heading('RDS — automated backups and manual snapshots');
$try('RDS', static function () use ($ops, $rdsIds, $clusters, $days): void {
    $instances = $ops->read(['rds', 'describe-db-instances'])['DBInstances'] ?? [];

    foreach ($instances as $db) {
        $id   = (string) $db['DBInstanceIdentifier'];
        $keep = (int) ($db['BackupRetentionPeriod'] ?? 0);
        $ops->line(sprintf('  database %s (%s): automated backups kept %d day(s)%s', $id, $db['Engine'] ?? '?', $keep,
            isset($db['DBClusterIdentifier']) ? ', cluster ' . $db['DBClusterIdentifier'] : ''));

        if (!in_array($id, $rdsIds, true)) {
            if ($keep > $days) {
                $ops->note("{$id} keeps backups {$keep} days; if it holds scorecard data, re-run with --rds={$id}");
            }
            continue;
        }

        if (isset($db['DBClusterIdentifier'])) {
            $ops->note("{$id} is in cluster {$db['DBClusterIdentifier']}: its retention is the cluster's (--rds-cluster)");
        } elseif ($keep > $days) {
            $ops->change("set {$id} automated backup retention {$keep} -> {$days} days",
                ['rds', 'modify-db-instance', '--db-instance-identifier', $id, '--backup-retention-period', (string) $days, '--apply-immediately']);
        } else {
            $ops->ok("{$id}: automated backups kept {$keep} day(s)");
        }

        foreach ($ops->read(['rds', 'describe-db-snapshots', '--db-instance-identifier', $id, '--snapshot-type', 'manual'])['DBSnapshots'] ?? [] as $snap) {
            $age = Ops::ageDays((string) ($snap['SnapshotCreateTime'] ?? 'now'));
            if ($age >= $days) {
                $ops->change(sprintf('delete manual snapshot %s (%.0f days old)', $snap['DBSnapshotIdentifier'], $age),
                    ['rds', 'delete-db-snapshot', '--db-snapshot-identifier', (string) $snap['DBSnapshotIdentifier']]);
            } else {
                $ops->note(sprintf('manual snapshot %s (%.0f days old) has no expiry of its own: this script deletes it at day %d when run daily with --apply',
                    $snap['DBSnapshotIdentifier'], $age, $days));
            }
        }
    }

    foreach (array_diff($rdsIds, array_column($instances, 'DBInstanceIdentifier')) as $missing) {
        $ops->problem("RDS database {$missing} not found in {$ops->region}");
    }

    foreach ($clusters as $cid) {
        $cluster = ($ops->read(['rds', 'describe-db-clusters', '--db-cluster-identifier', $cid])['DBClusters'] ?? [])[0] ?? null;
        if ($cluster === null) {
            $ops->problem("RDS cluster {$cid} not found");
            continue;
        }
        $keep = (int) ($cluster['BackupRetentionPeriod'] ?? 0);
        if ($keep > $days) {
            $ops->change("set cluster {$cid} backup retention {$keep} -> {$days} days",
                ['rds', 'modify-db-cluster', '--db-cluster-identifier', $cid, '--backup-retention-period', (string) $days, '--apply-immediately']);
        } else {
            $ops->ok("cluster {$cid}: automated backups kept {$keep} day(s)");
        }
        foreach ($ops->read(['rds', 'describe-db-cluster-snapshots', '--db-cluster-identifier', $cid, '--snapshot-type', 'manual'])['DBClusterSnapshots'] ?? [] as $snap) {
            $age = Ops::ageDays((string) ($snap['SnapshotCreateTime'] ?? 'now'));
            if ($age >= $days) {
                $ops->change(sprintf('delete cluster snapshot %s (%.0f days old)', $snap['DBClusterSnapshotIdentifier'], $age),
                    ['rds', 'delete-db-cluster-snapshot', '--db-cluster-snapshot-identifier', (string) $snap['DBClusterSnapshotIdentifier']]);
            } else {
                $ops->note(sprintf('cluster snapshot %s has no expiry of its own: this script deletes it at day %d when run daily with --apply', $snap['DBClusterSnapshotIdentifier'], $days));
            }
        }
    }
}, $rdsIds !== [] || $clusters !== []);

/* ------------------------------------------------------------------ */
/* EBS (a database on the server's own disk)                            */
/* ------------------------------------------------------------------ */

$ops->heading('EBS — snapshots of the server\'s disk');
$try('EBS lifecycle policies', static function () use ($ops, $days): void {
    foreach ($ops->read(['dlm', 'get-lifecycle-policies'])['Policies'] ?? [] as $summary) {
        $policy = $ops->read(['dlm', 'get-lifecycle-policy', '--policy-id', (string) $summary['PolicyId']])['Policy'] ?? [];
        foreach ($policy['PolicyDetails']['Schedules'] ?? [] as $schedule) {
            $retain = $schedule['RetainRule'] ?? [];
            $text   = isset($retain['Count']) ? 'the last ' . $retain['Count'] . ' snapshot(s)'
                : (isset($retain['Interval']) ? $retain['Interval'] . ' ' . strtolower((string) $retain['IntervalUnit']) : 'forever');
            $ops->line(sprintf('  snapshot policy %s (%s) "%s": keeps %s', $summary['PolicyId'], $summary['State'] ?? '?', $schedule['Name'] ?? '?', $text));

            $tooLong = isset($retain['Interval']) && match (strtoupper((string) $retain['IntervalUnit'])) {
                'DAYS' => (int) $retain['Interval'] > $days,
                'WEEKS' => (int) $retain['Interval'] * 7 > $days,
                default => true,
            };
            if ($tooLong || $retain === []) {
                $ops->note("policy {$summary['PolicyId']} may keep snapshots {$days}+ days; if it covers the scorecard server, set its retain rule to {$days} days or fewer");
            } elseif (isset($retain['Count'])) {
                $ops->note("policy {$summary['PolicyId']} keeps by COUNT: the oldest is count × schedule interval old — check that is under 30 days");
            }
        }
    }
});
$try('EBS snapshots', static function () use ($ops, $volumes, $days): void {
    foreach ($volumes as $volume) {
        $snaps = $ops->read(['ec2', 'describe-snapshots', '--owner-ids', 'self', '--filters', 'Name=volume-id,Values=' . $volume])['Snapshots'] ?? [];
        $ops->line(sprintf('  volume %s: %d snapshot(s)', $volume, count($snaps)));

        foreach ($snaps as $snap) {
            $age = Ops::ageDays((string) $snap['StartTime']);
            if ($age >= $days) {
                $ops->change(sprintf('delete snapshot %s of %s (%.0f days old)', $snap['SnapshotId'], $volume, $age),
                    ['ec2', 'delete-snapshot', '--snapshot-id', (string) $snap['SnapshotId']]);
            }
        }
        if ($snaps !== []) {
            $ops->note("EBS snapshots have no expiry of their own: keep a lifecycle policy (or this script, daily) deleting them before day {$days}");
        }
    }
}, $volumes !== []);

/* ------------------------------------------------------------------ */
/* Lightsail                                                           */
/* ------------------------------------------------------------------ */

$ops->heading('Lightsail — instance and database snapshots');
$try('Lightsail', static function () use ($ops, $lightsail, $lightDbs, $days): void {
    $instances = $ops->read(['lightsail', 'get-instances'])['instances'] ?? [];
    foreach ($instances as $instance) {
        $ops->line('  instance ' . $instance['name']);
    }
    $databases = $ops->read(['lightsail', 'get-relational-databases'])['relationalDatabases'] ?? [];
    foreach ($databases as $db) {
        $ops->line(sprintf('  database %s: automatic backups (Lightsail keeps 7 days)', $db['name']));
    }

    foreach ($lightsail as $name) {
        $ops->note("{$name}: Lightsail automatic snapshots are kept 7 days by AWS — within the limit");
        foreach ($ops->read(['lightsail', 'get-instance-snapshots'])['instanceSnapshots'] ?? [] as $snap) {
            if (($snap['fromInstanceName'] ?? '') !== $name) {
                continue;
            }
            $age = Ops::ageDays((string) $snap['createdAt']);
            if ($age >= $days) {
                $ops->change(sprintf('delete instance snapshot %s (%.0f days old)', $snap['name'], $age),
                    ['lightsail', 'delete-instance-snapshot', '--instance-snapshot-name', (string) $snap['name']]);
            } else {
                $ops->note(sprintf('manual instance snapshot %s has no expiry of its own: this script deletes it at day %d when run daily with --apply', $snap['name'], $days));
            }
        }
    }

    foreach ($lightDbs as $name) {
        foreach ($ops->read(['lightsail', 'get-relational-database-snapshots'])['relationalDatabaseSnapshots'] ?? [] as $snap) {
            if (($snap['fromRelationalDatabaseName'] ?? '') !== $name) {
                continue;
            }
            $age = Ops::ageDays((string) $snap['createdAt']);
            if ($age >= $days) {
                $ops->change(sprintf('delete database snapshot %s (%.0f days old)', $snap['name'], $age),
                    ['lightsail', 'delete-relational-database-snapshot', '--relational-database-snapshot-name', (string) $snap['name']]);
            } else {
                $ops->note(sprintf('manual database snapshot %s has no expiry of its own: this script deletes it at day %d when run daily with --apply', $snap['name'], $days));
            }
        }
    }
}, $lightsail !== [] || $lightDbs !== []);

if (!$anyNamed) {
    $ops->line('');
    $ops->line('Nothing was named, so nothing would be changed. Name the plan, vault, database, volume or Lightsail');
    $ops->line('resource that holds the scorecard data (options at the top of this file) and run again.');
    $ops->line('');
    $ops->line('Result: survey only — C3 is NOT verified until the scorecard\'s resources are named.');
    exit(1);
}

$ops->finish();
