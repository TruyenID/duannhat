<?php

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\Enums\GatewayNextActionType;
use App\Services\Payment\Gateway\Stripe\StripeConnectScope;
use App\Services\Payment\Gateway\Stripe\StripeLifecycleMapper;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;

beforeEach(function () {
    $this->mapper = new StripeLifecycleMapper;
    $this->legacyConnection = new GatewayConnectionData(
        LegacyGlobalStripeConnection::CONNECTION_ID,
        PaymentGatewayProviderCodeEnum::Stripe,
        PaymentGatewayEnvironmentEnum::Test,
        LegacyGlobalStripeConnection::MERCHANT_REFERENCE,
        1,
    );
    $this->connectConnection = new GatewayConnectionData(
        '0198f608-84ce-7629-b653-00dc291475a1',
        PaymentGatewayProviderCodeEnum::Stripe,
        PaymentGatewayEnvironmentEnum::Test,
        'acct_1ConnectTest001',
        1,
    );
});

it('maps every canonical stripe payment intent status without regression', function (string $rawStatus, PaymentAttemptStateEnum $expected) {
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_lifecycle_'.$rawStatus,
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => $rawStatus,
    ]);

    expect($this->mapper->mapIntentState($rawStatus, $intent))->toBe($expected)
        ->and($this->mapper->mapPaymentIntent($intent, $this->legacyConnection)->state)->toBe($expected)
        ->and($this->mapper->mapPaymentIntent($intent, $this->legacyConnection)->rawStatus)->toBe($rawStatus);
})->with([
    ['requires_payment_method', PaymentAttemptStateEnum::ProviderPending],
    ['requires_confirmation', PaymentAttemptStateEnum::ProviderPending],
    ['requires_capture', PaymentAttemptStateEnum::ProviderPending],
    ['requires_action', PaymentAttemptStateEnum::ActionRequired],
    ['processing', PaymentAttemptStateEnum::Processing],
    ['succeeded', PaymentAttemptStateEnum::Succeeded],
    ['canceled', PaymentAttemptStateEnum::Canceled],
    ['unknown_future_status', PaymentAttemptStateEnum::ReconciliationRequired],
]);

it('maps requires_payment_method with terminal card errors to failed', function () {
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_failed_card',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'requires_payment_method',
        'last_payment_error' => ['code' => 'card_declined'],
    ]);

    expect($this->mapper->mapPaymentIntent($intent, $this->legacyConnection)->state)
        ->toBe(PaymentAttemptStateEnum::Failed);
});

it('maps requires_action to provider sdk next action with client secret', function () {
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_action',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'requires_action',
        'client_secret' => 'pi_action_secret_test',
        'next_action' => [
            'type' => 'use_stripe_sdk',
        ],
    ]);

    $result = $this->mapper->mapPaymentIntent($intent, $this->legacyConnection);

    expect($result->state)->toBe(PaymentAttemptStateEnum::ActionRequired)
        ->and($result->nextAction?->type)->toBe(GatewayNextActionType::ProviderSdk)
        ->and($result->nextAction?->payload()['client_secret'])->toBe('pi_action_secret_test');
});

it('maps stripe refund lifecycle states', function (string $rawStatus, PaymentRefundStateEnum $expected) {
    $refund = Refund::constructFrom([
        'id' => 're_lifecycle_'.$rawStatus,
        'object' => 'refund',
        'amount' => 500,
        'currency' => 'jpy',
        'status' => $rawStatus,
    ]);

    $result = $this->mapper->mapRefund($refund, $this->legacyConnection);

    expect($result->state)->toBe($expected)
        ->and($result->rawStatus)->toBe($rawStatus);
})->with([
    ['pending', PaymentRefundStateEnum::Pending],
    ['succeeded', PaymentRefundStateEnum::Succeeded],
    ['failed', PaymentRefundStateEnum::Failed],
    ['canceled', PaymentRefundStateEnum::Canceled],
    ['unknown_future_status', PaymentRefundStateEnum::ReconciliationRequired],
]);

it('scopes connect account identity in normalized summaries', function () {
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_connect',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'processing',
        'metadata' => ['idempotency_key' => 'idem-connect-1'],
    ]);

    $legacy = $this->mapper->mapPaymentIntent($intent, $this->legacyConnection);
    $connect = $this->mapper->mapPaymentIntent($intent, $this->connectConnection);

    expect($legacy->summary->jsonSerialize()['connect_account_scope'])->toBe('platform')
        ->and($connect->summary->jsonSerialize()['connect_account_scope'])->toBe('acct_1ConnectTest001')
        ->and($connect->summary->jsonSerialize()['provider_idempotency_key'])->toBe('idem-connect-1')
        ->and(StripeConnectScope::requestOptions($this->connectConnection))->toBe(['stripe_account' => 'acct_1ConnectTest001'])
        ->and(StripeConnectScope::requestOptions($this->legacyConnection))->toBe([]);
});

it('maps refund webhook objects to normalized verified events', function () {
    $payloadHash = hash('sha256', 'fixture');
    $event = Event::constructFrom([
        'id' => 'evt_refund_1',
        'object' => 'event',
        'type' => 'charge.refund.updated',
        'created' => time(),
        'data' => [
            'object' => [
                'id' => 're_webhook_1',
                'object' => 'refund',
                'status' => 'succeeded',
                'payment_intent' => 'pi_webhook_1',
            ],
        ],
    ]);

    $verified = $this->mapper->mapVerifiedEvent($event, $payloadHash, $this->connectConnection);

    expect($verified->providerEventId)->toBe('evt_refund_1')
        ->and($verified->eventType)->toBe('charge.refund.updated')
        ->and($verified->payment?->value)->toBe('pi_webhook_1')
        ->and($verified->refund?->value)->toBe('re_webhook_1')
        ->and($verified->payload->jsonSerialize()['raw_status'])->toBe('succeeded')
        ->and($verified->payload->jsonSerialize()['connect_account_scope'])->toBe('acct_1ConnectTest001');
});
