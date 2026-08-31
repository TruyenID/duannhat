<?php

/**
 * NotificationChannelJob unit tests (plan-012 T3.7).
 */

use App\Contracts\NotificationChannelContract;
use App\Jobs\NotificationChannelJob;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\Channels\DeliveryResult;
use App\Services\Notification\NotificationChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);

    $this->notification = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => [],
        'priority' => 'normal',
    ]);
    $this->recipient = NotificationRecipient::query()->create([
        'notification_id' => $this->notification->id,
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
    ]);
    $this->delivery = NotificationDelivery::query()->create([
        'notification_recipient_id' => $this->recipient->id,
        'channel' => NotificationChannelEnum::InApp,
        'status' => NotificationDeliveryStatusEnum::Pending,
    ]);
});

function stubResolverReturning(DeliveryResult $result): NotificationChannelResolver
{
    $stub = new class($result) implements NotificationChannelContract
    {
        public function __construct(public readonly DeliveryResult $result) {}

        public function name(): string
        {
            return 'in_app';
        }

        public function send($n, $r): DeliveryResult
        {
            return $this->result;
        }
    };

    $resolver = app(NotificationChannelResolver::class);
    $resolver->register($stub);

    return $resolver;
}

it('marks delivery status=sent on success', function () {
    $resolver = stubResolverReturning(DeliveryResult::sent(providerRef: 'ref-1'));

    (new NotificationChannelJob($this->delivery->id))->handle($resolver);

    $this->delivery->refresh();
    expect($this->delivery->status)->toBe(NotificationDeliveryStatusEnum::Sent);
    expect($this->delivery->sent_at)->not->toBeNull();
    expect($this->delivery->provider_ref)->toBe('ref-1');
    expect($this->delivery->attempts)->toBe(1);
});

it('marks delivery status=skipped with reason recorded', function () {
    $resolver = stubResolverReturning(DeliveryResult::skipped('no email address'));

    (new NotificationChannelJob($this->delivery->id))->handle($resolver);

    $this->delivery->refresh();
    expect($this->delivery->status)->toBe(NotificationDeliveryStatusEnum::Skipped);
    expect($this->delivery->error)->toBe('no email address');
});

it('throws on failed result so the queue retries', function () {
    $resolver = stubResolverReturning(DeliveryResult::failed('smtp down'));

    expect(fn () => (new NotificationChannelJob($this->delivery->id))->handle($resolver))
        ->toThrow(RuntimeException::class);

    $this->delivery->refresh();
    expect($this->delivery->error)->toBe('smtp down');
    // Status stays `pending` until `failed()` hook finalises the
    // terminal state when retries are exhausted.
    expect($this->delivery->status)->toBe(NotificationDeliveryStatusEnum::Pending);
});

it('failed() hook finalises status=failed with the exception message', function () {
    (new NotificationChannelJob($this->delivery->id))->failed(new RuntimeException('last attempt crashed'));

    $this->delivery->refresh();
    expect($this->delivery->status)->toBe(NotificationDeliveryStatusEnum::Failed);
    expect($this->delivery->failed_at)->not->toBeNull();
    expect($this->delivery->error)->toBe('last attempt crashed');
});

it('uses the backoff schedule [10, 60, 300]', function () {
    $job = new NotificationChannelJob($this->delivery->id);
    expect($job->backoff())->toBe([10, 60, 300]);
    expect($job->tries)->toBe(3);
});

/**
 * Counting resolver — records how many times any channel `send` is invoked so
 * the concurrency-dedup tests can assert a delivery is sent at most once.
 */
function countingResolver(object $counter): NotificationChannelResolver
{
    $stub = new class($counter) implements NotificationChannelContract
    {
        public function __construct(public readonly object $counter) {}

        public function name(): string
        {
            return 'in_app';
        }

        public function send($n, $r): DeliveryResult
        {
            $this->counter->sends++;

            return DeliveryResult::sent(providerRef: 'ref-'.$this->counter->sends);
        }
    };

    $resolver = app(NotificationChannelResolver::class);
    $resolver->register($stub);

    return $resolver;
}

it('does not double-send when a concurrent worker holds the claim', function () {
    // Simulate worker A having just claimed the row (attempts bumped, lease
    // freshly stamped, status still pending mid-send).
    $this->delivery->update([
        'attempts' => 1,
        'status' => NotificationDeliveryStatusEnum::Pending,
        'last_attempted_at' => now(),
    ]);

    $counter = new stdClass;
    $counter->sends = 0;

    // Worker B (a duplicate from orphan recovery) starts within the lease.
    (new NotificationChannelJob($this->delivery->id))->handle(countingResolver($counter));

    expect($counter->sends)->toBe(0); // B lost the claim and sent nothing
    $this->delivery->refresh();
    expect($this->delivery->attempts)->toBe(1); // untouched by B
    expect($this->delivery->status)->toBe(NotificationDeliveryStatusEnum::Pending);
});

it('does not re-send a delivery that already reached a terminal status', function () {
    // Orphan recovery re-queues a delivery that worker A already completed.
    $this->delivery->update([
        'attempts' => 1,
        'status' => NotificationDeliveryStatusEnum::Sent,
        'last_attempted_at' => now()->subMinutes(30), // lease long expired
        'sent_at' => now()->subMinutes(30),
    ]);

    $counter = new stdClass;
    $counter->sends = 0;

    (new NotificationChannelJob($this->delivery->id))->handle(countingResolver($counter));

    expect($counter->sends)->toBe(0); // status guard blocks the resend
    $this->delivery->refresh();
    expect($this->delivery->attempts)->toBe(1);
});

it('lets a legitimate retry re-claim once the lease has expired', function () {
    // Worker A's attempt failed; its lease is older than the backoff window.
    $this->delivery->update([
        'attempts' => 1,
        'status' => NotificationDeliveryStatusEnum::Pending,
        'last_attempted_at' => now()->subSeconds(30),
    ]);

    $counter = new stdClass;
    $counter->sends = 0;

    (new NotificationChannelJob($this->delivery->id))->handle(countingResolver($counter));

    expect($counter->sends)->toBe(1); // retry proceeds
    $this->delivery->refresh();
    expect($this->delivery->attempts)->toBe(2); // claim incremented
    expect($this->delivery->status)->toBe(NotificationDeliveryStatusEnum::Sent);
});
