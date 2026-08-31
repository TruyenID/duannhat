<?php

/**
 * Plan-023 M3 T3.5 — HQ schedules admin API contract tests.
 *
 * Covers the 8 endpoints + the freeze-window cancel guard delegated to
 * NotificationScheduleCanceller. Each test stays narrow — the
 * dispatcher's tick semantics are exercised in the dispatcher unit
 * test, this file only pins HTTP contract.
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
        'slug' => 'sched-hq-'.Str::random(4),
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
        'name' => 'Test audience',
        'rule' => ['combinator' => 'or', 'rules' => [['type' => 'user', 'user_ids' => []]]],
        'is_system' => false,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/schedules";
});

function makeStorePayload(array $overrides = []): array
{
    return array_merge([
        'template_key' => 'stock.alert.low',
        'audience_id' => test()->audience->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
        'rrule' => 'FREQ=DAILY;BYHOUR=9;BYMINUTE=0',
        'timezone' => 'Asia/Tokyo',
        'starts_at' => now()->addHour()->toIso8601String(),
    ], $overrides);
}

it('M3-T3.5: stores a schedule and computes next_occurrence_at', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, makeStorePayload());

    $response->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.template_key', 'stock.alert.low')
        ->assertJsonStructure(['data' => ['id', 'next_occurrence_at', 'next_5_occurrences']]);

    expect(NotificationSchedule::count())->toBe(1);
});

it('M3-T3.5: rejects an invalid RRULE with 422', function () {
    $this->actingAs($this->user)
        ->postJson($this->base, makeStorePayload(['rrule' => 'NOT-A-VALID-RRULE']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('rrule');
});

it('M3-T3.5: rejects an invalid timezone with 422', function () {
    $this->actingAs($this->user)
        ->postJson($this->base, makeStorePayload(['timezone' => 'Mars/Olympus']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('timezone');
});

it('M3-T3.5: lists schedules scoped to the brand', function () {
    $this->actingAs($this->user)->postJson($this->base, makeStorePayload());

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
});

it('M3-T3.5: shows a single schedule with next_5_occurrences', function () {
    $created = $this->actingAs($this->user)->postJson($this->base, makeStorePayload());
    $id = $created->json('data.id');

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonStructure(['data' => ['next_5_occurrences']]);
});

it('M3-T3.5: pauses then resumes a schedule', function () {
    $created = $this->actingAs($this->user)->postJson($this->base, makeStorePayload());
    $id = $created->json('data.id');

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$id}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', 'paused');

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$id}/resume")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('M3-T3.5: cancels a schedule (more than 60s before next occurrence)', function () {
    $created = $this->actingAs($this->user)->postJson($this->base, makeStorePayload([
        'starts_at' => now()->addHours(2)->toIso8601String(),
    ]));
    $id = $created->json('data.id');

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$id}")
        ->assertNoContent();

    expect(NotificationSchedule::find($id)->status)->toBe('cancelled');
});

it('M3-T3.5 / M3-11: rejects cancel within 60s freeze window', function () {
    $schedule = NotificationSchedule::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'template_key' => 'stock.alert.low',
        'audience_id' => $this->audience->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
        'rrule' => 'FREQ=DAILY',
        'timezone' => 'Asia/Tokyo',
        'starts_at' => now()->subDay(),
        'next_occurrence_at' => now()->addSeconds(30),
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$schedule->id}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'within_freeze_window');
});

it('M3-T3.5: preview-rrule returns the next 5 occurrences', function () {
    $response = $this->actingAs($this->user)->postJson("{$this->base}/preview-rrule", [
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR;BYHOUR=9;BYMINUTE=0',
        'timezone' => 'Asia/Tokyo',
        'starts_at' => '2026-05-18T00:00:00+09:00', // Monday
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['occurrences']])
        ->assertJsonCount(5, 'data.occurrences');
});

it('M3-T3.5: cross-brand access returns 404', function () {
    $otherOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrg, 'console_organization_id' => $otherOrg]);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrg,
        'slug' => 'other-'.Str::random(4),
    ]);
    $stranger = NotificationSchedule::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $otherOrg,
        'brand_id' => $otherBrand->id,
        'template_key' => 'stock.alert.low',
        'audience_id' => $this->audience->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
        'rrule' => 'FREQ=DAILY',
        'timezone' => 'UTC',
        'starts_at' => now(),
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$stranger->id}")
        ->assertNotFound();
});

it('M3-T3.5: updates next_occurrence_at when RRULE changes', function () {
    $created = $this->actingAs($this->user)->postJson($this->base, makeStorePayload());
    $id = $created->json('data.id');
    $originalNext = $created->json('data.next_occurrence_at');

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$id}", [
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR;BYHOUR=18',
            'timezone' => 'Asia/Tokyo',
            'starts_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.rrule', 'FREQ=WEEKLY;BYDAY=FR;BYHOUR=18');

    $updated = NotificationSchedule::find($id);
    expect($updated->next_occurrence_at?->toIso8601String())->not->toBe($originalNext);
});

// =============================================================================
// #1666 — ends_at was invisible to the first-occurrence maths
// =============================================================================

/**
 * The controller carried its own RRULE evaluation with `endDate` hard-coded to
 * null, three methods above a correct one in the dispatcher. So a schedule whose
 * window closes before its first occurrence still got a `next_occurrence_at`,
 * and nothing downstream re-checked it: `dueSchedules()` filters on `status` and
 * `next_occurrence_at` only. The broadcast fired after the operator said stop.
 *
 * Monday 09:00 → Monday 23:00 is the whole window; the rule only ever fires on
 * a Friday. Nothing should be pending.
 */
it('#1666: leaves next_occurrence_at null when ends_at closes before the first run', function () {
    $monday = now()->startOfWeek()->addWeek()->setTime(9, 0);

    $response = $this->actingAs($this->user)->postJson($this->base, makeStorePayload([
        'rrule' => 'FREQ=WEEKLY;BYDAY=FR;BYHOUR=18;BYMINUTE=0',
        'starts_at' => $monday->toIso8601String(),
        'ends_at' => $monday->copy()->setTime(23, 0)->toIso8601String(),
    ]));

    $response->assertCreated();

    expect(NotificationSchedule::find($response->json('data.id'))->next_occurrence_at)->toBeNull();
});

/** Shrinking the window on an EDIT has to re-ask the same question. */
it('#1666: clears a stranded next_occurrence_at when an edit shrinks ends_at', function () {
    $monday = now()->startOfWeek()->addWeek()->setTime(9, 0);

    $id = $this->actingAs($this->user)->postJson($this->base, makeStorePayload([
        'rrule' => 'FREQ=WEEKLY;BYDAY=FR;BYHOUR=18;BYMINUTE=0',
        'starts_at' => $monday->toIso8601String(),
    ]))->assertCreated()->json('data.id');

    // A Friday is pending at this point.
    expect(NotificationSchedule::find($id)->next_occurrence_at)->not->toBeNull();

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$id}", [
            'ends_at' => $monday->copy()->setTime(23, 0)->toIso8601String(),
        ])
        ->assertOk();

    expect(NotificationSchedule::find($id)->next_occurrence_at)->toBeNull();
});

/** The window still admits the run — this must NOT over-correct to null. */
it('#1666: keeps next_occurrence_at when ends_at leaves room for the first run', function () {
    $monday = now()->startOfWeek()->addWeek()->setTime(9, 0);

    $id = $this->actingAs($this->user)->postJson($this->base, makeStorePayload([
        'rrule' => 'FREQ=WEEKLY;BYDAY=FR;BYHOUR=18;BYMINUTE=0',
        'starts_at' => $monday->toIso8601String(),
        'ends_at' => $monday->copy()->addWeeks(2)->toIso8601String(),
    ]))->assertCreated()->json('data.id');

    expect(NotificationSchedule::find($id)->next_occurrence_at)->not->toBeNull();
});
