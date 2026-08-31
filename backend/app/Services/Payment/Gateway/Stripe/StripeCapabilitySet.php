<?php

namespace App\Services\Payment\Gateway\Stripe;

use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use App\Services\Payment\Gateway\ValueObjects\CapabilityEvidence;
use App\Services\Payment\Gateway\ValueObjects\CapabilityLimits;
use App\Services\Payment\Gateway\ValueObjects\CapabilityRule;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\CapabilityVerification;
use App\Services\Payment\Gateway\ValueObjects\CurrencyCapability;
use App\Services\Payment\Gateway\ValueObjects\OperationCapability;
use App\Services\Payment\Gateway\ValueObjects\RecoveryCapability;
use DateTimeImmutable;

final class StripeCapabilitySet
{
    public static function forEnvironment(PaymentGatewayEnvironmentEnum $environment): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);

        return new CapabilitySet(
            'stripe.payment_intent.card.v1',
            1,
            PaymentGatewayProviderCodeEnum::Stripe,
            'payment_intents',
            '2026-06-30',
            PaymentOptionRailEnum::Card,
            'card',
            ['automatic_payment_methods'],
            [PaymentChannelEnum::CustomerWeb, PaymentChannelEnum::Pos, PaymentChannelEnum::Kiosk, PaymentChannelEnum::SelfRegi],
            ['browser', 'terminal'],
            [new CurrencyCapability('JPY', 0), new CurrencyCapability('USD', 2), new CurrencyCapability('VND', 0)],
            $environment,
            [PaymentWorkflow::Sale, PaymentWorkflow::AuthorizeCapture],
            array_map(
                static fn (GatewayCapability $operation): OperationCapability => new OperationCapability($operation, $supported),
                GatewayCapability::cases(),
            ),
            new CapabilityLimits($supported, $supported, $supported, 1, 999_999_999, 604800, 86400, 2_592_000),
            new RecoveryCapability(true, true, true, 'webhook_and_retrieve'),
            ['account:legacy-global-platform'],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            new CapabilityVerification(CapabilityVerificationState::Verified, [
                new CapabilityEvidence(
                    'application-test:legacy-global-platform',
                    'account:legacy-global-platform',
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                    'test-run:plan-047-gate-3',
                    'identity:plan-047-reviewer',
                    new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
                ),
            ]),
        );
    }
}
