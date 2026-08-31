<?php

namespace Tests\Feature\Payment;

use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Enums\PolicyReasonCode;
use App\Services\Payment\Policy\PaymentPolicyResolver;
use App\Services\Payment\Policy\UnavailableBranchManagementProjectionSource;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyRequest;
use DateTimeImmutable;
use Tests\TestCase;

final class PaymentPolicyResolverBindingTest extends TestCase
{
    public function test_default_runtime_wiring_is_resolvable_and_fails_closed_without_identity_adapter(): void
    {
        $source = $this->app->make(BranchManagementProjectionSource::class);
        self::assertInstanceOf(UnavailableBranchManagementProjectionSource::class, $source);

        $resolver = $this->app->make(PaymentPolicyResolver::class);
        $result = $resolver->resolve(new PaymentPolicyRequest(
            '00000000-0000-4000-8000-000000000501',
            '00000000-0000-4000-8000-000000000201',
            '00000000-0000-4000-8000-000000000101',
            '00000000-0000-4000-8000-000000000202',
            '00000000-0000-4000-8000-000000000301',
            '00000000-0000-4000-8000-000000000601',
            'visa',
            null,
            PaymentChannelEnum::Pos,
            'browser',
            'JPY',
            PaymentGatewayEnvironmentEnum::Test,
            GatewayCapability::Create,
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
            'policy:binding:fail-closed',
        ), []);

        self::assertFalse($result->effective);
        self::assertSame(PolicyReasonCode::OwnershipSourceUnavailable, $result->reason);
        self::assertSame('PAYMENT_OWNERSHIP_UNRESOLVED', $result->reason->publicErrorCode());
        self::assertNull($result->connectionId);
    }
}
