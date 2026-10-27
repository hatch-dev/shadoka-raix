<?php

declare(strict_types=1);

/**
 * The AI Executive Readiness Scorecard's scoring rules.
 *
 * These are the app's rules, not the platform's. The band cut points are
 * duplicated into each published instrument version in exr_rungs, so historical
 * submissions keep scoring the way they were scored at the time. This file is
 * the default applied when a new version is created.
 */
return [
    /*
     * 'paired-mean' — each scope (self, leadership) is scored on its own. A
     * dimension score is the mean of its three ratings in that scope; the
     * overall score is the mean of the scope's twelve ratings, and the level is
     * the band that overall mean falls in. The floor (lowest dimension) is
     * computed and reported but does not cap the level. The difference is self
     * minus leadership, computed before rounding.
     */
    'rule' => 'paired-mean',

    /*
     * Band cut points, applied to a score on the 1.00–5.00 scale.
     * Read as: rung value => [inclusive min, exclusive max]. null = open-ended.
     *
     * Five equal bands of 0.80, from the reference's BAND_EDGES and verified
     * against it by instrument_integrity_test.php.
     */
    'bands' => [
        1 => [null, 1.80],   // Undeveloped
        2 => [1.80, 2.60],   // Emerging
        3 => [2.60, 3.40],   // Developing
        4 => [3.40, 4.20],   // Established
        5 => [4.20, null],   // Embedded
    ],

    // The two sets of ratings every question collects, in questionnaire order.
    'scopes' => ['self', 'leadership'],

    // Correlations are not reported below this many paired observations. The
    // scatter still renders — showing how thin the data is, is the point.
    'correlation_min_n' => 30,

    /*
     * New scorecards allowed per hour from one hashed client address.
     *
     * Generous on purpose: several executives of the same organisation filling
     * this in from one office network is the normal case, not an attack.
     */
    'start_rate_limit' => 20,
];
