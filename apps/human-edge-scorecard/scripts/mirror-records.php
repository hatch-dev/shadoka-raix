<?php

declare(strict_types=1);

/**
 * Human Edge Scorecard — bring the admin console in step with he_records.
 *
 *   php apps/human-edge-scorecard/scripts/mirror-records.php
 *
 * The console keeps itself in step as records change (Services/RecordMirror),
 * and every console screen runs backfill() before it reads. This is for the
 * moments neither can have: the first deployment of the console tables, or
 * after restoring a database. It
 *
 *   1. mirrors every record that has no console copy (restricted real records
 *      excepted);
 *   2. drops every console copy whose record is gone or restricted.
 *
 * Safe to re-run: nothing that is already in step is rewritten. CLI only.
 * Prints counts, never a record.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../platform/bootstrap.php';

use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Platform\Core\AppRegistry;

$mirror = new RecordMirror(AppRegistry::get('human-edge-scorecard'));

$mirrored = $mirror->backfill();
$pruned   = $mirror->prune();

echo sprintf("mirrored %d record(s); dropped %d stale copy(ies)\n", $mirrored, $pruned);
