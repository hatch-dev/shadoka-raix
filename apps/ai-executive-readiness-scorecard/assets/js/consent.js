/**
 * The "Before you begin" gate.
 *
 * One definition, because two pages open it: the hero and the nav open it in
 * place when you press "Start your scorecard" — which is what the reference's
 * `startAssessment()` does, showing the dialog over the screen you are already
 * on and only navigating once it is accepted — and the assessment page opens it
 * for anyone who arrives at /assess directly.
 *
 * Nothing here decides anything. Acceptance is a fact the SERVER records: the
 * scorecard's first write refuses to happen without it and stores the timestamp
 * and the URL of the terms that were shown. This dialog is how a person is asked
 * and how the answer is carried to the form.
 */

import { esc, modal, closeModal } from './ui.js';

/**
 * Open the gate.
 *
 * @param {object} consent   the instrument payload's `consent` block
 * @param {object} options
 * @param {string|null} options.cancelHref  where Cancel goes; null makes it
 *        close the dialog and stay put, which is what the reference does when
 *        the gate is opened over a page that is worth staying on
 * @param {Function} options.onAccept       called once the box is ticked and
 *        Continue is pressed
 */
export function openConsentDialog(consent, { cancelHref = null, onAccept }) {
  const block = consent || {};

  /*
   * The two link texts, turned into links.
   *
   * The paragraphs are escaped FIRST and the anchors substituted after, so the
   * wording stays plain text from config while still carrying the two links the
   * reference has. Nothing here is respondent input.
   */
  const withLinks = (text) =>
    esc(text)
      .replace(
        'Terms of Use',
        '<a href="' + esc(block.terms_url || '#') +
          '" target="_blank" rel="noopener noreferrer">Terms of Use</a>',
      )
      .replace(
        'Privacy Policy',
        '<a href="' + esc(block.privacy_url || '#') +
          '" target="_blank" rel="noopener noreferrer">Privacy Policy</a>',
      );

  const cancel = cancelHref
    ? '<a class="btn quiet sm" href="' + esc(cancelHref) + '">Cancel</a>'
    : '<button class="btn quiet sm" type="button" data-close-modal>Cancel</button>';

  const panel = modal(
    '<h3>Before you begin</h3>' +
      '<div class="muted" style="font-size:15px;line-height:1.55">' +
      (block.paragraphs || [])
        .map((paragraph) => '<p style="margin-bottom:10px">' + withLinks(paragraph) + '</p>')
        .join('') +
      '</div>' +
      '<div class="checks"><label><input type="checkbox" id="consentBox">' +
      '<span>' +
      esc(block.checkbox || 'I agree.') +
      '</span></label></div>' +
      '<div class="nav-row">' +
      cancel +
      '<button class="btn gold sm" id="consentGo" type="button" disabled>Continue</button>' +
      '</div>',
  );

  const box = panel.querySelector('#consentBox');
  const go = panel.querySelector('#consentGo');

  box.addEventListener('change', () => {
    go.disabled = !box.checked;
  });

  go.addEventListener('click', () => {
    if (!box.checked) return;
    onAccept();
  });

  return panel;
}

export { closeModal };
