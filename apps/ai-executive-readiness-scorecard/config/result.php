<?php

declare(strict_types=1);

/**
 * The words around the questionnaire: the consent gate, and the few sentences
 * the results, the PDFs and the exports repeat.
 *
 * Wording lives here rather than in a view so it can be revised without a
 * deploy. The instrument's own wording — dimensions, questions, anchors, level
 * names, and the three sentences the reference keeps beside its instrument
 * (INSTRUCTIONS, COMPARISON_NOTE, DISCLAIMER) — is NOT here: those are seeded
 * onto the instrument row from database/seeds/data/instrument-v1.json.
 */
return [
    /*
     * The consent gate, word for word the reference's `consentGate()`.
     *
     * Accepting it is written onto the record (`consent_agreed_at` and the URL of
     * the terms shown), and the server refuses to start a scorecard without it.
     */
    'consent' => [
        'terms_url'   => 'https://shadoka.com/terms/',
        'privacy_url' => 'https://shadoka.com/privacy/',
        'paragraphs'  => [
            'This scorecard is operated by {owner}. Your use of it is subject to the '
                . 'Terms of Use and the Privacy Policy.',
            'To complete the scorecard you will be asked for your name, email address, '
                . 'telephone number, and the name and size of your organization, together '
                . 'with your two ratings for each of the 12 questions. {owner} will collect '
                . 'and process this information for two purposes only: (1) educational '
                . 'research into AI executive readiness, in which results are analyzed and '
                . 'published only in aggregate, so that no individual or organization can be '
                . 'identified; and (2) preparing for and conducting discussions with your '
                . 'organization about its own results. Your information will not be sold, '
                . 'will not be shared with third parties for their own purposes, and will '
                . 'not be used for marketing unrelated to this scorecard.',
            'You may ask to see, correct, or delete the information you provide at any time '
                . 'by contacting {owner} as set out in the Privacy Policy. This scorecard is '
                . 'a self-assessment. Scores describe the respondent’s stated practices and '
                . 'perceptions of the organization’s leaders and do not constitute an audit, '
                . 'legal advice, or professional advice.',
        ],
        'checkbox' => 'I have read and agree to the Terms of Use, and I consent to the '
                    . 'collection and use of my information for the purposes described above.',
    ],

    /*
     * The line along the foot of every PDF, the reference's pdfFooter().
     */
    'pdf_footnote' => 'Self-assessment. Scores describe stated practices and perceptions. They do '
                    . 'not constitute an audit, legal advice, or professional advice.',

    /*
     * A blank sector is stored as NULL and shown as this — the reference wrote
     * `sector: g('f-sector') || 'Not specified'` at capture, which made "left it
     * blank" indistinguishable from a sector of that name forever after.
     */
    'unspecified_label' => 'Not specified',

    // Shown wherever a respondent is named but gave no name. Name is required,
    // so this appears only on a row written some other way.
    'anonymous_label' => 'Respondent',
];
