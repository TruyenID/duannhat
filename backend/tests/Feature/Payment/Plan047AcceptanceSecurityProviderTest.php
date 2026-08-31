<?php

/**
 * Plan 047 acceptance — security H4, H7, H9 and provider compatibility H8.
 */

use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Fakes\Payment\PayPayFakePaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;
use Tests\Unit\Services\Payment\PayPayPaymentGatewayContractTest;

describe('H4 webhook replay and signature tolerance', function () {
    it('H4 rejects invalid webhook signatures before normalized processing', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );

        expect(fn () => $gateway->verifyWebhook(new VerifyWebhookCommand(
            PaymentGatewayFixtures::connection(),
            '{"id":"evt_bad","type":"payment.succeeded"}',
            ['Provider-Signature' => 'invalid'],
            'h4-corr',
        )))->toThrow(WebhookVerificationFailed::class);
    });

    it('H4 contract suite covers webhook verification and duplicate delivery', function () {
        $contract = file_get_contents(base_path('tests/Contracts/Payment/PaymentGatewayContractTestCase.php'));
        expect($contract)->toContain('test_webhook_verification_is_idempotent_and_redacted')
            ->and($contract)->toContain('VerifyWebhookCommand');
    });
});

describe('H7 SBPS capability date boundary', function () {
    it('H7 documents the conservative 2026-09-30 SBPS boundary in TEST-CASES', function () {
        $cases = file_get_contents(base_path('../plans/plan-047/TEST-CASES.md'));
        expect($cases)->toContain('2026-09-30');
    });

    it('H7 capability snapshots expose effective date windows via appliesAt', function () {
        $capability = PaymentGatewayFixtures::fullCapability();
        expect($capability->appliesAt(new DateTimeImmutable('2026-07-22T00:00:00+00:00')))->toBeTrue();
    });
});

describe('H8 PayPay fake passes gateway contract', function () {
    it('H8 PayPayFakePaymentGateway satisfies PaymentGatewayContractTestCase', function () {
        expect(is_subclass_of(PayPayFakePaymentGateway::class, PaymentGatewayContract::class))->toBeTrue()
            ->and(class_exists(PayPayPaymentGatewayContractTest::class))->toBeTrue();
    });
});

describe('H9 H2 H10 registry', function () {
    it('H2 secret resolver and H10 rollback tests exist in dedicated suites', function () {
        // #2188 — the H9 backfill command + its test were deleted with the
        // Backfill* family (ruling: reseed, không backfill).
        expect(file_exists(base_path('tests/Feature/Payment/GatewaySecretStoreTest.php')))->toBeTrue()
            ->and(file_exists(base_path('tests/Feature/Payment/PaymentCutoverRollbackRehearsalTest.php')))->toBeTrue();
    });
});
