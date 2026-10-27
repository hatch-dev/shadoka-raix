<?php

declare(strict_types=1);

use Platform\Core\Migration;

/**
 * Throttling for endpoints with no account behind them.
 *
 * Failed admin sign-ins are throttled on the admin_users row itself, because
 * there is an account to attach the count to. The public submission endpoints
 * have no account by design, so they are throttled by hashed IP here.
 *
 * Keys are hashes, never raw addresses.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('rate_limits', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `bucket` VARCHAR(64) NOT NULL COMMENT 'what is being limited, e.g. submission.start',
            `identifier` CHAR(64) NOT NULL COMMENT 'hashed IP or token — never a raw address',
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `rate_limits_bucket_identifier` (`bucket`, `identifier`),
            KEY `rate_limits_expires_at` (`expires_at`)
        SQL);
    }

    public function down(): void
    {
        $this->drop('rate_limits');
    }
};
