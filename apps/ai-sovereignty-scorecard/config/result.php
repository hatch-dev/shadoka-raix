<?php

declare(strict_types=1);

/**
 * What a respondent is shown at the end of the scorecard, and the words the
 * consent gate is built from.
 *
 * Wording lives here rather than in a view so it can be revised without a
 * deploy. The instrument's own wording — dimensions, questions, anchors, level
 * names — is NOT here: that is seeded from
 * database/seeds/data/instrument-v1.json and belongs to a published version.
 */
return [
    /*
     * The consent gate.
     *
     * In the reference this is a dialog that must be accepted before the
     * questionnaire opens, and the acceptance is written onto the record
     * (`A.profile.consent = {agreed, at, terms}`). It is kept as a gate and as
     * a stored fact, because a self-assessment that collects a name, an email
     * address and a phone number for research has to be able to show what the
     * respondent agreed to and when.
     */
    'consent' => [
        'terms_url'   => 'https://shadoka.com/terms/',
        'privacy_url' => 'https://shadoka.com/privacy/',
        'paragraphs'  => [
            'This scorecard is operated by {owner}. Your use of it is subject to the '
                . 'Terms of Use and the Privacy Policy.',
            'To complete the scorecard you will be asked for your name, email address, '
                . 'telephone number, and the name and size of your organization, together '
                . 'with your answers to the sixteen questions. {owner} will collect and '
                . 'process this information for two purposes only: (1) educational research '
                . 'into enterprise AI sovereignty, in which results are analyzed and '
                . 'published only in aggregate, so that no individual or organization can be '
                . 'identified; and (2) preparing for and conducting discussions with your '
                . 'organization about its own results. Your information will not be sold, '
                . 'will not be shared with third parties for their own purposes, and will '
                . 'not be used for marketing unrelated to this scorecard.',
            'You may ask to see, correct, or delete the information you provide at any time '
                . 'by contacting {owner} as set out in the Privacy Policy. This scorecard is '
                . 'a self-assessment. Scores describe an organization’s stated practice and '
                . 'do not constitute an audit, legal advice, or professional advice.',
        ],
        'checkbox' => 'I have read and agree to the Terms of Use, and I consent to the '
                    . 'collection and use of my information for the purposes described above.',
    ],

    /*
     * Shown against the floor on the results page and in the reading of a
     * result. {dimension} and {floor} are substituted; the view escapes each
     * part separately so the dimension name can be emphasised.
     */
    'floor_note' => 'Your weakest dimension is {dimension} at {floor}. It does not cap your '
                  . 'level — the level follows your overall average — but it is the dimension '
                  . 'that will decide what the rest of the row can actually deliver.',

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
    'pdf_footnote' => 'Five levels: {levels}. Self-assessment. {copyright}',

    /*
     * Shown on the results page and the PDF when a respondent left an optional
     * field blank. Both stay NULL in the database; this is a display default so
     * the line reads as a sentence rather than trailing off.
     */
    'unspecified_label' => 'Not specified',

    /*
     * Shown wherever a respondent is named but did not give a name.
     *
     * Name IS required on this instrument, so this should never appear on a
     * real record — it exists for a row written before that rule, and for an
     * import.
     */
    'anonymous_label' => 'Respondent',
];
