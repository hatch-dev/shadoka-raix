<?php

declare(strict_types=1);

use Platform\Core\Assert;
use Platform\Validation\Validator;

/**
 * `max` and `min` measure a string, or compare a number — never both.
 *
 * They used to do both, in two consecutive ifs, and a great many strings are
 * also is_numeric(). A phone number is the case that proved it: PHP reads
 * "+919876274711" as a number, so `nullable|string|max:40` measured it as
 * thirteen characters, passed, then compared it as a quantity and rejected it
 * with "Respondent phone may not be greater than 40." A number typed WITH spaces
 * — "+1 555 123 4567" — is not is_numeric and went through fine, which is why
 * this looked intermittent rather than broken.
 *
 * A platform test, because every app's rules go through this class.
 */
return static function (Assert $t): void {
    /*
     * Pairs, not a keyed map: PHP turns a numeric-string array KEY into an int,
     * which would hand the validator an integer and test something else entirely.
     */
    $phones = [
        ['+919876274711', 'a country code with no spaces'],
        ['7894561230', 'ten digits and nothing else'],
        ['+1 555 123 4567', 'the spaced form that always worked'],
        ['00353861234567', 'leading zeros, which PHP still reads as a number'],
        ['4.15551234', 'a string PHP reads as a float'],
    ];

    foreach ($phones as [$phone, $why]) {
        $v = Validator::make(['phone' => $phone], ['phone' => 'nullable|string|max:40']);
        $t->true("max:40 accepts $why", !$v->fails(), (string) $v->firstError());
        $t->same("and returns it unchanged: $why", $phone, $v->validated()['phone'] ?? null);
    }

    /* The length rule still has to bite. */
    $long = Validator::make(['phone' => str_repeat('1', 41)], ['phone' => 'nullable|string|max:40']);
    $t->true('max:40 still refuses 41 characters', $long->fails());
    $t->same(
        'and says so in characters, not quantity',
        'Phone may not be longer than 40 characters.',
        (string) $long->firstError(),
    );

    /* min, which had the mirror-image bug. */
    $short = Validator::make(['code' => '7'], ['code' => 'nullable|string|min:2']);
    $t->true('min:2 refuses a one-character string', $short->fails());
    $t->same(
        'and says so in characters',
        'Code must be at least 2 characters.',
        (string) $short->firstError(),
    );

    $ok = Validator::make(['code' => '77'], ['code' => 'nullable|string|min:2']);
    $t->true('min:2 accepts a two-character numeric string', !$ok->fails());

    /*
     * The numeric branch is still reached — by fields DECLARED numeric, whose
     * type rule has already cast them by the time max/min runs.
     */
    $big = Validator::make(['n' => '101'], ['n' => 'nullable|integer|max:100']);
    $t->true('integer|max:100 refuses 101', $big->fails());
    $t->same(
        'and says so as a quantity',
        'N may not be greater than 100.',
        (string) $big->firstError(),
    );

    $fine = Validator::make(['n' => '99'], ['n' => 'nullable|integer|max:100']);
    $t->true('integer|max:100 accepts 99', !$fine->fails());
    $t->same('and hands back an int', 99, $fine->validated()['n'] ?? null);
};
