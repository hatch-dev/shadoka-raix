<?php

declare(strict_types=1);

/**
 * What a director is offered at the end of the scorecard.
 *
 * The commitments are the instrument's closing argument: a scorecard with
 * nothing attached to it changes nothing before the next board meeting. They
 * are config rather than hard-coded strings so the wording can be revised
 * without a deploy, and `key` is what lands in the database — treat it as
 * frozen once submissions reference it.
 */
return [
    'commitments' => [
        [
            'key'   => 'register',
            'label' => 'I will put the question on my floor competency in writing before the '
                     . 'next meeting and ask for the answer to be minuted.',
        ],
        [
            'key'   => 'agenda',
            'label' => 'I will ask for the AI system inventory, including AI inside software '
                     . 'the company bought.',
        ],
        [
            'key'   => 'gate',
            'label' => 'I will not vote for an item that depends on AI until my floor '
                     . 'competency has an answer I could check myself.',
        ],
    ],

    'commitments_note' => 'Pick at least one. A scorecard with nothing attached to it '
                        . 'changes nothing before the next meeting.',

    // Shown against the floor score on the results page.
    'floor_note' => 'Your floor is {competency} at {floor}. This is the competency to spend '
                  . 'the next quarter on, however strong the rest of the row looks.',

    // A benchmark is withheld until the dataset holds at least this many REAL
    // scorecards. Synthetic records are never used as peers.
    'benchmark_min_peers' => 5,

    /*
     * The line along the foot of the downloaded PDF.
     *
     * {copyright} and {levels} are filled in: the level legend is built from
     * the published instrument's rungs, so a PDF can never describe a ladder
     * the instrument no longer uses.
     */
    'pdf_footnote' => '{copyright} · Four levels: {levels}. This is a self-assessment. '
                    . 'Scores describe a director’s conduct, not fiduciary standing.',

    /*
     * Shown on the PDF's seat line when a director left sector or company size
     * blank. Both are genuinely optional, and the database keeps them NULL —
     * this is a display default, so the line reads as a sentence rather than
     * trailing off. Role and tenure are deliberately NOT given one: an unstated
     * seat simply drops out of the line.
     */
    'unspecified_label' => 'Not specified',

    /*
     * Shown wherever a scorecard with no board name is named.
     *
     * The single-file build wrote this string into the record at capture
     * (`org: g('f-org').trim() || 'Anonymous'`), so an unnamed board and a
     * board actually called "Anonymous" became the same value and could never
     * be told apart again. Here the column stays NULL and the label is applied
     * on the way out — same words on screen, without losing the distinction in
     * the data.
     */
    'anonymous_board_label' => 'Anonymous',
];
