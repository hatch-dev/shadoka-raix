<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services;

/**
 * The five required identity fields, checked.
 *
 * A direct port of the reference's nameError / emailError / phoneError, with one
 * change that is the point of porting them: they ran in the browser only, so
 * anything that bypassed the form bypassed the rules entirely. These run on the
 * server, and the browser runs the same checks again for the sake of showing an
 * error next to the field rather than after a round trip.
 *
 * What these checks are NOT: verification. Nothing is confirmed against any
 * external source. They catch what is plainly not a name, not an address and not
 * a phone number, plus the placeholder and disposable domains that mean the
 * director did not want to be reachable — which for a research instrument that
 * exists partly to start a conversation is the case worth catching.
 *
 * Every message is the reference's own wording.
 */
final class RespondentIdentity
{
    /**
     * The reference's EMAIL_RE, verbatim.
     *
     * Deliberately not filter_var(FILTER_VALIDATE_EMAIL), which accepts
     * `a@b` — no dot, no TLD — and is therefore looser than the form the
     * browser enforced. assess.js carries the same pattern, and
     * identity_test.php checks the two have not drifted.
     */
    public const EMAIL_PATTERN =
        '/^[^\s@,;:<>()[\]\\\\"]+@[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,24}$/';

    /**
     * Domains that mean "do not contact me".
     *
     * Disposable inboxes and the reserved example domains. `.test`, `.invalid`,
     * `.example` and `.local` are refused by suffix as well, which is also what
     * keeps a SEEDED record's address — deliberately `*.example.invalid` — from
     * ever being mistaken for one a person could be reached at.
     */
    public const BLOCKED_DOMAINS = [
        'example.com', 'example.org', 'example.net', 'test.com', 'email.com',
        'mailinator.com', 'yopmail.com', 'guerrillamail.com', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'trashmail.com', 'sharklasers.com',
        'throwawaymail.com', 'getnada.com', 'maildrop.cc', 'dispostable.com',
        'fakeinbox.com',
    ];

    public const BLOCKED_SUFFIXES = ['.test', '.invalid', '.example', '.local'];

    private function __construct()
    {
    }

    /**
     * Check all five required fields at once.
     *
     * @param array<string, mixed> $profile
     * @return array<string, string> field => message, empty when everything passes
     */
    public static function check(array $profile, array $sizes = []): array
    {
        $errors = [];

        foreach ([
            'director_name'   => self::nameError((string) ($profile['director_name'] ?? '')),
            'director_email'  => self::emailError((string) ($profile['director_email'] ?? '')),
            'director_phone'  => self::phoneError((string) ($profile['director_phone'] ?? '')),
            // Required since 25 Sep 2026, at the user's request (optional in the reference).
            'seat_role'       => self::roleError((string) ($profile['seat_role'] ?? '')),
            /*
             * Board and company size became OPTIONAL on 25 Sep 2026, at the
             * user's request (the reference requires both). A size that IS given
             * must still be one of the listed bands.
             */
            'company_size'   => trim((string) ($profile['company_size'] ?? '')) === ''
                ? null
                : self::sizeError((string) $profile['company_size'], $sizes),
        ] as $field => $message) {
            if ($message !== null) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    public static function nameError(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) < 2) {
            return 'Enter your full name.';
        }

        if (mb_strlen($value) > 120) {
            return 'That name is too long.';
        }

        if (!preg_match('/\p{L}/u', $value)) {
            return 'Enter your name in letters.';
        }

        if (preg_match('/\d/', $value)) {
            return 'A name should not contain digits.';
        }

        /*
         * No two-part rule. The reference demanded a first AND last name, which
         * refuses every mononym -- common in Indonesia, in parts of India, and in
         * any name written in Han, kana or Hangul, where the whole name is one
         * unspaced part. Its CJK carve-out still left everyone else out. The rule
         * was dropped from the Enterprise Scorecard for that reason and is left
         * out here for the same one. Nothing on this form is verified, and a name
         * with one part is not less real.
         */
        return null;
    }

    public static function emailError(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return 'Enter an email address.';
        }

        if (mb_strlen($value) > 254 || str_contains($value, '..') || !preg_match(self::EMAIL_PATTERN, $value)) {
            return 'That does not look like a valid email address.';
        }

        $domain = strtolower((string) substr($value, (int) strrpos($value, '@') + 1));

        if (in_array($domain, self::BLOCKED_DOMAINS, true)) {
            return 'Use an address you actually read. Disposable and placeholder domains are not accepted.';
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return 'Use an address you actually read. Disposable and placeholder domains are not accepted.';
            }
        }

        return null;
    }

    public static function phoneError(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return 'Enter a phone number.';
        }

        if (preg_match('/[A-Za-z]/', $value)) {
            return 'Enter digits only, with an optional leading +.';
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (strlen($digits) < 7) {
            return 'That number is too short. Include the area or city code.';
        }

        if (strlen($digits) > 15) {
            return 'That number is too long. Check the digits.';
        }

        // 1111111 and 0000000 are what gets typed to get past a required field.
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return 'Enter a real phone number.';
        }

        return null;
    }

    /** Only presence is checked here; the controller checks it is a listed role. */
    public static function roleError(string $value): ?string
    {
        return trim($value) === '' ? 'Select your role on this board.' : null;
    }

    public static function organisationError(string $value): ?string
    {
        return mb_strlen(trim($value)) < 2
            ? 'Enter the name of the organization whose board you sit on.'
            : null;
    }

    /**
     * The size band must be one the instrument offers.
     *
     * Checked against the published instrument's own list rather than a copy
     * here, so a client cannot invent a band and put a value in the size
     * breakdown that nothing else uses. An empty list means the caller could not
     * read the instrument, and only the "required" half of the rule applies.
     *
     * @param list<string> $sizes
     */
    public static function sizeError(string $value, array $sizes = []): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return 'Select the organization’s size.';
        }

        if ($sizes !== [] && !in_array($value, $sizes, true)) {
            return 'Organization size is not one of the listed options.';
        }

        return null;
    }
}
