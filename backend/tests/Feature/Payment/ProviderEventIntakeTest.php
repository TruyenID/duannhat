<?php

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
    ]);

    $this->makeStripeEvent = function (string $type, array $dataObject, string $secret = 'whsec_test_secret_xyz'): array {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $dataObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [
            'payload' => $payload,
            'header' => "t={$timestamp},v1={$signature}",
            'event_id' => json_decode($payload, true)['id'],
        ];
    };

    $this->postWebhook = fn (string $payload, string $signature) => $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );
});

it('acknowledges a verified webhook and dispatches inbox processing', function () {
    Queue::fake();

    $order = CustomerOrder::factory()->create([
        'stripe_payment_intent_id' => 'pi_intake_ack',
        'total_amount' => 1200,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_intake_ack',
        'amount' => 1200,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])
        ->assertOk()
        ->assertJson(['received' => true]);

    $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->first();
    expect($inbox)->not->toBeNull()
        ->and($inbox->event_type)->toBe('payment_intent.succeeded')
        ->and($inbox->state)->toBe(PaymentProviderEventStateEnum::Queued);

    Queue::assertPushed(ProcessPaymentProviderEventJob::class, fn (ProcessPaymentProviderEventJob $job) => $job->providerEventRecordId === $inbox->id);
});

it('deduplicates provider events by connection and provider event id', function () {
    Queue::fake();

    $event = ($this->makeStripeEvent)('payment_intent.created', [
        'object' => 'payment_intent',
        'id' => 'pi_dedupe',
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(1);

    Queue::assertPushed(ProcessPaymentProviderEventJob::class, 1);
});

it('processes payment_intent.succeeded end-to-end via sync queue', function () {
    $order = CustomerOrder::factory()->create([
        'stripe_payment_intent_id' => 'pi_sync_process',
        'total_amount' => 900,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_sync_process',
        'amount' => 900,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Closed)
        ->and(OrderPayment::query()->where('reference_no', 'pi_sync_process')->exists())->toBeTrue();

    $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->first();
    expect($inbox?->state)->toBe(PaymentProviderEventStateEnum::Succeeded)
        ->and($inbox?->outcome)->toBe('applied');
});
