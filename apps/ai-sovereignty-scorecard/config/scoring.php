<?php

declare(strict_types=1);

/**
 * The AI Sovereignty Scorecard's scoring rules.
 *
 * These are the app's rules, not the platform's. The Director Scorecard in the
 * folder next door aggregates by the FLOOR — its level is its weakest
 * competency — and this instrument deliberately does not, which is exactly why
 * these live in the app rather than in config/.
 *
 * The band cut points are duplicated into each published instrument version in
 * sov_rungs, so historical submissions keep scoring the way they were scored at
 * the time. This file is the default applied when a new version is created.
 */
return [
    /*
     * The rule that sets an organisation's level.
     *
     * 'mean' — the level is the band of the OVERALL AVERAGE across all sixteen
     * answers. The floor, the weakest dimension, is computed and stored too,
     * but it does not cap the level: it is reported beside the score and is
     * what the analytics screens are built around.
     */
    'rule' => 'mean',

    /*
     * Band cut points, applied to a score on the 1.00–5.00 scale.
     * Read as: rung value => [inclusive min, exclusive max]. null = open-ended.
     *
     * Five equal bands of 0.80. Taken from the reference's BAND_EDGES and
     * verified against it by instrument_integrity_test.php, because a cut point
     * retyped a tenth out re-bands a slice of every dataset silently.
     */
    'bands' => [
        1 => [null, 1.80],   // Undeveloped
        2 => [1.80, 2.60],   // Emerging
        3 => [2.60, 3.40],   // Developing
        4 => [3.40, 4.20],   // Established
        5 => [4.20, null],   // Embedded
    ],

    /*
     * The share of marks at Emerging above which a dimension pane warns.
     *
     * The reference showed its "real but not held in place" box at more than a
     * third: activity that exists but is carried by individuals rather than by
     * the organisation.
     */
    'emerging_warning_share' => 0.35,

    // Correlations are not reported below this many paired observations. The
    // scatter still renders — showing how thin the data is, is the point.
    'correlation_min_n' => 30,

    /*
     * New scorecards allowed per hour from one hashed client address.
     *
     * Generous on purpose: several executives of the same organisation filling
     * this in from one office network is the normal case, not an attack. It
     * exists to stop a script filling the dataset.
     */
    'start_rate_limit' => 20,
];
