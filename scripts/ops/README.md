# Production retention setup (directive C3 and C6)

These are the production steps for the scorecards' privacy directives (AI Sovereignty v6, Enterprise v32, Director Scorecard (Public) v13):

- **C3**: delete every record 24 months after completion, and keep backups less than 30 days.
- **C6**: keep request logs 30 days.

They cannot be done from a development machine, because they act on the production server and the production AWS account. Run them there, in the order below. Each step can be re-run safely.

The scripts never change anything without `--apply`. Run them once without it and read what they would do.

Requirements:

- PHP CLI, the same PHP that runs the site.
- For steps 3 and 4, the [AWS CLI v2](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html), signed in to the production account. Use an instance role, or `aws configure` with a named staff user.
- The region is the United States region the scorecard data is in (C1). The scripts refuse any other region.

## 1. The daily deletion job (C3)

```sh
sudo cp scripts/ops/server/raix-retention.cron /etc/cron.d/raix-retention
sudo chmod 644 /etc/cron.d/raix-retention
sudoedit /etc/cron.d/raix-retention     # check RAIX=, PHP= and the user column (www-data / apache)
```

The cron file runs each app's `scripts/retention.php` every night at 03:20, 03:25 and 03:30.

Each run does two things:

- It deletes every record completed more than 24 months ago, whole.
- It trims the app's logs to 30 days and redacts email addresses in what it keeps.

Output goes to `storage/logs/retention-YYYY-MM-DD.log`, one file per day, deleted whole after 30 days.

To preview without deleting anything:

```sh
php apps/ai-sovereignty-scorecard/scripts/retention.php --dry-run
```

To confirm the job ran, the morning after:

```sh
php scripts/ops/check-retention.php     # exit 0 = every app ran in the last 26 hours
```

A monitor can run that check daily and alert on exit 1.

## 2. The server's own logs (C6)

Apache's access and error logs record visitors' IP addresses. Keep them 30 days:

```sh
sudo cp scripts/ops/server/logrotate-raix-apache /etc/logrotate.d/raix-apache   # read its header first
sudo logrotate --debug /etc/logrotate.d/raix-apache                             # dry run
```

If the distribution already rotates Apache logs (`/etc/logrotate.d/apache2` or `httpd`), set `daily` and `rotate 30` in that file instead of adding this one.

PHP's error log should be covered too, if it is a separate file.

You can also have the daily job trim these logs itself: set `SERVER_LOG_DIR` and `PHP_LOG_DIR` in `.env` (see `config/logging.php`). The cron user must then be able to write them.

## 3. Backups and snapshots (C3)

First a survey. It changes nothing and lists every backup plan, vault, RDS database, EBS snapshot policy and Lightsail resource, with how long each keeps data:

```sh
php scripts/ops/aws-backup-retention.php --region=us-east-1
```

Then name the resources that hold scorecard data: the database, or the server's disk if MySQL runs on the server. Only named resources are changed, because the account may hold other things with their own backup needs.

```sh
php scripts/ops/aws-backup-retention.php --region=us-east-1 --rds=<db-id> --backup-plan=<id> --vault=<name>          # dry run
php scripts/ops/aws-backup-retention.php --region=us-east-1 --rds=<db-id> --backup-plan=<id> --vault=<name> --apply
```

The options can be repeated. They are `--backup-plan`, `--vault`, `--rds`, `--rds-cluster`, `--volume`, `--lightsail` and `--lightsail-db`.

With `--apply`, the script:

- sets retention to 29 days on the named resources;
- deletes snapshots and recovery points already 29 days old or older.

Manual snapshots never expire by themselves. If you take any, schedule this script daily (the commented line in the cron file) or delete them yourself before day 29.

EBS snapshot policies are reported but not changed. If one covers the scorecard server, set its retain rule to 29 days or fewer.

## 4. AWS request logs (C6)

```sh
php scripts/ops/aws-log-retention.php --region=us-east-1
```

This survey finds where requests are logged:

- CloudFront and load balancer access-log buckets;
- S3 server access-log targets;
- API Gateway execution and access logs;
- Lambda logs.

It also lists every CloudWatch log group with its retention.

Then apply 30 days, either to everything it found, or only to what you name:

```sh
php scripts/ops/aws-log-retention.php --region=us-east-1 --all-discovered --apply
php scripts/ops/aws-log-retention.php --region=us-east-1 --log-group-prefix=/aws/lambda/raix --bucket=my-logs/cloudfront/ --apply
```

- **Log groups** get a 30-day retention policy.
- **Log buckets** get a lifecycle rule that expires objects under the prefix after 30 days, and old versions after 1 day on a versioned bucket. The bucket's other lifecycle rules are kept. S3 applies lifecycle rules once a day.

If CloudFront or other global services log to CloudWatch, run it again with `--region=us-east-1`.

The survey also reports API Gateway stages that log full requests. Those logs contain names and emails, which C6 forbids. Turn that setting off in the console; the script does not change it.

## Verifying

Each script exits 0 only when everything it checked meets the directive. These lines can go into a monitor or an acceptance check (criteria 9, 13 and 14):

```sh
php scripts/ops/check-retention.php
php scripts/ops/aws-backup-retention.php --region=us-east-1 --rds=<db-id> ...
php scripts/ops/aws-log-retention.php --region=us-east-1
```
