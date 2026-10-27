<?php

declare(strict_types=1);

use Apps\RaixDirectorScorecardPublic\Services\RespondentIdentity;
use Apps\RaixDirectorScorecardPublic\Services\Synthetic\SeedGenerator;
use Platform\Core\AppRegistry;
use Platform\Core\Database;
use Platform\Core\Assert;

/**
 * The five required fields, and the reference's exact wording for refusing each.
 *
 * These rules ran in the browser only in the reference build, so anything that
 * bypassed the form bypassed them entirely. They run on the server here, and
 * assess.js runs a copy of them so an error can land beside its field — which
 * means the two can drift. The messages asserted below are the ones that copy
 * must also produce.
 *
 * Nothing here is verification. These checks catch what is plainly not a name,
 * not an address and not a phone number, plus the placeholder and disposable
 * domains that mean the director did not want to be reachable.
 */
return static function (Assert $t): void {
    /* ---------- names ---------- */

    $t->same('a first and last name passes', null, RespondentIdentity::nameError('Dana Whitfield'));
    $t->same('a hyphenated surname passes', null, RespondentIdentity::nameError('Ana Lopez-Reyes'));
    $t->same('an apostrophe is a separator, not a part', null, RespondentIdentity::nameError("Siobhan O'Neill"));
    $t->same('a curly apostrophe too', null, RespondentIdentity::nameError("Siobhan O\u{2019}Neill"));
    $t->same('an initial counts as a part', null, RespondentIdentity::nameError('J. Menon'));

    $t->same('empty is refused', 'Enter your full name.', RespondentIdentity::nameError(''));
    $t->same('one letter is refused', 'Enter your full name.', RespondentIdentity::nameError('D'));
    $t->same('a mononym passes', null, RespondentIdentity::nameError('Dana'));
    $t->same('digits are refused', 'A name should not contain digits.', RespondentIdentity::nameError('Dana 2'));
    $t->same('punctuation alone is refused', 'Enter your name in letters.', RespondentIdentity::nameError('-- --'));
    $t->same('120 characters is the limit', 'That name is too long.', RespondentIdentity::nameError(str_repeat('ab ', 45)));

    /*
     * The names the old two-part rule refused. It demanded a first AND last name,
     * which excludes every mononym, and carried a Han/kana/Hangul carve-out that
     * only covered some of them. The rule is gone; these are the cases that were
     * wrong before it went, kept so it cannot come back unnoticed.
     */
    $t->same('a Han name with no space passes', null, RespondentIdentity::nameError('田中太郎'));
    $t->same('a Hangul name with no space passes', null, RespondentIdentity::nameError('김민준'));
    $t->same('a kana name with no space passes', null, RespondentIdentity::nameError('たなかたろう'));

    /* ---------- email ---------- */

    $t->same('an ordinary address passes', null, RespondentIdentity::emailError('dana.whitfield@meridian-capital.com'));
    $t->same('a subdomain passes', null, RespondentIdentity::emailError('d@mail.corp.meridian.com'));
    $t->same('a plus tag passes', null, RespondentIdentity::emailError('dana+scorecard@meridian.com'));

    $t->same('empty is refused', 'Enter an email address.', RespondentIdentity::emailError(''));
    $t->same(
        'no TLD is refused, unlike filter_var',
        'That does not look like a valid email address.',
        RespondentIdentity::emailError('dana@meridian')
    );
    $t->same(
        'a double dot is refused',
        'That does not look like a valid email address.',
        RespondentIdentity::emailError('dana..w@meridian.com')
    );
    $t->same(
        'no local part is refused',
        'That does not look like a valid email address.',
        RespondentIdentity::emailError('@meridian.com')
    );

    $disposable = 'Use an address you actually read. Disposable and placeholder domains are not accepted.';

    $t->same('a disposable inbox is refused', $disposable, RespondentIdentity::emailError('x@mailinator.com'));
    $t->same('example.com is refused', $disposable, RespondentIdentity::emailError('x@example.com'));
    $t->same('the case does not matter', $disposable, RespondentIdentity::emailError('x@Example.COM'));
    $t->same('a .test domain is refused', $disposable, RespondentIdentity::emailError('x@acme.test'));
    $t->same('a .local domain is refused', $disposable, RespondentIdentity::emailError('x@acme.local'));

    /*
     * The seeder writes every synthetic address on a .invalid domain precisely
     * so that one can never be confused with a contactable person. That only
     * holds if the form would refuse it.
     */
    $t->same(
        'a seeded .invalid address could never be submitted',
        $disposable,
        RespondentIdentity::emailError('dana.whitfield@meridiancapital.example.invalid')
    );

    /* ---------- phone ---------- */

    $t->same('an international number passes', null, RespondentIdentity::phoneError('+1 415 555 0132'));
    $t->same('brackets and dashes pass', null, RespondentIdentity::phoneError('(020) 7946-0958'));
    $t->same('seven digits is the minimum', null, RespondentIdentity::phoneError('5550132'));
    $t->same('fifteen digits is the maximum', null, RespondentIdentity::phoneError('123456789012345'));

    $t->same('empty is refused', 'Enter a phone number.', RespondentIdentity::phoneError(''));
    $t->same(
        'letters are refused',
        'Enter digits only, with an optional leading +.',
        RespondentIdentity::phoneError('call me')
    );
    $t->same(
        'six digits is too short',
        'That number is too short. Include the area or city code.',
        RespondentIdentity::phoneError('555013')
    );
    $t->same(
        'sixteen digits is too long',
        'That number is too long. Check the digits.',
        RespondentIdentity::phoneError('1234567890123456')
    );
    $t->same(
        'a repeated digit is what gets typed to get past a required field',
        'Enter a real phone number.',
        RespondentIdentity::phoneError('1111111')
    );
    $t->same('zeroes too', 'Enter a real phone number.', RespondentIdentity::phoneError('000 000 0000'));

    /* ---------- organisation and size ---------- */

    $t->same('a name passes', null, RespondentIdentity::organisationError('Meridian Capital Group'));
    $t->same(
        'one character is refused',
        'Enter the name of the organization whose board you sit on.',
        RespondentIdentity::organisationError('X')
    );

    $sizes = ['1–50', '51–250', '251–1,000'];

    $t->same('a listed size passes', null, RespondentIdentity::sizeError('51–250', $sizes));
    $t->same('empty is refused', 'Select the organization’s size.', RespondentIdentity::sizeError('', $sizes));
    $t->same(
        'an invented size is refused',
        'Organization size is not one of the listed options.',
        RespondentIdentity::sizeError('about a hundred', $sizes)
    );
    $t->same(
        'with no list to check against, only the required half applies',
        null,
        RespondentIdentity::sizeError('about a hundred', [])
    );

    /* ---------- all at once ---------- */

    $good = RespondentIdentity::check([
        'director_name'   => 'Priya Menon',
        'director_email'  => 'priya.menon@northgate-systems.com',
        'director_phone'  => '+1 415 555 0144',
        'seat_role'       => 'Board Chair',
        'board_name'        => 'Northgate Systems',
        'company_size'      => '51–250',
    ], $sizes);

    $t->same('a complete profile passes', [], $good);

    $bad = RespondentIdentity::check([
        'director_name'   => 'D',
        'director_email'  => 'dana@mailinator.com',
        'director_phone'  => '1111111',
        'board_name'        => '',
        'company_size'      => '',
    ], $sizes);

    // Board and size are optional (25 Sep 2026): blanks there are not failures.
    $t->count('every failing field is reported, not just the first', 4, $bad);
    $t->same('a missing role is refused', 'Select your role on this board.', $bad['seat_role'] ?? null);
    $t->true('a blank board is not a failure', !isset($bad['board_name']));
    $t->true('a blank size is not a failure', !isset($bad['company_size']));
    $t->same(
        'but a size that is given must be a listed band',
        'Organization size is not one of the listed options.',
        RespondentIdentity::check(['company_size' => 'about a hundred'], $sizes)['company_size'] ?? null
    );
    $t->same('keyed by field', 'Enter your full name.', $bad['director_name']);

    /* A missing key is a blank, not a crash. */
    $t->count('an empty payload fails the four required fields', 4, RespondentIdentity::check([], $sizes));
    /* ---------- the browser copy says the same thing ---------- */

    /*
     * assess.js repeats these rules so an error can appear beside its field.
     * Every refusal message, and every blocked domain, must be in it word for
     * word, or a director is told one thing by the page and another by the
     * server for the same input.
     */
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/assess.js');

    foreach ([
        'Enter your full name.', 'That name is too long.', 'Enter your name in letters.',
        'A name should not contain digits.', 'Enter an email address.',
        'That does not look like a valid email address.', $disposable, 'Enter a phone number.',
        'Enter digits only, with an optional leading +.',
        'That number is too short. Include the area or city code.',
        'That number is too long. Check the digits.', 'Enter a real phone number.',
        'Select your role on this board.',
    ] as $message) {
        $t->true('assess.js says: ' . $message, str_contains($js, $message));
    }

    foreach (RespondentIdentity::BLOCKED_DOMAINS as $domain) {
        $t->true('assess.js blocks ' . $domain, str_contains($js, "'" . $domain . "'"));
    }

    $t->true('assess.js has no two-part name rule either', !str_contains($js, 'Enter a first and last name.'));

    /* ---------- synthetic records are unreachable by construction ---------- */

    $app = AppRegistry::get('raix-director-scorecard-public');
    $run = (new SeedGenerator($app, 20260925))->generate(['count' => 3]);

    try {
        $rows = Database::select(
            'SELECT `director_name`, `director_email`, `director_phone`, `board_name`, `company_size`, `consent_agreed_at`
               FROM `dsp_submissions` WHERE `seed_run_id` = ?',
            [$run['seed_run_id']]
        );

        $t->true('the seeder wrote records', count($rows) > 0);

        $bad = array_filter($rows, static fn (array $r): bool =>
            !str_ends_with($r['director_email'], '.example.invalid')
            || !preg_match('/^\+1 555 01\d{2}$/', $r['director_phone'])
            || RespondentIdentity::nameError($r['director_name']) !== null
            || $r['board_name'] === ''
            || $r['company_size'] === ''
            || $r['consent_agreed_at'] !== null);

        $t->same('every synthetic identity is fictional-range and was never consented', [], array_values($bad));
        $t->same(
            'and the form would refuse a seeded address',
            $disposable,
            RespondentIdentity::emailError($rows[0]['director_email'])
        );
    } finally {
        SeedGenerator::purge($app, $run['seed_run_id']);
        Database::statement('DELETE FROM `dsp_seed_runs` WHERE `id` = ?', [$run['seed_run_id']]);
    }
};
