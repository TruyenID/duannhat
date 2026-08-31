<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use App\Services\Payment\Gateway\PayPay\PayPayLifecycleMapper;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\Money;
use DateTimeImmutable;
use Tests\Support\Payment\PaymentGatewayFixtures;
use Tests\TestCase;

/**
 * plan-054 M1/M2 — the dynamic-QR capability and its lifecycle mapping.
 *
 * The SDK round trip itself is deliberately not unit-tested here: the existing
 * PayPay suites follow the same rule, and the network path is evidenced against
 * the real sandbox in plans/plan-054/evidence/01-sandbox-verification.md.
 */
final class PayPayQrCapabilityTest extends TestCase
{
    private function connection(): GatewayConnectionData
    {
        return new GatewayConnectionData(
            PaymentGatewayFixtures::CONNECTION_ID,
            PaymentGatewayProviderCodeEnum::Paypay,
            PaymentGatewayEnvironmentEnum::Test,
            'assume-merchant-001',
            1,
        );
    }

    public function test_qr_capability_declares_only_what_the_adapter_can_perform(): void
    {
        $set = PayPayLifecycleMapper::qrCapabilitySet(PaymentGatewayEnvironmentEnum::Test);
        $at = new DateTimeImmutable('2026-07-30T00:00:00+09:00');

        self::assertSame('paypay.web_payment.qr.v1', $set->id);
        self::assertSame(PaymentGatewayProviderCodeEnum::Paypay, $set->provider);
        self::assertSame('opa_web_payment', $set->integrationProduct);
        self::assertContains(PaymentWorkflow::Sale, $set->workflows);
        self::assertNotContains(PaymentWorkflow::AuthorizeCapture, $set->workflows);
        self::assertSame([PaymentChannelEnum::CustomerWeb], $set->channels);

        self::assertTrue($set->supports(GatewayCapability::Create, $at));
        self::assertTrue($set->supports(GatewayCapability::RetrievePayment, $at));
        self::assertTrue($set->supports(GatewayCapability::WebhookVerification, $at));

        // A QR payment is terminal at COMPLETED and no refund path is wired.
        // Declaring these would let the policy engine approve operations the
        // adapter cannot perform (plan-054 D5).
        self::assertFalse($set->supports(GatewayCapability::Capture, $at));
        self::assertFalse($set->supports(GatewayCapability::Cancel, $at));
        self::assertFalse($set->supports(GatewayCapability::Refund, $at));
        self::assertFalse($set->supports(GatewayCapability::RetrieveRefund, $at));
    }

    public function test_qr_capability_does_not_require_store_or_terminal_identity(): void
    {
        $qr = PayPayLifecycleMapper::qrCapabilitySet(PaymentGatewayEnvironmentEnum::Test);
        $preauth = PayPayLifecycleMapper::capabilitySet(PaymentGatewayEnvironmentEnum::Test);

        self::assertSame(['assume_merchant'], $qr->merchantIdentityRequirements);
        self::assertContains('store_id', $preauth->merchantIdentityRequirements);
        self::assertNotContains('store_id', $qr->merchantIdentityRequirements);
    }

    public function test_expired_is_a_terminal_cancel_not_a_reconciliation_task(): void
    {
        $mapper = new PayPayLifecycleMapper;

        // The one case the preauth map has no branch for: a QR simply timing
        // out means the customer never scanned, so no money moved and nobody
        // should be asked to chase it.
        self::assertSame(PaymentAttemptStateEnum::ReconciliationRequired, $mapper->mapPaymentState('EXPIRED'));
        self::assertSame(PaymentAttemptStateEnum::Canceled, $mapper->mapQrPaymentState('EXPIRED'));
    }

    public function test_qr_state_map_agrees_with_the_preauth_map_everywhere_else(): void
    {
        $mapper = new PayPayLifecycleMapper;

        foreach (['COMPLETED', 'FAILED', 'CANCELED', 'CREATED', 'AUTHORIZED', 'WHATEVER'] as $rawStatus) {
            self::assertSame(
                $mapper->mapPaymentState($rawStatus),
                $mapper->mapQrPaymentState($rawStatus),
                "QR map diverged from the preauth map on {$rawStatus}",
            );
        }
    }

    public function test_provider_rejection_in_the_result_envelope_is_a_failure_not_an_unknown(): void
    {
        $mapper = new PayPayLifecycleMapper;

        $result = $mapper->mapPaymentResponse(
            [
                'resultInfo' => ['code' => 'INVALID_REQUEST_PARAMS', 'message' => 'Bad request'],
                'data' => [],
            ],
            $this->connection(),
            'tempoqr-abc',
            new Money(1000, 'JPY'),
        );

        // Before plan-054 T2.2 this yielded rawStatus 'UNKNOWN' →
        // ReconciliationRequired, i.e. an operator chasing money that never moved.
        self::assertSame(PaymentAttemptStateEnum::Failed, $result->state);
        self::assertSame('INVALID_REQUEST_PARAMS', $result->rawStatus);
        self::assertNull($result->processedMoney);
    }

    public function test_a_successful_envelope_is_mapped_from_the_payment_status(): void
    {
        $mapper = new PayPayLifecycleMapper;

        $result = $mapper->mapPaymentResponse(
            [
                'resultInfo' => ['code' => 'SUCCESS'],
                'data' => ['status' => 'COMPLETED', 'paymentId' => 'pp-1', 'amount' => ['amount' => 1000, 'currency' => 'JPY']],
            ],
            $this->connection(),
            'tempoqr-abc',
        );

        self::assertSame(PaymentAttemptStateEnum::Succeeded, $result->state);
        self::assertSame('COMPLETED', $result->rawStatus);
        self::assertSame(1000, $result->processedMoney?->minorAmount);
    }

    public function test_qr_state_map_is_applied_only_when_asked_for(): void
    {
        $mapper = new PayPayLifecycleMapper;
        $envelope = ['resultInfo' => ['code' => 'SUCCESS'], 'data' => ['status' => 'EXPIRED']];

        $preauth = $mapper->mapPaymentResponse($envelope, $this->connection(), 'op-1');
        $qr = $mapper->mapPaymentResponse($envelope, $this->connection(), 'tempoqr-1', null, useQrStateMap: true);

        self::assertSame(PaymentAttemptStateEnum::ReconciliationRequired, $preauth->state);
        self::assertSame(PaymentAttemptStateEnum::Canceled, $qr->state);
    }

    public function test_merchant_payment_id_is_derived_per_attempt_and_is_self_identifying(): void
    {
        $first = PayPayQrCodeClient::merchantPaymentIdFor('019facae-cf5e-739b-b23d-26f27b01d76e');
        $second = PayPayQrCodeClient::merchantPaymentIdFor('019facae-cf5e-739b-b23d-000000000000');

        // Per attempt, never per order: payment_attempts is unique on
        // (connection_id, environment, provider_object_id), so an order-derived
        // id could not be re-minted after an expired QR (plan-054 R6).
        self::assertNotSame($first, $second);

        self::assertStringStartsWith(PayPayQrCodeClient::MPID_PREFIX, $first);
        self::assertStringNotContainsString('-', substr($first, strlen(PayPayQrCodeClient::MPID_PREFIX)));
        self::assertLessThanOrEqual(64, strlen($first));

        // The prefix is what routes a retrieve to /v2/codes/payments/{mpid}
        // instead of /v2/payments/{mpid} — asking the wrong one 404s and
        // dead-letters the provider event (plan-054 R5).
        self::assertTrue(PayPayQrCodeClient::isQrMerchantPaymentId($first));
        self::assertFalse(PayPayQrCodeClient::isQrMerchantPaymentId('019facaecf5e739bb23d26f27b01d76e'));
    }
}
