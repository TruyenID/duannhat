<?php

/**
 * plan-055 T3.4 (#1834) — the observable-code list must not drift from the
 * codes the validator can actually throw.
 *
 * `OrderPaymentService::assertPolicyAllowedOrObserve()` re-throws any code that
 * is not in `EMITTED_ERROR_CODES`. So a NEW code added to the validator without
 * being listed is fail-closed: on the aliased path it becomes a 422 refusing
 * real money — the exact Gate 3 regression the list exists to prevent, with
 * nothing to notice.
 *
 * A first version of this guard scanned the file for `/'(PAYMENT_[A-Z_]+)'/`
 * and was measured to MISS a code named `POLICY_OPTION_UNVERIFIED` — and
 * `POLICY_*` is the likelier prefix for the next code in this area, since the
 * sibling in the same feature is `POLICY_OPTION_REQUIRED`. A guard that reports
 * green for the most probable failure is worse than none.
 *
 * So this asserts STRUCTURE, not spelling:
 *   1. every `CODE_*` constant on the validator is in `EMITTED_ERROR_CODES`
 *   2. no throw site passes a bare string literal — a new code has to become a
 *      constant, which rule 1 then catches
 */

use App\Services\Payment\Policy\PaymentPolicySubmissionValidator;

it('#1834 lists every error-code constant the validator declares', function () {
    $declared = collect((new ReflectionClass(PaymentPolicySubmissionValidator::class))->getConstants())
        ->filter(static fn ($value, string $name): bool => str_starts_with($name, 'CODE_'))
        ->values()
        ->all();

    expect($declared)->not->toBeEmpty()
        ->and(array_diff($declared, PaymentPolicySubmissionValidator::EMITTED_ERROR_CODES))->toBe([]);
});

it('#1834 refuses a throw site that hard-codes a string instead of a constant', function () {
    $source = (string) file_get_contents(
        (new ReflectionClass(PaymentPolicySubmissionValidator::class))->getFileName()
    );

    // Second constructor argument of every PaymentConfigurationException built
    // in this class. Anything that is not `self::CODE_*` escapes rule 1.
    preg_match_all(
        '/new PaymentConfigurationException\(\s*[^,]+,\s*(?:errorCode:\s*)?([^,\s]+)/',
        $source,
        $matches,
    );

    $secondArguments = $matches[1] ?? [];

    expect($secondArguments)->not->toBeEmpty();

    foreach ($secondArguments as $argument) {
        // Anchored, not prefix-matched: `toStartWith` admits
        // `f($a, self::CODE_STALE)` — a first argument whose own second
        // sub-argument is a constant — while the real second argument is a bare
        // literal. Measured by the reviewer; the anchor closes it and still
        // passes the real multi-line shape.
        // `[A-Z0-9_]` not `[A-Z_]`: a future `CODE_V2_STALE` would otherwise be
        // rejected. And the `errorCode:` prefix above is stripped first —
        // named arguments are the likeliest shape for a behaviour-preserving
        // refactor in a PHP 8.4 codebase, and without it this guard would
        // blame that refactor for "hard-coding a string". Both measured by the
        // reviewer as fail-closed, i.e. noisy-red rather than silent gaps —
        // fixed anyway because a guard that cries wolf gets deleted.
        expect($argument)->toMatch('/^self::CODE_[A-Z0-9_]+$/');
    }
});
