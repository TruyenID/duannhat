<?php

namespace Tests\Support\Payment;

use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Services\Payment\Gateway\Enums\CapabilityFact;
use App\Services\Payment\Gateway\Enums\CapabilityOperator;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use App\Services\Payment\Gateway\ValueObjects\CapabilityCondition;
use App\Services\Payment\Gateway\ValueObjects\CapabilityEvidence;
use App\Services\Payment\Gateway\ValueObjects\CapabilityLimits;
use App\Services\Payment\Gateway\ValueObjects\CapabilityPredicate;
use App\Services\Payment\Gateway\ValueObjects\CapabilityRule;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\CapabilityVerification;
use App\Services\Payment\Gateway\ValueObjects\CurrencyCapability;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\GatewayRequestContext;
use App\Services\Payment\Gateway\ValueObjects\OperationCapability;
use App\Services\Payment\Gateway\ValueObjects\RecoveryCapability;
use DateTimeImmutable;

final class PaymentGatewayFixtures
{
    public const CONNECTION_ID = '0198f608-0800-7549-9dab-1e05925edcb9';

    public const ORDER_ID = '0198f608-0d92-792b-94a9-d44863c483e7';

    public const OPTION_ID = '0198f608-10ca-77d7-8791-954e4c88d760';

    public static function connection(string $connectionId = self::CONNECTION_ID): GatewayConnectionData
    {
        return new GatewayConnectionData(
            $connectionId,
            PaymentGatewayProviderCodeEnum::Stripe,
            PaymentGatewayEnvironmentEnum::Test,
            'acct_contract_test',
            1,
        );
    }

    public static function request(
        string $idempotencyKey = 'contract:operation:1',
        string $correlationId = 'contract:trace:1',
        string $operationId = '0198f608-1581-7a43-b20c-55470be9b6e9',
    ): GatewayRequestContext {
        return new GatewayRequestContext(
            $operationId,
            $idempotencyKey,
            $correlationId,
        );
    }

    public static function fullCapability(): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);

        return self::capability(
            [PaymentWorkflow::Sale, PaymentWorkflow::AuthorizeCapture],
            array_map(
                fn (GatewayCapability $operation): OperationCapability => new OperationCapability($operation, $supported),
                GatewayCapability::cases(),
            ),
            new CapabilityLimits($supported, $supported, $supported, 1, 10_000_000, 604800, 86400, 2_592_000),
            new RecoveryCapability(true, true, true, 'daily_reconciliation'),
        );
    }

    public static function payPayPreauthCapability(): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);
        $conditionalPartialRefund = new CapabilityRule(
            CapabilitySupport::Conditional,
            new CapabilityCondition([
                new CapabilityPredicate(
                    CapabilityFact::ConnectionPartialRefundEnabled,
                    CapabilityOperator::IsTrue,
                ),
            ]),
        );

        return new CapabilitySet(
            'paypay.preauth.wallet.v1',
            1,
            PaymentGatewayProviderCodeEnum::Paypay,
            'opa_preauth_capture',
            '1.0',
            PaymentOptionRailEnum::Wallet,
            'paypay',
            ['paypay'],
            [PaymentChannelEnum::CustomerWeb],
            ['browser'],
            [new CurrencyCapability('JPY', 0)],
            PaymentGatewayEnvironmentEnum::Test,
            [PaymentWorkflow::AuthorizeCapture],
            array_map(
                fn (GatewayCapability $operation): OperationCapability => new OperationCapability($operation, $supported),
                GatewayCapability::cases(),
            ),
            new CapabilityLimits($supported, $conditionalPartialRefund, $supported, 1, 10_000_000, 30, 86400, 2_592_000),
            new RecoveryCapability(true, true, true, 'daily_reconciliation'),
            ['assume_merchant', 'store_id', 'terminal_id'],
            new DateTimeImmutable('2026-07-22T00:00:00+09:00'),
            null,
            new CapabilityVerification(CapabilityVerificationState::CertificationRequired, []),
        );
    }

    public static function unsupportedMutationCapability(): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);
        $unsupported = new CapabilityRule(CapabilitySupport::Unsupported);

        return self::capability(
            [PaymentWorkflow::Sale],
            [
                new OperationCapability(GatewayCapability::Create, $supported),
                new OperationCapability(GatewayCapability::RetrievePayment, $supported),
                new OperationCapability(GatewayCapability::Authorize, $unsupported),
                new OperationCapability(GatewayCapability::Capture, $unsupported),
                new OperationCapability(GatewayCapability::Cancel, $unsupported),
                new OperationCapability(GatewayCapability::Refund, $unsupported),
                new OperationCapability(GatewayCapability::RetrieveRefund, $unsupported),
                new OperationCapability(GatewayCapability::WebhookVerification, $supported),
            ],
            new CapabilityLimits($unsupported, $unsupported, $unsupported),
            new RecoveryCapability(true, false, true),
        );
    }

    public static function unverifiedCapability(): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);

        return self::capability(
            [PaymentWorkflow::Sale, PaymentWorkflow::AuthorizeCapture],
            array_map(
                fn (GatewayCapability $operation): OperationCapability => new OperationCapability($operation, $supported),
                GatewayCapability::cases(),
            ),
            new CapabilityLimits($supported, $supported, $supported),
            new RecoveryCapability(true, true, true, 'daily_reconciliation'),
            new CapabilityVerification(CapabilityVerificationState::ContractRequired, []),
        );
    }

    public static function capabilityWithout(GatewayCapability $unavailable): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);
        $unsupported = new CapabilityRule(CapabilitySupport::Unsupported);

        return self::capability(
            [PaymentWorkflow::Sale, PaymentWorkflow::AuthorizeCapture],
            array_map(
                fn (GatewayCapability $operation): OperationCapability => new OperationCapability(
                    $operation,
                    $operation === $unavailable ? $unsupported : $supported,
                ),
                GatewayCapability::cases(),
            ),
            new CapabilityLimits($supported, $supported, $supported),
            new RecoveryCapability(
                $unavailable !== GatewayCapability::RetrievePayment,
                $unavailable !== GatewayCapability::RetrieveRefund,
                $unavailable !== GatewayCapability::WebhookVerification,
                'daily_reconciliation',
            ),
        );
    }

    /**
     * @param  list<PaymentWorkflow>  $workflows
     * @param  list<OperationCapability>  $operations
     */
    private static function capability(
        array $workflows,
        array $operations,
        CapabilityLimits $limits,
        RecoveryCapability $recovery,
        ?CapabilityVerification $verification = null,
    ): CapabilitySet {
        return new CapabilitySet(
            'contract.fake.card.v1',
            3,
            PaymentGatewayProviderCodeEnum::Stripe,
            'contract_fake',
            '2026-06-30',
            PaymentOptionRailEnum::Card,
            'card',
            ['account_configured'],
            [PaymentChannelEnum::CustomerWeb, PaymentChannelEnum::Pos],
            ['browser'],
            [new CurrencyCapability('JPY', 0)],
            PaymentGatewayEnvironmentEnum::Test,
            $workflows,
            $operations,
            $limits,
            $recovery,
            ['connected_account'],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
            $verification ?? new CapabilityVerification(CapabilityVerificationState::Verified, [
                new CapabilityEvidence(
                    'application-test:payment-gateway-contract',
                    'account:fake-provider-test',
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                    'test-run:payment-gateway-contract',
                    'identity:plan-047-reviewer',
                    new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
                ),
            ]),
        );
    }
}
