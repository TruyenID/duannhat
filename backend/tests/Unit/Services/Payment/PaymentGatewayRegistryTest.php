<?php

namespace Tests\Unit\Services\Payment;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Exceptions\InvalidPaymentGatewayDriver;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentGatewayProvider;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

final class PaymentGatewayRegistryTest extends TestCase
{
    public function test_resolves_only_the_explicit_provider_mapping(): void
    {
        $container = new Container;
        $stripe = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );
        $container->instance('payments.gateway.stripe', $stripe);
        $registry = new PaymentGatewayRegistry($container, [
            'stripe' => 'payments.gateway.stripe',
        ]);

        self::assertSame($stripe, $registry->forProvider(
            PaymentGatewayProviderCodeEnum::Stripe,
            'registry:resolve:provider',
        ));
        self::assertSame($stripe, $registry->forConnection(
            PaymentGatewayFixtures::connection(),
            'registry:resolve:connection',
        ));
        self::assertSame(
            [PaymentGatewayProviderCodeEnum::Stripe],
            $registry->configuredProviders(),
        );
    }

    public function test_unconfigured_provider_fails_typed_without_fallback_or_resolution(): void
    {
        $container = new Container;
        $resolutions = 0;
        $container->bind('payments.gateway.stripe', function () use (&$resolutions) {
            $resolutions++;

            return new \stdClass;
        });
        $registry = new PaymentGatewayRegistry($container, [
            'stripe' => 'payments.gateway.stripe',
        ]);

        try {
            $registry->forProvider(PaymentGatewayProviderCodeEnum::Paypay, 'registry:unsupported:1');
            self::fail('An unconfigured provider must fail closed.');
        } catch (UnsupportedPaymentGatewayProvider $error) {
            self::assertSame('PAYMENT_GATEWAY_PROVIDER_UNSUPPORTED', $error->errorCode);
            self::assertSame('registry:unsupported:1', $error->correlationId);
            self::assertSame(PaymentGatewayProviderCodeEnum::Paypay, $error->provider);
            self::assertSame([PaymentGatewayProviderCodeEnum::Stripe], $error->configuredProviders);
            self::assertStringNotContainsString('payments.gateway.stripe', $error->getMessage());
        }

        self::assertSame(0, $resolutions);
    }

    public function test_configuration_is_deterministic_and_rejects_unknown_or_blank_mappings(): void
    {
        $container = new Container;
        $registry = new PaymentGatewayRegistry($container, [
            'stripe' => 'payments.gateway.stripe',
            'paypay' => 'payments.gateway.paypay',
        ]);

        self::assertSame(
            [PaymentGatewayProviderCodeEnum::Paypay, PaymentGatewayProviderCodeEnum::Stripe],
            $registry->configuredProviders(),
        );

        foreach ([
            ['future-provider' => 'payments.gateway.future'],
            ['stripe' => ''],
            ['stripe' => null],
        ] as $invalid) {
            try {
                new PaymentGatewayRegistry($container, $invalid);
                self::fail('Invalid registry mapping must fail during construction.');
            } catch (InvalidPaymentGatewayDriver $error) {
                self::assertSame('PAYMENT_GATEWAY_DRIVER_INVALID', $error->errorCode);
                self::assertContains($error->reason, ['unknown_provider', 'invalid_service']);
                self::assertStringNotContainsString('payments.gateway.future', $error->getMessage());
            }
        }
    }

    public function test_unresolvable_and_wrong_contract_drivers_fail_with_safe_typed_configuration_errors(): void
    {
        $container = new Container;

        foreach ([
            'missing.service' => 'unresolvable',
            \stdClass::class => 'contract_mismatch',
        ] as $service => $expectedReason) {
            $registry = new PaymentGatewayRegistry($container, ['stripe' => $service]);

            try {
                $registry->forProvider(PaymentGatewayProviderCodeEnum::Stripe, 'registry:invalid-driver');
                self::fail('Invalid driver must fail closed.');
            } catch (InvalidPaymentGatewayDriver $error) {
                self::assertSame(PaymentGatewayProviderCodeEnum::Stripe, $error->provider);
                self::assertSame($expectedReason, $error->reason);
                self::assertStringNotContainsString($service, $error->getMessage());
            }
        }
    }
}
