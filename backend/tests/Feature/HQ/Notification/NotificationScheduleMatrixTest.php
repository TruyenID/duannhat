<?php

/**
 * User-requested matrix coverage for /hq/[brandSlug]/notifications/schedules.
 *
 * Maps directly to the test cases provided:
 *   - P1 Tải list scheduled           → index() with rows present
 *   - P1 Filter pending/sent          → ?status=active vs ?status=completed
 *   - P0 Cancel scheduled (pending)   → DELETE on active row
 *   - P1 Edit scheduled (pending)     → PATCH on active row
 *   - P1 Không sửa được "sent"        → PATCH on terminal (completed) row → 422
 */

use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'matrix-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->audience = NotificationAudience::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Matrix audience',
        'rule' => ['combinator' => 'or', 'rules' => [['type' => 'user', 'user_ids' => []]]],
        'is_system' => false,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/schedules";
});

function matrixSchedule(array $overrides = []): NotificationSchedule
{
    return NotificationSchedule::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'template_key' => 'stock.alert.low',
        'audience_id' => test()->audience->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
        'rrule' => 'FREQ=DAILY;BYHOUR=9;BYMINUTE=0',
        'timezone' => 'Asia/Tokyo',
        'starts_at' => now()->subDay(),
        'next_occurrence_at' => now()->addHours(2),
        'status' => 'active',
    ], $overrides));
}

it('P1 list-scheduled: returns active + paused + completed + cancelled rows for the brand', function () {
    matrixSchedule(['status' => 'active']);
    matrixSchedule(['status' => 'paused', 'next_occurrence_at' => null]);
    matrixSchedule(['status' => 'completed', 'next_occurrence_at' => null]);
    matrixSchedule(['status' => 'cancelled', 'next_occurrence_at' => null]);

    $response = $this->actingAs($this->user)->getJson($this->base);

    $response->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'status', 'rrule', 'timezone', 'template_key', 'next_occurrence_at', 'audience_id', 'audience_name']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(4);
    // Pin: every row carries the audience display name so the list UI
    // can render the time/audience/status table without a second fetch.
    expect($response->json('data.0.audience_name'))->toBe('Matrix audience');
});

it('P1 filter-pending: ?status=active returns only active (pending) rows', function () {
    matrixSchedule(['status' => 'active']);
    matrixSchedule(['status' => 'completed', 'next_occurrence_at' => null]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}?status=active");

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.status'))->toBe('active');
});

it('P1 filter-sent: ?status=completed returns only completed (sent) rows', function () {
    matrixSchedule(['status' => 'active']);
    matrixSchedule(['status' => 'completed', 'next_occurrence_at' => null]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}?status=completed");

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.status'))->toBe('completed');
});

it('P0 cancel-scheduled: DELETE on active (pending) row outside freeze window → 204 + status=cancelled', function () {
    $schedule = matrixSchedule(['next_occurrence_at' => now()->addHours(2)]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$schedule->id}")
        ->assertNoContent();

    expect($schedule->fresh()->status)->toBe('cancelled');
});

it('P0 cancel-scheduled freeze: DELETE inside 60s window → 422 within_freeze_window', function () {
    $schedule = matrixSchedule(['next_occurrence_at' => now()->addSeconds(30)]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$schedule->id}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'within_freeze_window');

    expect($schedule->fresh()->status)->toBe('active');
});

it('P1 edit-scheduled: PATCH active row updates rrule and recomputes next_occurrence_at', function () {
    $schedule = matrixSchedule();
    $original = $schedule->next_occurrence_at?->toIso8601String();

    $response = $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$schedule->id}", [
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR;BYHOUR=18',
            'timezone' => 'Asia/Tokyo',
            'starts_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertOk();

    expect($response->json('data.rrule'))->toBe('FREQ=WEEKLY;BYDAY=FR;BYHOUR=18');
    expect($schedule->fresh()->next_occurrence_at?->toIso8601String())->not->toBe($original);
});

it('P1 edit-scheduled paused row: PATCH still allowed (paused is non-terminal)', function () {
    $schedule = matrixSchedule(['status' => 'paused']);

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$schedule->id}", [
            'priority' => 'high',
        ])
        ->assertOk()
        ->assertJsonPath('data.priority', 'high');
});

it('P1 not-editing-sent: PATCH on completed (sent) row → 422 terminal', function () {
    $schedule = matrixSchedule(['status' => 'completed', 'next_occurrence_at' => null]);

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$schedule->id}", [
            'priority' => 'high',
        ])
        ->assertStatus(422);

    expect($schedule->fresh()->priority)->toBe('normal');
});

it('P1 not-editing-sent: PATCH on cancelled row → 422 terminal', function () {
    $schedule = matrixSchedule(['status' => 'cancelled', 'next_occurrence_at' => null]);

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$schedule->id}", [
            'priority' => 'high',
        ])
        ->assertStatus(422);

    expect($schedule->fresh()->priority)->toBe('normal');
});

it('P1 not-editing-sent show: GET on completed row still returns the row (read-only)', function () {
    $schedule = matrixSchedule(['status' => 'completed', 'next_occurrence_at' => null]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});
