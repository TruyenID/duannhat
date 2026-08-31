<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\ValueObjects\CapabilityRule;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use DateTimeImmutable;
use Tests\Fakes\Payment\PayPayFakePaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;
use Tests\TestCase;

final class PayPayPaymentGatewayCertificationTest extends TestCase
{
    public function test_rejects_mismatched_assume_merchant_identity(): void
    {
        $gateway = new PayPayFakePaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );

        $connection = new GatewayConnectionData(
            PaymentGatewayFixtures::CONNECTION_ID,
            PaymentGatewayProviderCodeEnum::Paypay,
            PaymentGatewayFixtures::connection()->environment,
            'assume-merchant-001',
            1,
        );

        $this->expectException(GatewayAuthenticationFailed::class);

        $gateway->preparePayment(new CreatePaymentCommand(
            $connection,
            PaymentGatewayFixtures::request(),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::CustomerWeb,
            9,
            'client-source-secret',
            new RedactedData([
                'merchant_account_reference' => 'assume-merchant-002',
                'order_code' => 'ORDER_1000',
                'reason_code' => 'paypay_cert',
            ]),
        ));
    }

    public function test_rejects_deprecated_paypay_webhook_host(): void
    {
        $gateway = new PayPayFakePaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );

        $this->expectException(WebhookVerificationFailed::class);

        $gateway->verifyWebhook(new VerifyWebhookCommand(
            PaymentGatewayFixtures::connection(),
            json_encode([
                'id' => 'evt_deprecated_host',
                'type' => 'paypay.payment.completed',
                'payment' => 'pay_deprecated',
                'status' => 'COMPLETED',
                'callback' => 'https://api.paypay.ne.jp/webhook',
            ], JSON_THROW_ON_ERROR),
            ['PayPay-Signature' => 'valid-paypay-signature'],
            'paypay:cert:deprecated-host',
        ));
    }

    public function test_exposes_paypay_polling_and_webhook_recovery_flags(): void
    {
        $capabilities = PaymentGatewayFixtures::payPayPreauthCapability();

        self::assertTrue($capabilities->recovery->pollPayment);
        self::assertTrue($capabilities->recovery->pollRefund);
        self::assertTrue($capabilities->recovery->webhookEvents);
        self::assertContains('assume_merchant', $capabilities->merchantIdentityRequirements);
    }

    public function test_documents_partial_refund_as_conditional_capability(): void
    {
        $limits = PaymentGatewayFixtures::payPayPreauthCapability()->limits;

        self::assertSame(CapabilitySupport::Conditional, $limits->partialRefund->support);
        self::assertInstanceOf(CapabilityRule::class, $limits->partialRefund);
    }
}
