<?php

/**
 * Plan-023 M3 T3.9 — freeze-window cancel guard.
 *
 * Scenarios:
 *   M3-11: cancel within 60s of next_occurrence_at throws 422
 *   M3-11a: cancel > 60s out flips status to cancelled
 *   M3-11b: cancelling an already-completed schedule is a no-op
 *   M3-11c: cancelling a schedule with null next_occurrence_at is a no-op
 */

use App\Exceptions\NotificationException;
use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\NotificationScheduleCanceller;
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
        'slug' => 'cancel-'.Str::random(4),
    ]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->audience = NotificationAudience::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Cancel test',
        'rule' => ['combinator' => 'or', 'rules' => [['type' => 'user', 'user_ids' => [$this->user->id]]]],
        'is_system' => false,
    ]);
});

function makeCancellerSchedule(array $overrides = []): NotificationSchedule
{
    return NotificationSchedule::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'template_key' => 'stock.alert.low',
        'audience_id' => test()->audience->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
        'rrule' => 'FREQ=DAILY',
        'timezone' => 'Asia/Tokyo',
        'starts_at' => now()->subWeek(),
        'next_occurrence_at' => now()->addHour(),
        'status' => 'active',
    ], $overrides));
}

it('M3-11a: cancels a schedule whose next occurrence is more than 60s out', function () {
    $schedule = makeCancellerSchedule(['next_occurrence_at' => now()->addMinutes(5)]);

    $result = app(NotificationScheduleCanceller::class)->cancel($schedule);

    expect($result->status)->toBe('cancelled');
    expect($result->next_occurrence_at)->toBeNull();
});

it('M3-11: refuses to cancel inside the 60s freeze window', function () {
    $schedule = makeCancellerSchedule(['next_occurrence_at' => now()->addSeconds(30)]);

    expect(fn () => app(NotificationScheduleCanceller::class)->cancel($schedule))
        ->toThrow(NotificationException::class, 'freeze window');

    $schedule->refresh();
    expect($schedule->status)->toBe('active');
});

it('refuses to cancel exactly at the 59s boundary (must be ≥60s away)', function () {
    $schedule = makeCancellerSchedule();
    $now = CarbonImmutable::now();
    $schedule->next_occurrence_at = $now->addSeconds(59);
    $schedule->saveQuietly();

    expect(fn () => app(NotificationScheduleCanceller::class)->cancel($schedule, $now))
        ->toThrow(NotificationException::class, 'freeze window');
});

it('accepts cancel at the 60s boundary', function () {
    $schedule = makeCancellerSchedule();
    $now = CarbonImmutable::now();
    $schedule->next_occurrence_at = $now->addSeconds(60);
    $schedule->saveQuietly();

    $result = app(NotificationScheduleCanceller::class)->cancel($schedule, $now);

    expect($result->status)->toBe('cancelled');
});

it('M3-11b: cancelling an already-completed schedule is a no-op', function () {
    $schedule = makeCancellerSchedule(['status' => 'completed', 'next_occurrence_at' => null]);

    $result = app(NotificationScheduleCanceller::class)->cancel($schedule);

    expect($result->status)->toBe('completed');
});

it('M3-11c: cancelling a schedule with null next_occurrence_at is allowed', function () {
    $schedule = makeCancellerSchedule(['next_occurrence_at' => null, 'status' => 'paused']);

    $result = app(NotificationScheduleCanceller::class)->cancel($schedule);

    expect($result->status)->toBe('cancelled');
});
