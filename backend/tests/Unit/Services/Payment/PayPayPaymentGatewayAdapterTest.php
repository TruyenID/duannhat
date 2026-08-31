<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use DateTimeImmutable;
use PayPay\OpenPaymentAPI\Client;
use PayPay\OpenPaymentAPI\Models\CreatePaymentAuthPayload;
use Tests\Support\Payment\PaymentGatewayFixtures;
use Tests\TestCase;

final class PayPayPaymentGatewayAdapterTest extends TestCase
{
    public function test_registry_resolves_the_paypay_sdk_adapter(): void
    {
        $registry = $this->app->make(PaymentGatewayRegistry::class);

        $gateway = $registry->forProvider(
            PaymentGatewayProviderCodeEnum::Paypay,
            'paypay:adapter:registry',
        );

        self::assertInstanceOf(PayPayPaymentGateway::class, $gateway);
        self::assertSame($gateway, $this->app->make(PayPayPaymentGateway::class));
    }

    public function test_composer_installs_godx_paypay_sdk(): void
    {
        self::assertTrue(class_exists(Client::class));
        self::assertTrue(class_exists(CreatePaymentAuthPayload::class));
    }

    public function test_exposes_paypay_preauth_capability_snapshot(): void
    {
        $gateway = new PayPayPaymentGateway;
        $expected = PaymentGatewayFixtures::payPayPreauthCapability();
        $actual = $gateway->capabilities(new GatewayConnectionData(
            PaymentGatewayFixtures::CONNECTION_ID,
            PaymentGatewayProviderCodeEnum::Paypay,
            PaymentGatewayFixtures::connection()->environment,
            'assume-merchant-001',
            1,
        ));

        self::assertSame($expected->id, $actual->id);
        self::assertSame($expected->provider, $actual->provider);
        self::assertSame($expected->integrationProduct, $actual->integrationProduct);
        self::assertSame($expected->apiVersion, $actual->apiVersion);
        self::assertSame($expected->rail, $actual->rail);
        self::assertTrue($actual->supports(GatewayCapability::Create, new DateTimeImmutable('2026-07-22T00:00:00+09:00')));
        self::assertTrue($actual->recovery->pollPayment);
        self::assertTrue($actual->recovery->pollRefund);
        self::assertTrue($actual->recovery->webhookEvents);
        self::assertContains('assume_merchant', $actual->merchantIdentityRequirements);
    }

    public function test_rejects_mismatched_assume_merchant_identity(): void
    {
        $gateway = new PayPayPaymentGateway;

        $this->expectException(GatewayAuthenticationFailed::class);

        $gateway->preparePayment(new CreatePaymentCommand(
            new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayProviderCodeEnum::Paypay,
                PaymentGatewayFixtures::connection()->environment,
                'assume-merchant-001',
                1,
            ),
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
            ]),
        ));
    }

    public function test_rejects_deprecated_paypay_webhook_host(): void
    {
        $gateway = new PayPayPaymentGateway;

        $this->expectException(WebhookVerificationFailed::class);

        $gateway->verifyWebhook(new VerifyWebhookCommand(
            new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayProviderCodeEnum::Paypay,
                PaymentGatewayFixtures::connection()->environment,
                'assume-merchant-001',
                1,
            ),
            json_encode([
                'notification_type' => 'Transaction',
                'order_id' => 'pay_deprecated',
                'state' => 'COMPLETED',
                'callback' => 'https://api.paypay.ne.jp/webhook',
            ], JSON_THROW_ON_ERROR),
            [],
            'paypay:adapter:deprecated-host',
        ));
    }

    public function test_accepts_live_opa_webhook_from_paypay_source_ip_without_secret(): void
    {
        $gateway = new PayPayPaymentGateway;
        $payload = json_encode([
            'notification_type' => 'Transaction',
            'notification_id' => 'evt_live_opa',
            'order_id' => 'mp_live_opa',
            'state' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        $verified = $gateway->verifyWebhook(new VerifyWebhookCommand(
            new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayProviderCodeEnum::Paypay,
                PaymentGatewayEnvironmentEnum::Live,
                'assume-merchant-001',
                1,
            ),
            $payload,
            [],
            'paypay:adapter:live-opa',
            '52.68.128.8',
        ));

        self::assertSame('paypay.transaction.notification', $verified->eventType);
        self::assertSame('mp_live_opa', $verified->payment?->value);
    }

    public function test_rejects_live_opa_webhook_from_non_paypay_source_ip(): void
    {
        $gateway = new PayPayPaymentGateway;

        $this->expectException(WebhookVerificationFailed::class);

        $gateway->verifyWebhook(new VerifyWebhookCommand(
            new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayProviderCodeEnum::Paypay,
                PaymentGatewayEnvironmentEnum::Live,
                'assume-merchant-001',
                1,
            ),
            json_encode([
                'notification_type' => 'Transaction',
                'order_id' => 'mp_blocked',
                'state' => 'COMPLETED',
            ], JSON_THROW_ON_ERROR),
            [],
            'paypay:adapter:live-blocked',
            '203.0.113.50',
        ));
    }
}
