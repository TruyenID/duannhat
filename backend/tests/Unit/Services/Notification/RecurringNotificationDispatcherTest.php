<?php

/**
 * Plan-023 M3 T3.3 — RecurringNotificationDispatcher unit coverage.
 *
 * Scenarios:
 *   M3-2: tick materialises a Notification for a due schedule + advances
 *         next_occurrence_at to the next RRULE date
 *   M3-4: occurrences_remaining decrement + flip to completed at 0
 *   M3-8: idempotency — re-running tick for the same next_occurrence_at
 *         does not duplicate the Notification (relies on the
 *         idempotency_key unique index plan-008 T1.2 enforces)
 *   M3-extra: tick skips rows with status != active
 *   M3-extra: tick skips rows whose next_occurrence_at is null
 *   M3-extra: end-of-RRULE flips status to completed
 */

use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\RecurringNotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'sched-'.Str::random(4),
    ]);

    // Audience that resolves to exactly one user — keeps the dispatcher
    // happy without spinning up a full role pivot fixture.
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->audience = NotificationAudience::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Test audience',
        'description' => 'Single user fixture',
        'rule' => ['combinator' => 'or', 'rules' => [['type' => 'user', 'user_ids' => [$this->user->id]]]],
        'is_system' => false,
    ]);
});

function makeSchedule(array $overrides = []): NotificationSchedule
{
    $startsAt = $overrides['starts_at'] ?? now()->subWeek();
    $nextAt = $overrides['next_occurrence_at'] ?? now()->subMinutes(2);

    return NotificationSchedule::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'template_key' => 'stock.alert.low',
        'audience_id' => test()->audience->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
        'params' => ['warehouse_name' => 'Tokyo HQ'],
        'rrule' => 'FREQ=DAILY;BYHOUR=9;BYMINUTE=0',
        'timezone' => 'Asia/Tokyo',
        'starts_at' => $startsAt,
        'next_occurrence_at' => $nextAt,
        'status' => 'active',
    ], $overrides));
}

it('M3-2: tick materialises a Notification for a due schedule + advances next_occurrence_at', function () {
    $schedule = makeSchedule();

    $dispatcher = app(RecurringNotificationDispatcher::class);
    $processed = $dispatcher->tick();

    expect($processed)->toBe(1);
    expect(Notification::where('subject_type', 'NotificationSchedule')->where('subject_id', $schedule->id)->count())->toBe(1);

    $schedule->refresh();
    expect($schedule->last_occurrence_at)->not->toBeNull();
    expect($schedule->next_occurrence_at)->not->toBeNull();
    expect($schedule->next_occurrence_at->greaterThan(now()))->toBeTrue();
});

it('M3-8: re-running tick at the same instant is a no-op (idempotency_key unique)', function () {
    $schedule = makeSchedule();
    $dispatcher = app(RecurringNotificationDispatcher::class);

    $dispatcher->tick();
    $afterFirst = Notification::where('subject_type', 'NotificationSchedule')->count();

    // Simulate the worker re-launching and finding the same row before
    // anyone else advances it: forcibly roll next_occurrence_at back to
    // the prior value, then tick again with a clock just past that.
    $schedule->refresh();
    $schedule->next_occurrence_at = $schedule->last_occurrence_at;
    $schedule->saveQuietly();

    $dispatcher->tick(CarbonImmutable::instance($schedule->next_occurrence_at->addSecond()));

    expect(Notification::where('subject_type', 'NotificationSchedule')->count())->toBe($afterFirst);
});

it('M3-4: occurrences_remaining decrement + flip to completed at zero', function () {
    $schedule = makeSchedule(['occurrences_remaining' => 1]);

    app(RecurringNotificationDispatcher::class)->tick();

    $schedule->refresh();
    expect($schedule->status)->toBe('completed');
    expect($schedule->next_occurrence_at)->toBeNull();
});

it('tick skips schedules with status != active', function () {
    makeSchedule(['status' => 'paused']);

    $processed = app(RecurringNotificationDispatcher::class)->tick();

    expect($processed)->toBe(0);
    expect(Notification::where('subject_type', 'NotificationSchedule')->count())->toBe(0);
});

it('tick skips schedules with null next_occurrence_at', function () {
    makeSchedule(['next_occurrence_at' => null, 'status' => 'completed']);

    $processed = app(RecurringNotificationDispatcher::class)->tick();

    expect($processed)->toBe(0);
});

it('flips status to completed when next_occurrence_at would exceed ends_at', function () {
    $schedule = makeSchedule([
        'next_occurrence_at' => now()->subMinute(),
        'ends_at' => now()->addMinutes(2),
        'rrule' => 'FREQ=DAILY',
    ]);

    app(RecurringNotificationDispatcher::class)->tick();

    $schedule->refresh();
    expect($schedule->status)->toBe('completed');
    expect($schedule->next_occurrence_at)->toBeNull();
});
