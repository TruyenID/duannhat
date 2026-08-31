<?php

/**
 * Plan-023 M3 T3.4 — artisan tick command + scheduler registration.
 *
 * Scenarios:
 *   - command exits 0 + reports processed count
 *   - command actually advances a due schedule (smoke through to T3.3)
 *   - scheduler registers `notifications.recurring.tick` with 5-min cadence
 */

use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('M3-T3.4 command exits 0 with no due schedules', function () {
    $exit = Artisan::call('notifications:tick-recurring-schedules');

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('processed 0 schedule(s)');
});

it('M3-T3.4 command advances a due schedule', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
        'slug' => 'tick-cmd-'.Str::random(4),
    ]);
    $user = User::factory()->create(['console_organization_id' => $orgId]);

    $audience = NotificationAudience::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'name' => 'Test audience',
        'rule' => ['combinator' => 'or', 'rules' => [['type' => 'user', 'user_ids' => [$user->id]]]],
        'is_system' => false,
    ]);

    $schedule = NotificationSchedule::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'template_key' => 'stock.alert.low',
        'audience_id' => $audience->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
        'rrule' => 'FREQ=DAILY;BYHOUR=9;BYMINUTE=0',
        'timezone' => 'Asia/Tokyo',
        'starts_at' => now()->subWeek(),
        'next_occurrence_at' => now()->subMinute(),
        'status' => 'active',
    ]);

    $previousNext = $schedule->next_occurrence_at;

    $exit = Artisan::call('notifications:tick-recurring-schedules');

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('processed 1 schedule(s)');

    $schedule->refresh();
    expect($schedule->last_occurrence_at)->not->toBeNull();
    expect($schedule->next_occurrence_at?->greaterThan($previousNext))->toBeTrue();
});

it('M3-T3.4 scheduler registers notifications.recurring.tick every 5 minutes', function () {
    $events = collect(Schedule::events())->filter(
        fn ($e) => str_contains((string) $e->description, 'notifications.recurring.tick')
            || str_contains((string) $e->command, 'notifications:tick-recurring-schedules'),
    );

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('*/5 * * * *');
});
