<?php

declare(strict_types=1);

/**
 * The Director Scorecard's scoring rules.
 *
 * These are the app's rules, not the platform's. Another instrument on this
 * platform may aggregate differently and need not use the floor rule at all,
 * which is why these live in the app rather than in config/.
 *
 * The band cut points are duplicated into each published instrument version in
 * ds_rungs, so historical submissions keep scoring the way they were scored at
 * the time. This file is the default applied when a new version is created.
 */
return [
    /*
     * The rule that sets a director's level.
     *
     * 'floor' — the level is the band of the WEAKEST scored competency, never
     * the average. This is the whole point of the instrument: a rising average
     * with a static floor means the board got better at what it was already
     * comfortable asking about.
     */
    'rule' => 'floor',

    // Band cut points, applied to a competency mean of 1.00–4.00.
    // Read as: rung value => [inclusive min, exclusive max]. null = open-ended.
    'bands' => [
        1 => [null, 1.75],   // Unaware
        2 => [1.75, 2.75],   // Aware
        3 => [2.75, 3.75],   // Practiced
        4 => [3.75, null],   // Systematic
    ],

    // A competency below this is re-scored quarterly rather than annually.
    'practiced_threshold' => 2.75,

    // Re-score cadence, in months. 'incident' is immediate by definition.
    'cadence' => [
        'full'            => 12,
        'below_practiced' => 3,
    ],

    // Correlations are not reported below this many paired observations. The
    // scatter still renders — showing how thin the data is, is the point.
    'correlation_min_n' => 30,

    // Marks at or above this invite an optional "when, and where?" note.
    'evidence_prompt_from' => 3,

    /*
     * New scorecards allowed per hour from one hashed client address.
     *
     * Generous on purpose: several directors of the same board sitting in the
     * same room, behind one NAT, is the normal case this instrument is used in,
     * not an attack. It exists to stop a script filling the dataset.
     */
    'start_rate_limit' => 20,
];
