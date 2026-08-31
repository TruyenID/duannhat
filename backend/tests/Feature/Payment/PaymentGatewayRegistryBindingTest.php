<?php

namespace Tests\Feature\Payment;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway;
use DateTimeImmutable;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;
use Tests\TestCase;

final class PaymentGatewayRegistryBindingTest extends TestCase
{
    public function test_application_container_binds_one_fail_closed_registry_by_default(): void
    {
        $first = $this->app->make(PaymentGatewayRegistry::class);
        $second = $this->app->make(PaymentGatewayRegistry::class);

        self::assertSame($first, $second);
        self::assertSame(
            [PaymentGatewayProviderCodeEnum::Paypay, PaymentGatewayProviderCodeEnum::Stripe],
            $first->configuredProviders(),
        );
    }

    public function test_application_binding_reads_the_explicit_driver_config_when_first_resolved(): void
    {
        $driver = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );
        $this->app->instance('payments.gateway.stripe.binding-test', $driver);
        config()->set('payments.gateway_drivers', [
            'paypay' => PayPayPaymentGateway::class,
            'stripe' => 'payments.gateway.stripe.binding-test',
        ]);

        $registry = $this->app->make(PaymentGatewayRegistry::class);

        self::assertSame(
            [PaymentGatewayProviderCodeEnum::Paypay, PaymentGatewayProviderCodeEnum::Stripe],
            $registry->configuredProviders(),
        );
        self::assertSame($driver, $registry->forProvider(
            PaymentGatewayProviderCodeEnum::Stripe,
            'registry:binding:stripe',
        ));
        self::assertInstanceOf(PayPayPaymentGateway::class, $registry->forProvider(
            PaymentGatewayProviderCodeEnum::Paypay,
            'registry:binding:paypay',
        ));
    }
}
