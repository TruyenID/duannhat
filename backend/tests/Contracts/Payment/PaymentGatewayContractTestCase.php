<?php

namespace Tests\Contracts\Payment;

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\IdempotencyPayloadMismatch;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentOperation;
use App\Services\Payment\Gateway\Exceptions\WebhookPayloadConflict;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Payment\PaymentGatewayFixtures;

/** Extend this class for every provider adapter; no test may be overridden. */
abstract class PaymentGatewayContractTestCase extends TestCase
{
    protected const STARTED_AT = '2026-07-22T00:00:00+00:00';

    abstract protected function gateway(CapabilitySet $capabilities): PaymentGatewayContract;

    abstract protected function gatewayWithFault(CapabilitySet $capabilities, ProviderFault $fault): PaymentGatewayContract;

    abstract protected function providerCallCount(PaymentGatewayContract $gateway, string $operation): int;

    abstract protected function expectedRawStatus(ProviderScenario $scenario): string;

    abstract protected function signedWebhook(
        GatewayConnectionData $connection,
        string $eventId,
        string $paymentReference,
        string $rawStatus,
        string $secretMarker,
        string $correlationId,
    ): VerifyWebhookCommand;

    abstract protected function invalidWebhook(GatewayConnectionData $connection, string $correlationId): VerifyWebhookCommand;

    final public function test_returns_the_exact_verified_capability_snapshot(): void
    {
        $capabilities = PaymentGatewayFixtures::fullCapability();
        $gateway = $this->gateway($capabilities);

        $this->assertEquivalent($capabilities, $gateway->capabilities(PaymentGatewayFixtures::connection()));
        self::assertTrue($capabilities->supports(GatewayCapability::Create, new DateTimeImmutable(self::STARTED_AT)));
        self::assertTrue($capabilities->supports(GatewayCapability::Refund, new DateTimeImmutable(self::STARTED_AT)));
    }

    final public function test_create_and_retrieve_return_normalized_and_raw_evidence(): void
    {
        $gateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $created = $gateway->preparePayment($this->createCommand());
        self::assertSame(PaymentAttemptStateEnum::Succeeded, $created->state);
        self::assertSame($this->expectedRawStatus(ProviderScenario::CreateSucceeded), $created->rawStatus);
        self::assertNotNull($created->payment);
        self::assertSame(1000, $created->processedMoney?->minorAmount);

        $retrieved = $gateway->retrievePayment(new RetrievePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:retrieve:1'),
            $created->payment,
        ));
        $this->assertEquivalent($created, $retrieved);
    }

    final public function test_create_is_idempotent_and_rejects_payload_drift(): void
    {
        $gateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $first = $gateway->preparePayment($this->createCommand());
        $second = $gateway->preparePayment($this->createCommand());
        $this->assertEquivalent($first, $second);
        self::assertSame(1, $this->providerCallCount($gateway, 'create'));

        $reorderedMetadata = $gateway->preparePayment($this->createCommand(metadata: new RedactedData([
            'reason_code' => 'contract_fixture',
            'order_code' => 'ORDER_1000',
        ])));
        $this->assertEquivalent($first, $reorderedMetadata);
        self::assertSame(1, $this->providerCallCount($gateway, 'create'));

        $driftedCommands = [
            $this->createCommand(new Money(1001, 'JPY')),
            $this->createCommand(new Money(1000, 'USD')),
            $this->createCommand(paymentSource: 'different-client-source'),
            $this->createCommand(orderId: '0198f608-bf35-79a1-927f-e04ac6eb4c91'),
            $this->createCommand(optionId: '0198f608-bf35-79a1-927f-e04ac6eb4c9b'),
            $this->createCommand(policyRevision: 10),
            $this->createCommand(operation: PaymentAttemptOperationEnum::Authorize),
            $this->createCommand(channel: PaymentChannelEnum::Pos),
            $this->createCommand(operationId: '0198f608-bf35-79a1-927f-e04ac6eb4c92'),
            $this->createCommand(connection: new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayFixtures::connection()->provider,
                PaymentGatewayFixtures::connection()->environment,
                'acct_contract_test',
                2,
            )),
            $this->createCommand(connection: new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayProviderCodeEnum::Paypay,
                PaymentGatewayFixtures::connection()->environment,
                'acct_contract_test',
                1,
            )),
            $this->createCommand(connection: new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayFixtures::connection()->provider,
                PaymentGatewayEnvironmentEnum::Live,
                'acct_contract_test',
                1,
            )),
            $this->createCommand(connection: new GatewayConnectionData(
                PaymentGatewayFixtures::CONNECTION_ID,
                PaymentGatewayFixtures::connection()->provider,
                PaymentGatewayFixtures::connection()->environment,
                'acct_contract_different',
                1,
            )),
            $this->createCommand(metadata: new RedactedData(['order_code' => 'ORDER_DIFFERENT'])),
        ];
        foreach ($driftedCommands as $driftedCommand) {
            $this->assertIdempotencyMismatch(fn () => $gateway->preparePayment($driftedCommand));
        }
        self::assertSame(1, $this->providerCallCount($gateway, 'create'));

        $otherConnection = PaymentGatewayFixtures::connection('0198f608-84ce-7629-b653-00dc291475a1');
        $other = $gateway->preparePayment($this->createCommand(connection: $otherConnection));
        self::assertNotSame($first->payment?->value, $other->payment?->value);
        self::assertSame(2, $this->providerCallCount($gateway, 'create'));
        self::assertStringNotContainsString('client-source-secret', json_encode($first, JSON_THROW_ON_ERROR));

        $this->assertEquivalent($first, $gateway->retrievePayment(new RetrievePaymentCommand(
            PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('contract:retrieve:scope-1'), $first->payment,
        )));
        $this->assertEquivalent($other, $gateway->retrievePayment(new RetrievePaymentCommand(
            $otherConnection, PaymentGatewayFixtures::request('contract:retrieve:scope-2'), $other->payment,
        )));
    }

    final public function test_capture_and_cancel_use_stable_mutation_identity(): void
    {
        $gateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $payment = new ProviderObjectReference('provider-payment-capture');
        $capture = new CapturePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:capture:1'),
            $payment,
            new Money(600, 'JPY'),
        );
        $captured = $gateway->capture($capture);
        $this->assertEquivalent($captured, $gateway->capture($capture));
        self::assertSame(1, $this->providerCallCount($gateway, 'capture'));
        self::assertSame(PaymentAttemptStateEnum::Succeeded, $captured->state);
        self::assertSame($this->expectedRawStatus(ProviderScenario::CaptureSucceeded), $captured->rawStatus);

        $otherConnection = PaymentGatewayFixtures::connection('0198f608-84ce-7629-b653-00dc291475a1');
        $otherCaptured = $gateway->capture(new CapturePaymentCommand(
            $otherConnection,
            PaymentGatewayFixtures::request('contract:capture:1'),
            $payment,
            new Money(700, 'JPY'),
        ));
        self::assertSame(2, $this->providerCallCount($gateway, 'capture'));
        self::assertSame(600, $gateway->retrievePayment(new RetrievePaymentCommand(
            PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('contract:capture:retrieve-1'), $payment,
        ))->processedMoney?->minorAmount);
        self::assertSame(700, $gateway->retrievePayment(new RetrievePaymentCommand(
            $otherConnection, PaymentGatewayFixtures::request('contract:capture:retrieve-2'), $payment,
        ))->processedMoney?->minorAmount);
        self::assertNotSame($captured->processedMoney?->minorAmount, $otherCaptured->processedMoney?->minorAmount);

        $cancel = new CancelPaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:cancel:1'),
            $payment,
        );
        $canceled = $gateway->cancel($cancel);
        $this->assertEquivalent($canceled, $gateway->cancel($cancel));
        self::assertSame(1, $this->providerCallCount($gateway, 'cancel'));
        self::assertSame(PaymentAttemptStateEnum::Canceled, $canceled->state);
        self::assertSame($this->expectedRawStatus(ProviderScenario::CancelSucceeded), $canceled->rawStatus);

        try {
            $gateway->capture(new CapturePaymentCommand(
                PaymentGatewayFixtures::connection(),
                PaymentGatewayFixtures::request('contract:capture:1'),
                $payment,
                new Money(601, 'JPY'),
            ));
            self::fail('Capture payload drift must throw.');
        } catch (IdempotencyPayloadMismatch $error) {
            self::assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $error->errorCode);
        }

        $this->assertIdempotencyMismatch(fn () => $gateway->cancel(new CancelPaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:cancel:1'),
            new ProviderObjectReference('different-provider-payment'),
        )));
    }

    final public function test_refund_and_retrieve_refund_are_idempotent(): void
    {
        $gateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $refundCommand = new RefundPaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:refund:1'),
            new ProviderObjectReference('provider-payment-refund'),
            new Money(250, 'JPY'),
        );
        $refunded = $gateway->refund($refundCommand);
        $this->assertEquivalent($refunded, $gateway->refund($refundCommand));
        self::assertSame(1, $this->providerCallCount($gateway, 'refund'));
        self::assertSame(PaymentRefundStateEnum::Succeeded, $refunded->state);
        self::assertSame($this->expectedRawStatus(ProviderScenario::RefundSucceeded), $refunded->rawStatus);
        self::assertNotNull($refunded->refund);

        $retrieved = $gateway->retrieveRefund(new RetrieveRefundCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:retrieve-refund:1'),
            $refunded->refund,
        ));
        $this->assertEquivalent($refunded, $retrieved);

        try {
            $gateway->refund(new RefundPaymentCommand(
                PaymentGatewayFixtures::connection(),
                PaymentGatewayFixtures::request('contract:refund:1'),
                new ProviderObjectReference('provider-payment-refund'),
                new Money(251, 'JPY'),
            ));
            self::fail('Refund payload drift must throw.');
        } catch (IdempotencyPayloadMismatch $error) {
            self::assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $error->errorCode);
        }

        $otherConnection = PaymentGatewayFixtures::connection('0198f608-84ce-7629-b653-00dc291475a1');
        $scopedFirst = $gateway->refund(new RefundPaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:refund:scope'),
            new ProviderObjectReference('provider-payment-refund-scope'),
            new Money(250, 'JPY'),
        ));
        $scopedOther = $gateway->refund(new RefundPaymentCommand(
            $otherConnection,
            PaymentGatewayFixtures::request('contract:refund:scope'),
            new ProviderObjectReference('provider-payment-refund-scope'),
            new Money(300, 'JPY'),
        ));
        self::assertSame($scopedFirst->refund?->value, $scopedOther->refund?->value);
        self::assertSame(3, $this->providerCallCount($gateway, 'refund'));
        self::assertSame(250, $gateway->retrieveRefund(new RetrieveRefundCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('contract:refund:scope:retrieve:first'),
            $scopedFirst->refund,
        ))->processedMoney?->minorAmount);
        self::assertSame(300, $gateway->retrieveRefund(new RetrieveRefundCommand(
            $otherConnection,
            PaymentGatewayFixtures::request('contract:refund:scope:retrieve:other'),
            $scopedOther->refund,
        ))->processedMoney?->minorAmount);
    }

    final public function test_mutation_idempotency_rejects_every_provider_affecting_field_drift(): void
    {
        $connectionDrifts = [
            new GatewayConnectionData(PaymentGatewayFixtures::CONNECTION_ID, PaymentGatewayProviderCodeEnum::Paypay, PaymentGatewayEnvironmentEnum::Test, 'acct_contract_test', 1),
            new GatewayConnectionData(PaymentGatewayFixtures::CONNECTION_ID, PaymentGatewayProviderCodeEnum::Stripe, PaymentGatewayEnvironmentEnum::Live, 'acct_contract_test', 1),
            new GatewayConnectionData(PaymentGatewayFixtures::CONNECTION_ID, PaymentGatewayProviderCodeEnum::Stripe, PaymentGatewayEnvironmentEnum::Test, 'acct_contract_different', 1),
            new GatewayConnectionData(PaymentGatewayFixtures::CONNECTION_ID, PaymentGatewayProviderCodeEnum::Stripe, PaymentGatewayEnvironmentEnum::Test, 'acct_contract_test', 2),
        ];

        $captureGateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $capture = new CapturePaymentCommand(
            PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('contract:capture:drift'),
            new ProviderObjectReference('provider-payment-capture-drift'), new Money(600, 'JPY'),
            new RedactedData(['reason_code' => 'contract']),
        );
        $captureGateway->capture($capture);
        $captureDrifts = [
            new CapturePaymentCommand($capture->connection, $capture->request, new ProviderObjectReference('provider-payment-capture-other'), $capture->money, $capture->metadata),
            new CapturePaymentCommand($capture->connection, PaymentGatewayFixtures::request('contract:capture:drift', operationId: '0198f608-bf35-79a1-927f-e04ac6eb4c93'), $capture->payment, $capture->money, $capture->metadata),
            new CapturePaymentCommand($capture->connection, $capture->request, $capture->payment, new Money(601, 'JPY'), $capture->metadata),
            new CapturePaymentCommand($capture->connection, $capture->request, $capture->payment, new Money(600, 'USD'), $capture->metadata),
            new CapturePaymentCommand($capture->connection, $capture->request, $capture->payment, $capture->money, new RedactedData(['reason_code' => 'different'])),
            ...array_map(fn (GatewayConnectionData $connection) => new CapturePaymentCommand($connection, $capture->request, $capture->payment, $capture->money, $capture->metadata), $connectionDrifts),
        ];
        foreach ($captureDrifts as $drift) {
            $this->assertIdempotencyMismatch(fn () => $captureGateway->capture($drift));
        }
        self::assertSame(1, $this->providerCallCount($captureGateway, 'capture'));

        $cancelGateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $cancel = new CancelPaymentCommand(
            PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('contract:cancel:drift'),
            new ProviderObjectReference('provider-payment-cancel-drift'), new RedactedData(['reason_code' => 'contract']),
        );
        $cancelGateway->cancel($cancel);
        $cancelDrifts = [
            new CancelPaymentCommand($cancel->connection, $cancel->request, new ProviderObjectReference('provider-payment-cancel-other'), $cancel->metadata),
            new CancelPaymentCommand($cancel->connection, PaymentGatewayFixtures::request('contract:cancel:drift', operationId: '0198f608-bf35-79a1-927f-e04ac6eb4c94'), $cancel->payment, $cancel->metadata),
            new CancelPaymentCommand($cancel->connection, $cancel->request, $cancel->payment, new RedactedData(['reason_code' => 'different'])),
            ...array_map(fn (GatewayConnectionData $connection) => new CancelPaymentCommand($connection, $cancel->request, $cancel->payment, $cancel->metadata), $connectionDrifts),
        ];
        foreach ($cancelDrifts as $drift) {
            $this->assertIdempotencyMismatch(fn () => $cancelGateway->cancel($drift));
        }
        self::assertSame(1, $this->providerCallCount($cancelGateway, 'cancel'));

        $refundGateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $refund = new RefundPaymentCommand(
            PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('contract:refund:drift'),
            new ProviderObjectReference('provider-payment-refund-drift'), new Money(250, 'JPY'),
            new RedactedData(['reason_code' => 'contract']),
        );
        $refundGateway->refund($refund);
        $refundDrifts = [
            new RefundPaymentCommand($refund->connection, $refund->request, new ProviderObjectReference('provider-payment-refund-other'), $refund->money, $refund->metadata),
            new RefundPaymentCommand($refund->connection, PaymentGatewayFixtures::request('contract:refund:drift', operationId: '0198f608-bf35-79a1-927f-e04ac6eb4c95'), $refund->payment, $refund->money, $refund->metadata),
            new RefundPaymentCommand($refund->connection, $refund->request, $refund->payment, new Money(251, 'JPY'), $refund->metadata),
            new RefundPaymentCommand($refund->connection, $refund->request, $refund->payment, new Money(250, 'USD'), $refund->metadata),
            new RefundPaymentCommand($refund->connection, $refund->request, $refund->payment, $refund->money, new RedactedData(['reason_code' => 'different'])),
            ...array_map(fn (GatewayConnectionData $connection) => new RefundPaymentCommand($connection, $refund->request, $refund->payment, $refund->money, $refund->metadata), $connectionDrifts),
        ];
        foreach ($refundDrifts as $drift) {
            $this->assertIdempotencyMismatch(fn () => $refundGateway->refund($drift));
        }
        self::assertSame(1, $this->providerCallCount($refundGateway, 'refund'));
    }

    final public function test_webhook_verification_is_idempotent_and_redacted(): void
    {
        $gateway = $this->gateway(PaymentGatewayFixtures::fullCapability());
        $connection = PaymentGatewayFixtures::connection();
        $command = $this->signedWebhook(
            $connection, 'event-1', 'provider-payment-1', $this->expectedRawStatus(ProviderScenario::WebhookProcessing), 'webhook-secret', 'contract:webhook:1',
        );
        $first = $gateway->verifyWebhook($command);
        $second = $gateway->verifyWebhook($command);

        $this->assertEquivalent($first, $second);
        self::assertSame(1, $this->providerCallCount($gateway, 'verify_webhook'));
        self::assertSame('event-1', $first->providerEventId);
        $serialized = json_encode($first, JSON_THROW_ON_ERROR).print_r($first, true).var_export($first, true);
        self::assertStringNotContainsString('webhook-secret', $serialized);
        self::assertStringContainsString($this->expectedRawStatus(ProviderScenario::WebhookProcessing), $serialized);

        try {
            $gateway->verifyWebhook($this->signedWebhook(
                $connection, 'event-1', 'provider-payment-1', $this->expectedRawStatus(ProviderScenario::WebhookAlternate), 'other-secret', 'contract:webhook:conflict',
            ));
            self::fail('Same event ID with a different body must conflict.');
        } catch (WebhookPayloadConflict $error) {
            self::assertSame('PAYMENT_WEBHOOK_PAYLOAD_CONFLICT', $error->errorCode);
        }
        self::assertSame(1, $this->providerCallCount($gateway, 'verify_webhook'));

        $otherConnection = PaymentGatewayFixtures::connection('0198f608-84ce-7629-b653-00dc291475a1');
        $otherConnectionEvent = $gateway->verifyWebhook($this->signedWebhook(
            $otherConnection, 'event-1', 'provider-payment-1', $this->expectedRawStatus(ProviderScenario::WebhookProcessing), 'webhook-secret', 'contract:webhook:other-connection',
        ));
        $this->assertEquivalent($first, $otherConnectionEvent);
        self::assertSame(2, $this->providerCallCount($gateway, 'verify_webhook'));

        try {
            $gateway->verifyWebhook($this->invalidWebhook($connection, 'contract:webhook:invalid'));
            self::fail('Invalid signature must fail verification.');
        } catch (WebhookVerificationFailed $error) {
            self::assertSame('PAYMENT_WEBHOOK_VERIFICATION_FAILED', $error->errorCode);
        }
        self::assertSame(2, $this->providerCallCount($gateway, 'verify_webhook'));
    }

    final public function test_unsupported_mutations_return_the_typed_capability_error(): void
    {
        $gateway = $this->gateway(PaymentGatewayFixtures::unsupportedMutationCapability());
        $cases = [
            [GatewayCapability::Authorize, 'create', fn () => $gateway->preparePayment($this->createCommand(operation: PaymentAttemptOperationEnum::Authorize))],
            [GatewayCapability::Capture, 'capture', fn () => $gateway->capture(new CapturePaymentCommand(
                PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('unsupported:capture'),
                new ProviderObjectReference('provider-payment-1'), new Money(1, 'JPY'),
            ))],
            [GatewayCapability::Cancel, 'cancel', fn () => $gateway->cancel(new CancelPaymentCommand(
                PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('unsupported:cancel'),
                new ProviderObjectReference('provider-payment-1'),
            ))],
            [GatewayCapability::Refund, 'refund', fn () => $gateway->refund(new RefundPaymentCommand(
                PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('unsupported:refund'),
                new ProviderObjectReference('provider-payment-1'), new Money(1, 'JPY'),
            ))],
            [GatewayCapability::RetrieveRefund, 'retrieve_refund', fn () => $gateway->retrieveRefund(new RetrieveRefundCommand(
                PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('unsupported:retrieve-refund'),
                new ProviderObjectReference('provider-refund-1'),
            ))],
        ];

        foreach ($cases as [$operation, $callName, $call]) {
            try {
                $call();
                self::fail("{$operation->value} should be unsupported.");
            } catch (UnsupportedPaymentOperation $error) {
                self::assertSame('PAYMENT_OPERATION_UNSUPPORTED', $error->errorCode);
                self::assertSame($operation, $error->operation);
                self::assertSame('contract.fake.card.v1', $error->capabilityId);
                self::assertSame(3, $error->capabilityRevision);
                self::assertSame('2026-06-30', $error->apiVersion);
                self::assertSame(0, $this->providerCallCount($gateway, $callName));
            }
        }

        $unverified = $this->gateway(PaymentGatewayFixtures::unverifiedCapability());
        $unverifiedCases = [
            [GatewayCapability::Create, 'create', fn () => $unverified->preparePayment($this->createCommand())],
            [GatewayCapability::RetrievePayment, 'retrieve_payment', fn () => $unverified->retrievePayment(new RetrievePaymentCommand(
                PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('unsupported:retrieve-payment'),
                new ProviderObjectReference('provider-payment-1'),
            ))],
            [GatewayCapability::WebhookVerification, 'verify_webhook', fn () => $unverified->verifyWebhook($this->signedWebhook(
                PaymentGatewayFixtures::connection(), 'unsupported-event', 'provider-payment-1', 'pending', 'secret', 'unsupported:webhook',
            ))],
        ];

        foreach ($unverifiedCases as [$operation, $callName, $call]) {
            try {
                $call();
                self::fail("{$operation->value} should fail closed without verified capability evidence.");
            } catch (UnsupportedPaymentOperation $error) {
                self::assertSame($operation, $error->operation);
                self::assertSame(0, $this->providerCallCount($unverified, $callName));
            }
        }

        $retrieveUnavailable = $this->gateway(PaymentGatewayFixtures::capabilityWithout(GatewayCapability::RetrievePayment));
        try {
            $retrieveUnavailable->retrievePayment(new RetrievePaymentCommand(
                PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('verified:retrieve-unavailable'),
                new ProviderObjectReference('provider-payment-1'),
            ));
            self::fail('Retrieve payment must check its operation-specific capability.');
        } catch (UnsupportedPaymentOperation $error) {
            self::assertSame(GatewayCapability::RetrievePayment, $error->operation);
            self::assertSame(0, $this->providerCallCount($retrieveUnavailable, 'retrieve_payment'));
        }

        $webhookUnavailable = $this->gateway(PaymentGatewayFixtures::capabilityWithout(GatewayCapability::WebhookVerification));
        try {
            $webhookUnavailable->verifyWebhook($this->signedWebhook(
                PaymentGatewayFixtures::connection(), 'verified-webhook-unavailable', 'provider-payment-1',
                $this->expectedRawStatus(ProviderScenario::WebhookProcessing), 'secret', 'verified:webhook-unavailable',
            ));
            self::fail('Webhook verification must check its operation-specific capability.');
        } catch (UnsupportedPaymentOperation $error) {
            self::assertSame(GatewayCapability::WebhookVerification, $error->operation);
            self::assertSame(0, $this->providerCallCount($webhookUnavailable, 'verify_webhook'));
        }
    }

    final public function test_authorize_decline_authentication_and_timeout_are_provider_neutral(): void
    {
        $capabilities = PaymentGatewayFixtures::fullCapability();

        $authorizedGateway = $this->gateway($capabilities);
        $authorized = $authorizedGateway->preparePayment($this->createCommand(operation: PaymentAttemptOperationEnum::Authorize));
        self::assertSame(PaymentAttemptStateEnum::Succeeded, $authorized->state);
        self::assertSame($this->expectedRawStatus(ProviderScenario::CreateSucceeded), $authorized->rawStatus);

        $decliningGateway = $this->gatewayWithFault($capabilities, ProviderFault::Decline);
        $declined = $decliningGateway->preparePayment($this->createCommand());
        self::assertSame(PaymentAttemptStateEnum::Failed, $declined->state);
        self::assertSame($this->expectedRawStatus(ProviderScenario::Declined), $declined->rawStatus);
        $this->assertEquivalent($declined, $decliningGateway->preparePayment($this->createCommand()));
        self::assertSame(1, $this->providerCallCount($decliningGateway, 'create'));

        $timeoutGateway = $this->gatewayWithFault($capabilities, ProviderFault::Timeout);
        $timedOut = $timeoutGateway->preparePayment($this->createCommand());
        self::assertSame(PaymentAttemptStateEnum::ReconciliationRequired, $timedOut->state);
        self::assertSame($this->expectedRawStatus(ProviderScenario::TimedOut), $timedOut->rawStatus);
        $this->assertEquivalent($timedOut, $timeoutGateway->preparePayment($this->createCommand()));
        self::assertSame(1, $this->providerCallCount($timeoutGateway, 'create'));

        $authGateway = $this->gatewayWithFault($capabilities, ProviderFault::Authentication);
        try {
            $authGateway->preparePayment($this->createCommand());
            self::fail('Provider authentication failure must be typed.');
        } catch (GatewayAuthenticationFailed $error) {
            self::assertSame('PAYMENT_GATEWAY_AUTHENTICATION_FAILED', $error->errorCode);
            $safe = $error->getMessage().print_r($error, true).var_export($error, true);
            self::assertStringNotContainsString('client-source-secret', $safe);
            self::assertStringNotContainsString('Stripe\\Stripe', $safe);
        }
    }

    final public function test_capture_cancel_and_refund_faults_are_safe_and_idempotent(): void
    {
        $capabilities = PaymentGatewayFixtures::fullCapability();
        $operations = [
            [
                'name' => 'capture',
                'timeout_status' => ProviderScenario::CaptureTimedOut,
                'decline_status' => ProviderScenario::CaptureDeclined,
                'call' => fn (PaymentGatewayContract $gateway) => $gateway->capture(new CapturePaymentCommand(
                    PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('fault:capture'),
                    new ProviderObjectReference('fault-payment-capture'), new Money(500, 'JPY'),
                )),
            ],
            [
                'name' => 'cancel',
                'timeout_status' => ProviderScenario::CancelTimedOut,
                'decline_status' => ProviderScenario::CancelDeclined,
                'call' => fn (PaymentGatewayContract $gateway) => $gateway->cancel(new CancelPaymentCommand(
                    PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('fault:cancel'),
                    new ProviderObjectReference('fault-payment-cancel'),
                )),
            ],
            [
                'name' => 'refund',
                'timeout_status' => ProviderScenario::RefundTimedOut,
                'decline_status' => ProviderScenario::RefundDeclined,
                'call' => fn (PaymentGatewayContract $gateway) => $gateway->refund(new RefundPaymentCommand(
                    PaymentGatewayFixtures::connection(), PaymentGatewayFixtures::request('fault:refund'),
                    new ProviderObjectReference('fault-payment-refund'), new Money(200, 'JPY'),
                )),
            ],
        ];

        foreach ($operations as $operation) {
            $timeoutGateway = $this->gatewayWithFault($capabilities, ProviderFault::Timeout);
            $timedOut = $operation['call']($timeoutGateway);
            self::assertSame('reconciliation_required', $timedOut->state->value);
            self::assertSame($this->expectedRawStatus($operation['timeout_status']), $timedOut->rawStatus);
            $this->assertEquivalent($timedOut, $operation['call']($timeoutGateway));
            self::assertSame(1, $this->providerCallCount($timeoutGateway, $operation['name']));

            $decliningGateway = $this->gatewayWithFault($capabilities, ProviderFault::Decline);
            $declined = $operation['call']($decliningGateway);
            self::assertSame('failed', $declined->state->value);
            self::assertSame($this->expectedRawStatus($operation['decline_status']), $declined->rawStatus);
            $this->assertEquivalent($declined, $operation['call']($decliningGateway));
            self::assertSame(1, $this->providerCallCount($decliningGateway, $operation['name']));

            $authGateway = $this->gatewayWithFault($capabilities, ProviderFault::Authentication);
            try {
                $operation['call']($authGateway);
                self::fail("{$operation['name']} authentication failure must be typed.");
            } catch (GatewayAuthenticationFailed $error) {
                self::assertSame('PAYMENT_GATEWAY_AUTHENTICATION_FAILED', $error->errorCode);
                self::assertStringNotContainsString('client-source-secret', $error->getMessage().var_export($error, true));
            }
        }
    }

    private function createCommand(
        ?Money $money = null,
        ?GatewayConnectionData $connection = null,
        PaymentAttemptOperationEnum $operation = PaymentAttemptOperationEnum::Sale,
        string $optionId = PaymentGatewayFixtures::OPTION_ID,
        int $policyRevision = 9,
        string $paymentSource = 'client-source-secret',
        ?RedactedData $metadata = null,
        string $orderId = PaymentGatewayFixtures::ORDER_ID,
        PaymentChannelEnum $channel = PaymentChannelEnum::CustomerWeb,
        string $operationId = '0198f608-1581-7a43-b20c-55470be9b6e9',
    ): CreatePaymentCommand {
        return new CreatePaymentCommand(
            $connection ?? PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request(operationId: $operationId),
            $orderId,
            $optionId,
            $money ?? new Money(1000, 'JPY'),
            $operation,
            $channel,
            $policyRevision,
            $paymentSource,
            $metadata ?? new RedactedData([
                'order_code' => 'ORDER_1000',
                'reason_code' => 'contract_fixture',
            ]),
        );
    }

    private function assertIdempotencyMismatch(callable $call): void
    {
        try {
            $call();
            self::fail('Provider-affecting payload drift must throw.');
        } catch (IdempotencyPayloadMismatch $error) {
            self::assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $error->errorCode);
        }
    }

    private function assertEquivalent(mixed $expected, mixed $actual): void
    {
        self::assertJsonStringEqualsJsonString(
            json_encode($expected, JSON_THROW_ON_ERROR),
            json_encode($actual, JSON_THROW_ON_ERROR),
        );
    }
}
