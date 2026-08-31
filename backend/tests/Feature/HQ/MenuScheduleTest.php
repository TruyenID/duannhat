<?php

/**
 * Plan 014 — MenuScheduleTest
 *
 * Covers:
 *   Happy H1  — GET  .../schedules with no schedules → 200 with empty data[]
 *   Happy H2  — POST .../schedules with valid payload → 201 with schedule resource
 *   Happy H3  — PUT  .../schedules/{id} → 200 with end_time updated in DB
 *   Happy H4  — DELETE .../schedules/{id} → 204 and deleted_at is set (soft delete)
 *
 *   Validation V1  — POST start_time == end_time → 422 on end_time
 *   Validation V2  — POST end_time < start_time → 422 on end_time
 *   Validation V3  — POST days_of_week=0 → 422
 *   Validation V4  — POST days_of_week=128 → 422
 *   Validation V5  — POST days_of_week=-1 → 422
 *   Validation V6  — POST missing start_time → 422
 *   Validation V7  — POST missing end_time → 422
 *   Validation V8  — PUT only end_time that violates existing start_time → 422
 *   Validation V9  — PUT only start_time that violates existing end_time → 422
 *
 *   Auth A1 — unauthenticated GET → 401
 *   Auth A2 — cross-org user GET → 403
 *   Auth A3 — unauthenticated POST → 401
 *
 *   Error E1 — PUT with schedule from different menu → 404 (scoped binding)
 *   Error E2 — DELETE already-soft-deleted schedule → 404
 *
 *   Side effect SE1 — POST records created_by_id = acting user's id
 */

use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
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
        'slug' => 'acme-sched-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    // An Active menu belonging to this org/brand (branch_id null avoids the FK constraint)
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'status' => 'Active',
        'valid_from' => null,
        'valid_to' => null,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/menus/{$this->menu->id}/schedules";
});

// =============================================================================
//  Happy path
// =============================================================================

describe('happy path', function () {
    it('returns 200 with an empty data array when no schedules exist', function () {
        $this->actingAs($this->user)
            ->getJson($this->base)
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('creates a schedule and returns 201 with the resource', function () {
        $response = $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '06:00',
                'end_time' => '10:30',
                'days_of_week' => 127,
            ]);

        $response->assertCreated();

        expect($response->json('data.days_of_week'))->toBe(127)
            ->and($response->json('data.is_active'))->toBeTrue()
            ->and($response->json('data.menu_id'))->toBe($this->menu->id);
    });

    it('updates end_time and returns 200 with the new value persisted', function () {
        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("{$this->base}/{$schedule->id}", [
                'end_time' => '11:00',
            ]);

        $response->assertOk();

        $fresh = MenuSchedule::findOrFail($schedule->id);
        expect($fresh->end_time)->toBe('11:00:00');
    });

    it('soft-deletes a schedule and returns 204 with deleted_at set', function () {
        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$schedule->id}")
            ->assertNoContent();

        expect(
            MenuSchedule::withTrashed()->findOrFail($schedule->id)->deleted_at
        )->not->toBeNull();
    });
});

// =============================================================================
//  Validation
// =============================================================================

describe('validation', function () {
    it('returns 422 when start_time equals end_time', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '10:00',
                'end_time' => '10:00',
                'days_of_week' => 127,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
    });

    it('returns 422 when end_time is before start_time', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '10:00',
                'end_time' => '09:00',
                'days_of_week' => 127,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
    });

    it('returns 422 when days_of_week is 0', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '08:00',
                'end_time' => '12:00',
                'days_of_week' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days_of_week']);
    });

    it('returns 422 when days_of_week is 128 (exceeds 7-bit bitmask)', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '08:00',
                'end_time' => '12:00',
                'days_of_week' => 128,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days_of_week']);
    });

    it('returns 422 when days_of_week is negative', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '08:00',
                'end_time' => '12:00',
                'days_of_week' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days_of_week']);
    });

    it('returns 422 when start_time is missing', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'end_time' => '12:00',
                'days_of_week' => 127,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    });

    it('returns 422 when end_time is missing', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '08:00',
                'days_of_week' => 127,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
    });

    it('returns 422 on PUT when only end_time is sent and it violates the existing start_time', function () {
        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '10:00:00',
            'end_time' => '14:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$schedule->id}", [
                'end_time' => '09:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
    });

    it('returns 422 on PUT when only start_time is sent and it violates the existing end_time', function () {
        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$schedule->id}", [
                'start_time' => '13:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    });
});

// =============================================================================
//  Authorization
// =============================================================================

describe('authorization', function () {
    it('returns 401 for unauthenticated GET', function () {
        $this->getJson($this->base)->assertUnauthorized();
    });

    // NOTE: The actual MenuPolicy has no role-based restrictions — only org-boundary
    // checks (console_organization_id comparison via the organization relation).
    // Therefore a "staff" user from the SAME org can access schedules just fine.
    // This test uses a user from a DIFFERENT org to cover the 403 path.
    it('returns 403 when a user from a different org accesses the schedule list', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);

        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-'.Str::random(4),
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create([
            'console_organization_id' => $otherOrgId,
        ]);
        grantOrgAccess($otherUser, $otherOrgId);

        // otherUser hits THIS brand's menu schedule endpoint → org mismatch → 403
        $this->actingAs($otherUser)
            ->getJson($this->base)
            ->assertForbidden();
    });

    it('returns 401 for unauthenticated POST', function () {
        $this->postJson($this->base, [
            'start_time' => '08:00',
            'end_time' => '12:00',
            'days_of_week' => 127,
        ])->assertUnauthorized();
    });
});

// =============================================================================
//  Error handling
// =============================================================================

describe('error handling', function () {
    it('returns 404 on PUT when the schedule belongs to a different menu (scoped binding)', function () {
        // menuB belongs to the same org/brand but is a different menu
        $menuB = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => null,
            'status' => 'Active',
            'valid_from' => null,
            'valid_to' => null,
        ]);

        // Schedule created under menuB
        $scheduleForMenuB = MenuSchedule::factory()->create([
            'menu_id' => $menuB->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        // Attempt to update via $this->menu's URL — scope mismatch → 404
        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$scheduleForMenuB->id}", [
                'end_time' => '13:00',
            ])
            ->assertNotFound();
    });

    it('returns 404 on DELETE when the schedule has already been soft-deleted', function () {
        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        // First delete succeeds
        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$schedule->id}")
            ->assertNoContent();

        // Second delete on the same (now soft-deleted) record → 404
        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$schedule->id}")
            ->assertNotFound();
    });
});

// =============================================================================
//  Side effects
// =============================================================================

describe('side effects', function () {
    it('stamps created_by_id with the authenticated user id', function () {
        $response = $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '07:00',
                'end_time' => '11:00',
                'days_of_week' => 127,
            ]);

        $response->assertCreated();

        $schedule = MenuSchedule::findOrFail($response->json('data.id'));
        expect($schedule->created_by_id)->toBe($this->user->id);
    });
});

// =============================================================================
//  TC-MSCH-103 — optional calendar-date range on a schedule
// =============================================================================

describe('date range (TC-MSCH-103)', function () {
    it('creates a schedule with a start/end date and persists + returns them', function () {
        $response = $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '06:00',
                'end_time' => '10:30',
                'days_of_week' => 62, // Mon–Fri
                'start_date' => '2026-06-01',
                'end_date' => '2026-08-31',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.start_date', '2026-06-01')
            ->assertJsonPath('data.end_date', '2026-08-31');

        $schedule = MenuSchedule::findOrFail($response->json('data.id'));
        expect($schedule->start_date->toDateString())->toBe('2026-06-01')
            ->and($schedule->end_date->toDateString())->toBe('2026-08-31');
    });

    it('allows an open-ended range (only start_date)', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '06:00',
                'end_time' => '10:30',
                'days_of_week' => 127,
                'start_date' => '2026-06-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.start_date', '2026-06-01')
            ->assertJsonPath('data.end_date', null);
    });

    it('returns 422 when end_date is before start_date', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '06:00',
                'end_time' => '10:30',
                'days_of_week' => 127,
                'start_date' => '2026-08-31',
                'end_date' => '2026-06-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    });

    it('still creates a schedule with no dates (backward compat)', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'start_time' => '06:00',
                'end_time' => '10:30',
                'days_of_week' => 127,
            ])
            ->assertCreated()
            ->assertJsonPath('data.start_date', null)
            ->assertJsonPath('data.end_date', null);
    });
});

// =============================================================================
//  Reorder (PUT .../schedules/reorder)
// =============================================================================

describe('reorder', function () {
    it('reassigns priority 1..N in the order of the submitted ids', function () {
        // Create 3 schedules; MenuScheduleService::create assigns priority sequentially
        // (max+1), so they start at 1, 2, 3 in creation order.
        $a = MenuSchedule::factory()->create(['menu_id' => $this->menu->id, 'priority' => 1]);
        $b = MenuSchedule::factory()->create(['menu_id' => $this->menu->id, 'priority' => 2]);
        $c = MenuSchedule::factory()->create(['menu_id' => $this->menu->id, 'priority' => 3]);

        // Reverse the order: c, b, a should become priority 1, 2, 3 respectively.
        $this->actingAs($this->user)
            ->putJson("{$this->base}/reorder", [
                'schedule_ids' => [$c->id, $b->id, $a->id],
            ])
            ->assertOk();

        expect($c->refresh()->priority)->toBe(1)
            ->and($b->refresh()->priority)->toBe(2)
            ->and($a->refresh()->priority)->toBe(3);
    });
});
