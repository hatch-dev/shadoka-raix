<?php

declare(strict_types=1);

/**
 * Director Scorecard (Public) — bring the admin console in step with dsp_records.
 *
 *   php apps/raix-director-scorecard-public/scripts/mirror-records.php
 *
 * The console keeps itself in step as records change (Services/RecordMirror).
 * This is for the moments it cannot have: the first deployment of the mirror,
 * or after restoring a database. It
 *
 *   1. gives every console demo row that has no record one, so the v14 page's
 *      admin views show the same demo set;
 *   2. mirrors every record that has no console copy (restricted real records
 *      excepted);
 *   3. drops every console copy whose record is gone or restricted.
 *
 * Safe to re-run: nothing that is already in step is rewritten. CLI only.
 * Prints counts, never a record.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../platform/bootstrap.php';

use Apps\RaixDirectorScorecardPublic\Services\RecordMirror;
use Platform\Core\AppRegistry;

$mirror = new RecordMirror(AppRegistry::get('raix-director-scorecard-public'));

$adopted  = $mirror->adoptSynthetic();
$mirrored = $mirror->backfill();
$pruned   = $mirror->prune();

echo sprintf("adopted %d console demo row(s) as records; mirrored %d record(s); dropped %d stale copy(ies)\n", $adopted, $mirrored, $pruned);
