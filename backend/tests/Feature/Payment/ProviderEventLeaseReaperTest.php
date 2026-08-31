<?php

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Services\Payment\ProviderEvent\ProviderEventInboxService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * Plan 047 Gate 3 — the provider-event inbox lease reaper.
 *
 * A worker that dies after `claim()` (which sets state=Processing + a 5-min
 * lease) but before markSucceeded/markRetryable leaves the row stuck in
 * Processing forever — a confirmed webhook then never settles its order.
 * `reclaimExpiredLeases()` + the scheduled `payments:process-provider-events`
 * close that gap.
 */
function stuckProcessingEvent(): PaymentProviderEvent
{
    return PaymentProviderEvent::factory()->create([
        'state' => PaymentProviderEventStateEnum::Processing->value,
        'lease_token' => 'tok',
        'lease_expires_at' => now()->subMinutes(10),
        'next_retry_at' => null,
        'last_error_code' => null,
    ]);
}

function eventState(PaymentProviderEvent $event): string
{
    $state = $event->fresh()->state;

    return $state instanceof PaymentProviderEventStateEnum ? $state->value : (string) $state;
}

it('reclaims a processing event whose lease has expired, leaving fresh leases alone', function () {
    $stuck = stuckProcessingEvent();
    $leased = PaymentProviderEvent::factory()->create([
        'state' => PaymentProviderEventStateEnum::Processing->value,
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $reclaimed = app(ProviderEventInboxService::class)->reclaimExpiredLeases();

    expect($reclaimed)->toBe(1)
        ->and(eventState($stuck))->toBe('retryable')
        ->and($stuck->fresh()->lease_expires_at)->toBeNull()
        ->and($stuck->fresh()->lease_token)->toBeNull()
        ->and($stuck->fresh()->last_error_code)->toBe('lease_expired')
        ->and($stuck->fresh()->next_retry_at)->not->toBeNull()
        // the still-leased row is untouched
        ->and(eventState($leased))->toBe('processing');
});

it('the processor command reclaims the stranded event and re-dispatches it', function () {
    Queue::fake();
    $stuck = stuckProcessingEvent();

    $this->artisan('payments:process-provider-events')
        ->assertExitCode(0);

    // Reclaimed to retryable, then dispatched for processing.
    expect(eventState($stuck))->toBe('retryable');
    Queue::assertPushed(ProcessPaymentProviderEventJob::class);
});

it('registers payments:process-provider-events on the scheduler', function () {
    $matches = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'process-provider-events'));

    expect($matches)->not->toBeEmpty();
});
