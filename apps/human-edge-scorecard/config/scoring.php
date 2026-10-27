<?php

declare(strict_types=1);

/**
 * The Human Edge Scorecard's scoring rules — the page's own constants.
 *
 * The band cut points are duplicated into each published instrument version in
 * he_rungs, so historical submissions keep scoring the way they were scored at
 * the time. This file is the default applied when a new version is created.
 */
return [
    /*
     * 'mean' — the level is the band of the OVERALL AVERAGE of the skill
     * scores (each the mean of its three answers). The weakest skill is stored
     * too, but it does not cap the level.
     */
    'rule' => 'mean',

    // BAND_EDGES: rung value => [inclusive min, exclusive max]. null = open-ended.
    'bands' => [
        1 => [null, 1.80],   // Rare
        2 => [1.80, 2.60],   // Occasional
        3 => [2.60, 3.40],   // Developing
        4 => [3.40, 4.20],   // Consistent
        5 => [4.20, null],   // Habitual
    ],

    // CORR_MIN_N: correlations are not reported below this many paired
    // observations. The scatter still renders.
    'correlation_min_n' => 30,

    // SEG_MIN: segment groups smaller than this are not shown.
    'segment_min_n' => 5,
];
