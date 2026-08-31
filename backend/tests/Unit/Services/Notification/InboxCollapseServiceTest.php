<?php

/**
 * Plan-023 M5 T5.1 — InboxCollapseService unit coverage.
 *
 * Scenarios:
 *   M5-1: group by aggregation_key, NULL key stays singleton
 *   M5-2: count matches; sample[≤3] preserved
 *   M5-3: ordering follows latest notifications.created_at DESC
 */

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\InboxCollapseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
});

function seedNotification(string $orgId, User $recipient, ?string $aggKey, ?DateTimeInterface $at = null): Notification
{
    $n = Notification::factory()->create([
        'organization_id' => $orgId,
        'aggregation_key' => $aggKey,
        'created_at' => $at ?? now(),
    ]);
    NotificationRecipient::factory()->create([
        'notification_id' => $n->id,
        'recipient_type' => 'User',
        'recipient_id' => $recipient->id,
    ]);

    return $n;
}

it('M5-1: groups by aggregation_key and keeps NULL-key rows as singletons', function () {
    seedNotification($this->orgId, $this->user, 'stock.alert.low:warehouse:w1', now()->subMinutes(3));
    seedNotification($this->orgId, $this->user, 'stock.alert.low:warehouse:w1', now()->subMinutes(2));
    seedNotification($this->orgId, $this->user, 'stock.alert.low:warehouse:w1', now()->subMinutes(1));
    seedNotification($this->orgId, $this->user, null, now()->subMinutes(4));     // singleton

    $paginator = app(InboxCollapseService::class)->collapseFor($this->user);
    $items = $paginator->items();

    expect(count($items))->toBe(2);

    $collapsed = collect($items)->firstWhere('is_collapsed', true);
    expect($collapsed['count'])->toBe(3);
    expect($collapsed['aggregation_key'])->toBe('stock.alert.low:warehouse:w1');
    expect($collapsed['sample'])->toHaveCount(3);

    $singleton = collect($items)->firstWhere('is_collapsed', false);
    expect($singleton['count'])->toBe(1);
    expect($singleton['aggregation_key'])->toBeNull();
});

it('M5-2: collapsed sample caps at 3 even when bucket has more rows', function () {
    foreach (range(1, 5) as $i) {
        seedNotification($this->orgId, $this->user, 'order.status_changed:branch:b1:status:dining', now()->subMinutes($i));
    }

    $paginator = app(InboxCollapseService::class)->collapseFor($this->user);
    $bucket = collect($paginator->items())->first();

    expect($bucket['count'])->toBe(5);
    expect($bucket['sample'])->toHaveCount(3);
});

it('M5-3: bucket order follows latest notifications.created_at DESC', function () {
    seedNotification($this->orgId, $this->user, 'key.a', now()->subHours(2));    // key.a latest
    seedNotification($this->orgId, $this->user, 'key.a', now()->subMinutes(10)); // key.a latest now
    seedNotification($this->orgId, $this->user, 'key.b', now()->subMinutes(5));  // key.b latest — newer than key.a

    $items = app(InboxCollapseService::class)->collapseFor($this->user)->items();

    $keys = collect($items)->pluck('aggregation_key')->all();
    expect($keys[0])->toBe('key.b');     // -5m
    expect($keys[1])->toBe('key.a');     // -10m
});
