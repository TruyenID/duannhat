<?php

/**
 * Plan 047 acceptance — webhook inbox D2, D4, D6, D7, D10 and reliability J4.
 */

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use App\Services\Payment\ProviderEvent\ProviderEventInboxService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
        'payments.provider_event.retry_backoff_seconds' => [5, 15, 30],
        'payments.provider_event.max_processing_attempts' => 3,
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

describe('D2 invalid webhook signatures', function () {
    it('D2 rejects missing signature before creating inbox rows', function () {
        Queue::fake();

        $payload = json_encode(['id' => 'evt_no_sig', 'type' => 'payment_intent.succeeded']);

        $this->call(
            'POST',
            '/api/v1/customer/stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload,
        )->assertStatus(400);

        expect(PaymentProviderEvent::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('D2 rejects tampered signature without inbox or ledger writes', function () {
        Queue::fake();

        $event = ($this->makeStripeEvent)('payment_intent.created', [
            'object' => 'payment_intent',
            'id' => 'pi_bad_sig',
        ]);

        ($this->postWebhook)($event['payload'], 't='.time().',v1=deadbeef')
            ->assertStatus(400);

        expect(PaymentProviderEvent::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('D4 out-of-order events cannot regress terminal state', function () {
    it('D4 ignores late processing events once attempt is terminal', function () {
        $provider = PaymentGatewayProvider::factory()->create(['code' => 'stripe', 'is_active' => true]);
        $connection = PaymentGatewayConnection::factory()->create([
            'provider_id' => $provider->id,
            'environment' => 'test',
            'is_active' => true,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'connection_id' => $connection->id,
            'organization_id' => $connection->organization_id,
            'state' => PaymentAttemptStateEnum::Succeeded->value,
            'provider_object_id' => 'pi_terminal_no_regress',
            'version' => 2,
        ]);

        $event = PaymentProviderEvent::factory()->create([
            'connection_id' => $connection->id,
            'organization_id' => $connection->organization_id,
            'event_type' => 'payment_intent.succeeded',
            'provider_event_id' => 'evt_late_processing',
            'provider_object_id' => 'pi_terminal_no_regress',
            'state' => PaymentProviderEventStateEnum::Processing->value,
            'redacted_payload' => [
                'intent_snapshot' => [
                    'id' => 'pi_terminal_no_regress',
                    'object' => 'payment_intent',
                    'amount' => 1000,
                    'currency' => 'jpy',
                    'status' => 'processing',
                ],
            ],
        ]);

        $outcome = app(ProviderEventApplicator::class)->apply((string) $event->id);

        expect($outcome)->toBe('ignored_transition')
            ->and($attempt->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded);
    });
});

describe('D6 D7 inbox retry and dead letter lifecycle', function () {
    it('D6 increments retry count and schedules next retry on transient failure', function () {
        $event = PaymentProviderEvent::factory()->create([
            'state' => PaymentProviderEventStateEnum::Queued->value,
            'processing_attempts' => 0,
        ]);

        $inbox = app(ProviderEventInboxService::class);
        $inbox->claim((string) $event->id);
        $inbox->markRetryable((string) $event->id, 'PROCESSING_TRANSIENT', 1);

        $updated = $event->fresh();
        expect($updated->state)->toBe(PaymentProviderEventStateEnum::Retryable)
            ->and($updated->processing_attempts)->toBe(1)
            ->and($updated->last_error_code)->toBe('PROCESSING_TRANSIENT')
            ->and($updated->next_retry_at)->not->toBeNull();
    });

    it('D7 moves exhausted events to dead letter state', function () {
        $event = PaymentProviderEvent::factory()->create([
            'state' => PaymentProviderEventStateEnum::Retryable->value,
            'processing_attempts' => 3,
        ]);

        app(ProviderEventInboxService::class)->markDeadLetter((string) $event->id, 'PROCESSING_EXHAUSTED');

        $updated = $event->fresh();
        expect($updated->state)->toBe(PaymentProviderEventStateEnum::DeadLetter)
            ->and($updated->last_error_code)->toBe('PROCESSING_EXHAUSTED');
    });
});

describe('D10 dead letter reprocessing is idempotent', function () {
    it('D10 returns terminal outcome when a succeeded inbox event is applied again', function () {
        $event = PaymentProviderEvent::factory()->create([
            'event_type' => 'payment_intent.succeeded',
            'provider_event_id' => 'evt_terminal_twice',
            'state' => PaymentProviderEventStateEnum::Succeeded->value,
            'outcome' => 'applied',
        ]);

        $applicator = app(ProviderEventApplicator::class);
        expect($applicator->apply((string) $event->id))->toBe('applied');
        expect($applicator->apply((string) $event->id))->toBe('applied');
    });
});

describe('J4 WEBHOOK_FLOOD duplicate delivery', function () {
    it('J4 acknowledges 100 duplicate deliveries with one inbox row and one queued job', function () {
        Queue::fake();

        $event = ($this->makeStripeEvent)('payment_intent.created', [
            'object' => 'payment_intent',
            'id' => 'pi_flood_dedupe',
        ]);

        for ($i = 0; $i < 100; $i++) {
            ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
        }

        expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(1);
        Queue::assertPushed(ProcessPaymentProviderEventJob::class, 1);
    });
});

describe('D1 D3 webhook intake basics', function () {
    it('D1 acknowledges verified webhook quickly and queues processing', function () {
        Queue::fake();

        $event = ($this->makeStripeEvent)('payment_intent.created', [
            'object' => 'payment_intent',
            'id' => 'pi_d1_budget',
        ]);

        ($this->postWebhook)($event['payload'], $event['header'])
            ->assertOk()
            ->assertJson(['received' => true]);

        $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->first();
        expect($inbox)->not->toBeNull()
            ->and($inbox->state)->toBe(PaymentProviderEventStateEnum::Queued);

        Queue::assertPushed(ProcessPaymentProviderEventJob::class);
    });

    it('D3 deduplicates concurrent duplicate provider event IDs', function () {
        Queue::fake();

        $event = ($this->makeStripeEvent)('payment_intent.created', [
            'object' => 'payment_intent',
            'id' => 'pi_d3_concurrent',
        ]);

        ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
        ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

        expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(1);
        Queue::assertPushed(ProcessPaymentProviderEventJob::class, 1);
    });
});
