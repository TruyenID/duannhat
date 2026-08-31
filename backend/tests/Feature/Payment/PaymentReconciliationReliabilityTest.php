<?php

use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Services\Payment\ProviderEvent\ProviderEventInboxService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\Payment\StripeFakePaymentGateway;

it('reconcile-attempts dry-run reports due attempts and does not call provider recovery', function () {
    config(['payments.gateway_drivers.stripe' => StripeFakePaymentGateway::class]);
    $provider = PaymentGatewayProvider::factory()->create([
        'code' => 'stripe',
        'is_active' => true,
    ]);
    $connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);

    PaymentAttempt::factory()->create([
        'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
        'next_reconciliation_at' => now()->subMinute(),
        'connection_id' => $connection->id,
        'provider_object_id' => 'pi_reconcile_dry',
    ]);

    $futureAttempt = PaymentAttempt::factory()->create([
        'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
        'next_reconciliation_at' => now()->addMinutes(10),
        'connection_id' => $connection->id,
        'provider_object_id' => 'pi_reconcile_future',
    ]);

    $this->artisan('payments:reconcile-attempts')
        ->expectsOutputToContain('Running in dry-run mode.')
        ->expectsOutputToContain('Found 1 attempt(s) due for reconciliation.')
        ->assertExitCode(0);

    expect(PaymentAttempt::query()->find($futureAttempt->id))->not->toBeNull()
        ->and(PaymentAttempt::query()->where('id', $futureAttempt->id)->first()?->state)
        ->toBe(PaymentAttemptStateEnum::ReconciliationRequired);
});

it('reconcile-attempts --execute calls recovery for due candidates', function () {
    config(['payments.gateway_drivers.stripe' => StripeFakePaymentGateway::class]);
    $provider = PaymentGatewayProvider::factory()->create([
        'code' => 'stripe',
        'is_active' => true,
    ]);
    $connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);

    PaymentAttempt::factory()->create([
        'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
        'next_reconciliation_at' => null,
        'connection_id' => $connection->id,
        'provider_object_id' => 'pi_reconcile_exec_1',
    ]);
    PaymentAttempt::factory()->create([
        'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
        'next_reconciliation_at' => null,
        'connection_id' => $connection->id,
        'provider_object_id' => 'pi_reconcile_exec_2',
    ]);

    $this->artisan('payments:reconcile-attempts', ['--execute' => true])
        ->expectsOutputToContain('Recovered 2 of 2 attempt(s).')
        ->assertExitCode(0);

    $this->assertEquals(2, PaymentAttempt::query()
        ->where('state', PaymentAttemptStateEnum::ReconciliationRequired->value)
        ->where('connection_id', $connection->id)
        ->count());
});

it('#1108: a poisoned attempt does not abort the sweep for the rest of the batch', function () {
    // Unresolvable driver → recoverAttempt throws for every attempt; the
    // sweep must still finish cleanly and report zero recoveries instead of
    // dying on the first candidate.
    config(['payments.gateway_drivers.stripe' => 'App\\DoesNotExist\\BrokenDriver']);
    $provider = PaymentGatewayProvider::factory()->create([
        'code' => 'stripe',
        'is_active' => true,
    ]);
    $connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);

    foreach (['pi_poisoned_1', 'pi_poisoned_2'] as $reference) {
        PaymentAttempt::factory()->create([
            'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
            'next_reconciliation_at' => null,
            'connection_id' => $connection->id,
            'environment' => 'test',
            'provider_object_id' => $reference,
        ]);
    }

    $this->artisan('payments:reconcile-attempts', ['--execute' => true])
        ->expectsOutputToContain('Recovered 0 of 2 attempt(s).')
        ->assertExitCode(0);

    // Backoff bookkeeping: a poisoned attempt must leave the head of the
    // candidate window (NULLs sort first) so it cannot starve healthy ones.
    PaymentAttempt::query()
        ->whereIn('provider_object_id', ['pi_poisoned_1', 'pi_poisoned_2'])
        ->get()
        ->each(function (PaymentAttempt $attempt): void {
            expect($attempt->next_reconciliation_at)->not->toBeNull()
                ->and($attempt->next_reconciliation_at->isFuture())->toBeTrue()
                ->and((int) $attempt->retry_count)->toBeGreaterThan(0);
        });
});

it('reconcile-attempts can handle attempts with provider retrieval results that are not terminal', function () {
    config(['payments.gateway_drivers.stripe' => StripeFakePaymentGateway::class]);
    $provider = PaymentGatewayProvider::factory()->create([
        'code' => 'stripe',
        'is_active' => true,
    ]);
    $connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);

    $attempt = PaymentAttempt::factory()->create([
        'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
        'next_reconciliation_at' => null,
        'connection_id' => $connection->id,
        'provider_object_id' => 'pi_reconcile_exec_3',
    ]);

    $this->artisan('payments:reconcile-attempts', ['--execute' => true])
        ->expectsOutputToContain('Recovered 1 of 1 attempt(s).')
        ->assertExitCode(0);

    $attempt = PaymentAttempt::query()->findOrFail($attempt->id);
    expect($attempt->state)->toBe(PaymentAttemptStateEnum::ReconciliationRequired);
});

it('reconcile-attempts no-op when an attempt cannot be recovered because provider is unlinked', function () {
    PaymentAttempt::factory()->create([
        'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
        'next_reconciliation_at' => null,
        'provider_object_id' => null,
    ]);

    $this->artisan('payments:reconcile-attempts', ['--execute' => true])
        ->expectsOutputToContain('Recovered 0 of 1 attempt(s).')
        ->assertExitCode(0);
});

it('reconcile-attempts returns clean no-op when lock is already held', function () {
    config(['payments.gateway_drivers.stripe' => StripeFakePaymentGateway::class]);
    $provider = PaymentGatewayProvider::factory()->create([
        'code' => 'stripe',
        'is_active' => true,
    ]);
    $connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);

    PaymentAttempt::factory()->create([
        'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
        'next_reconciliation_at' => null,
        'connection_id' => $connection->id,
        'provider_object_id' => 'pi_reconcile_lock',
    ]);

    $lock = Cache::lock('payments:reconcile-attempts', 300);
    expect($lock->get())->toBeTrue();

    $this->artisan('payments:reconcile-attempts', ['--execute' => true])
        ->expectsOutputToContain('Another payments:reconcile-attempts run is already in progress.')
        ->assertExitCode(0);

    expect($lock->get())->toBeFalse();

    $lock->release();
});

it('provider-event job marks event retryable and applies backoff window on transient failure', function () {
    config([
        'payments.provider_event.retry_backoff_seconds' => [10, 30, 60, 120, 300],
    ]);
    Carbon::setTestNow(Carbon::create(2026, 7, 23, 12, 0, 0));

    $event = PaymentProviderEvent::factory()->create([
        'state' => PaymentProviderEventStateEnum::Queued->value,
        'processing_attempts' => 0,
        'next_retry_at' => null,
        'last_error_code' => null,
        'lease_token' => null,
        'lease_expires_at' => null,
    ]);

    $inbox = app(ProviderEventInboxService::class);
    $inbox->claim((string) $event->id);
    $inbox->markRetryable((string) $event->id, 'PROCESSING_TRANSIENT', 1);

    $updated = $event->fresh();

    expect($updated->state)->toBe(PaymentProviderEventStateEnum::Retryable)
        ->and($updated->processing_attempts)->toBe(1)
        ->and($updated->last_error_code)->toBe('PROCESSING_TRANSIENT')
        ->and($updated->next_retry_at)->not()->toBeNull()
        ->and($updated->next_retry_at->timestamp - Carbon::now()->timestamp)->toBe(10);

    $inbox->markRetryable((string) $event->id, 'PROCESSING_TRANSIENT', 2);
    $updated = $event->fresh();

    $gap = $updated->next_retry_at->timestamp - Carbon::now()->timestamp;
    expect($gap)->toBeBetween(29, 31);
});
