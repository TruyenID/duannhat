<?php

/**
 * Plan 014 — BranchMenuScheduleTest (Phase 11 TB-O9)
 *
 * Tests branch schedule override API + getCurrentMenu() COALESCE behaviour.
 *
 * Scenarios:
 *   O1  — GET  no override  → is_overridden=false, effective = HQ values, hq_defaults present
 *   O2  — GET  with override → is_overridden=true, effective=COALESCED, hq_defaults=HQ values
 *   O3  — PUT  start_time only → single DB row; end_time column remains NULL
 *   O4  — PUT  end_time only  → start_time column remains NULL
 *   O5  — Re-PUT same schedule+branch → exactly one DB row; updated values returned
 *   O6  — DELETE override → 204; effective reverts to HQ values
 *   O7  — Double DELETE → 404
 *   O8  — HQ soft-deletes parent schedule → override row cascade-deleted
 *   O9a — Branch staff PUT → 403
 *   O9b — Cross-branch manager PUT → 403
 *   O9c — Own-branch manager PUT → 200
 *   O10 — getCurrentMenu() with branch override end_time="09:00" does NOT resolve at 09:01
 *   O11 — getCurrentMenu() with branch override end_time="09:00" DOES resolve at 08:30
 *   O13 — PUT forbidden field (priority/is_active/start_date/end_date/menu_id/id) → 422; days_of_week is shop-overridable
 *   O14 — sibling branch isolation: A overrides window, B still resolves against HQ default
 *   O15 — HQ soft-deletes schedule: override row remains in DB but effective list excludes it
 *   O16 — Carbon::setTestNow() reflects in getCurrentMenu() (regression for timezone fix)
 *   O17 — by-day matched_start_time/matched_end_time honour branch override
 */

use App\Models\Branch;
use App\Models\BranchScheduleOverride;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Product\MenuService;
use Carbon\Carbon;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\DB; // used by makeShopManager/makeShopStaff helpers
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Helpers

function branchOrgSetup(): array
{
    $orgId = (string) Str::uuid();
    $org = Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'is_active' => true,
        // #1091 — getCurrentMenu now evaluates windows at the BRANCH clock.
        // These tests exercise schedule-override semantics with naive
        // Carbon::setTestNow (UTC) sentinels, so pin the branch to UTC to keep
        // wall clock == test clock; the branch-vs-UTC conversion itself is
        // covered by tests/Feature/Timezone/BusinessTimezoneContractTest.
        'timezone' => 'UTC',
    ]);

    return [$org, $branch, $orgId];
}

function makeShopManager(string $orgId, string $branchId): User
{
    $user = User::factory()->create([
        'console_organization_id' => $orgId,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'shop-manager'],
        ['name' => 'Shop Manager', 'level' => 50]
    );

    // Branch-scoped row satisfies both the middleware IAM check (org_id match) and
    // isShopManager($user, $branchId). An org-level row would incorrectly let this
    // manager pass the policy check for other branches.
    DB::table('role_user_pivots')->insert([
        ['user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $orgId, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $user;
}

function makeShopStaff(string $orgId, string $branchId): User
{
    $user = User::factory()->create([
        'console_organization_id' => $orgId,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'shop-staff'],
        ['name' => 'Shop Staff', 'level' => 10]
    );

    // Grant org-level IAM access (for ResolveBranchFromSlug check).
    $staffOrgRole = Role::firstOrCreate(
        ['slug' => 'staff'],
        ['name' => 'Staff', 'level' => 20]
    );
    DB::table('role_user_pivots')->insert([
        ['user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $orgId, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $user->id, 'role_id' => $staffOrgRole->id, 'organization_id' => $orgId, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $user;
}

// ---------------------------------------------------------------------------
// beforeEach: shared org, branch, menu, schedule, manager user

beforeEach(function () {
    $this->seed(IamSeeder::class);

    [$this->org, $this->branch, $this->orgId] = branchOrgSetup();

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
        'valid_from' => null,
        'valid_to' => null,
    ]);

    $this->schedule = MenuSchedule::factory()->create([
        'menu_id' => $this->menu->id,
        'start_time' => '07:00:00',
        'end_time' => '09:00:00',
        'days_of_week' => 127,
        'is_active' => true,
    ]);

    // Shop manager for this branch.
    $this->manager = makeShopManager($this->orgId, $this->branch->id);

    // Base URL for branch schedule overrides.
    $this->base = "/api/v1/shops/{$this->branch->slug}/menus/{$this->menu->id}/schedules";
    $this->overrideUrl = "{$this->base}/{$this->schedule->id}/override";
    $this->activeUrl = "{$this->base}/{$this->schedule->id}/active";
});

// ===========================================================================
//  O1 — GET, no override
// ===========================================================================

it('O1: GET returns is_overridden=false with effective = HQ values when no override exists', function () {
    $response = $this->actingAs($this->manager)
        ->getJson($this->base)
        ->assertOk();

    $item = $response->json('data.0');

    expect($item['is_overridden'])->toBeFalse()
        ->and($item['start_time'])->toBe('07:00:00')
        ->and($item['end_time'])->toBe('09:00:00')
        ->and($item['hq_defaults']['start_time'])->toBe('07:00:00')
        ->and($item['hq_defaults']['end_time'])->toBe('09:00:00');
});

// ===========================================================================
//  O2 — GET with override
// ===========================================================================

it('O2: GET returns is_overridden=true with COALESCED effective times and raw hq_defaults', function () {
    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $this->schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'start_time' => '07:30:00',
        'end_time' => '10:00:00',
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson($this->base)
        ->assertOk();

    $item = $response->json('data.0');

    expect($item['is_overridden'])->toBeTrue()
        ->and($item['start_time'])->toBe('07:30:00')
        ->and($item['end_time'])->toBe('10:00:00')
        ->and($item['hq_defaults']['start_time'])->toBe('07:00:00')
        ->and($item['hq_defaults']['end_time'])->toBe('09:00:00');
});

// ===========================================================================
//  O3 — PUT start_time only
// ===========================================================================

it('O3: PUT start_time only creates one DB row with end_time=NULL', function () {
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['start_time' => '07:30'])
        ->assertOk();

    $override = BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->first();

    expect($override)->not->toBeNull()
        ->and($override->getRawOriginal('start_time'))->toBe('07:30:00')
        ->and($override->getRawOriginal('end_time'))->toBeNull();

    expect(BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->count())->toBe(1);
});

// ===========================================================================
//  O4 — PUT end_time only
// ===========================================================================

it('O4: PUT end_time only creates one DB row with start_time=NULL', function () {
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['end_time' => '10:00'])
        ->assertOk();

    $override = BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->first();

    expect($override->getRawOriginal('start_time'))->toBeNull()
        ->and($override->getRawOriginal('end_time'))->toBe('10:00:00');
});

// ===========================================================================
//  O5 — Re-PUT same pair (no duplicate)
// ===========================================================================

it('O5: Re-PUT same schedule+branch produces exactly one DB row with updated values', function () {
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['end_time' => '10:00'])
        ->assertOk();

    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['end_time' => '11:00'])
        ->assertOk();

    $count = BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->count();

    $override = BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->first();

    expect($count)->toBe(1)
        ->and($override->getRawOriginal('end_time'))->toBe('11:00:00');
});

// ===========================================================================
//  O6 — DELETE override reverts to HQ values
// ===========================================================================

it('O6: DELETE override returns 204 and effective times revert to HQ values', function () {
    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $this->schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'start_time' => '07:30:00',
        'end_time' => '10:00:00',
    ]);

    $this->actingAs($this->manager)
        ->deleteJson($this->overrideUrl)
        ->assertNoContent();

    expect(BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->exists())->toBeFalse();

    // GET should now show HQ values with is_overridden=false.
    $item = $this->actingAs($this->manager)
        ->getJson($this->base)
        ->assertOk()
        ->json('data.0');

    expect($item['is_overridden'])->toBeFalse()
        ->and($item['start_time'])->toBe('07:00:00');
});

// ===========================================================================
//  O7 — Double DELETE → 404
// ===========================================================================

it('O7: double DELETE returns 404 on the second call', function () {
    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $this->schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)->deleteJson($this->overrideUrl)->assertNoContent();
    $this->actingAs($this->manager)->deleteJson($this->overrideUrl)->assertNotFound();
});

// ===========================================================================
//  O8 — HQ soft-delete cascades to override
// ===========================================================================

it('O8: hard-deleting the parent HQ schedule cascade-deletes the branch override', function () {
    $override = BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $this->schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    // forceDelete() issues DELETE FROM, triggering the FK ON DELETE CASCADE.
    // Soft-delete ($schedule->delete()) only sets deleted_at and does NOT cascade.
    $this->schedule->forceDelete();

    expect(BranchScheduleOverride::find($override->id))->toBeNull();
});

// ===========================================================================
//  O9 — Authorization
// ===========================================================================

describe('authorization', function () {
    it('O9a: branch staff PUT returns 403', function () {
        $staff = makeShopStaff($this->orgId, $this->branch->id);

        $this->actingAs($staff)
            ->putJson($this->overrideUrl, ['end_time' => '10:00'])
            ->assertForbidden();
    });

    it('O9b: shop manager of a different branch returns 403', function () {
        // Another branch in same org.
        $otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        $otherManager = makeShopManager($this->orgId, $otherBranch->id);

        $this->actingAs($otherManager)
            ->putJson($this->overrideUrl, ['end_time' => '10:00'])
            ->assertForbidden();
    });

    it('O9c: own-branch shop manager PUT returns 200 with effective schedule in response', function () {
        $response = $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['end_time' => '10:00'])
            ->assertOk();

        expect($response->json('data.is_overridden'))->toBeTrue()
            ->and($response->json('data.end_time'))->toBe('10:00:00')
            ->and($response->json('data.hq_defaults.end_time'))->toBe('09:00:00');
    });

    it('O9d: PUT with schedule from a different menu returns 404', function () {
        // Schedule that belongs to a different menu.
        $otherMenu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);
        $otherSchedule = MenuSchedule::factory()->create([
            'menu_id' => $otherMenu->id,
            'start_time' => '07:00:00',
            'end_time' => '09:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        // Attempt to override otherSchedule through the URL of $this->menu (cross-menu).
        $crossMenuUrl = "/api/v1/shops/{$this->branch->slug}/menus/{$this->menu->id}/schedules/{$otherSchedule->id}/override";

        $this->actingAs($this->manager)
            ->putJson($crossMenuUrl, ['end_time' => '10:00'])
            ->assertNotFound();
    });
});

// ===========================================================================
//  O10 / O11 — getCurrentMenu() with branch COALESCE
// ===========================================================================

describe('getCurrentMenu branch-aware COALESCE', function () {
    // NOTE: Carbon::setTestNow() does NOT affect MySQL CURRENT_TIME(). All time
    // comparisons in getCurrentMenu() run inside the DB. We use dynamic times
    // relative to the real wall clock so the test is always correct regardless
    // of when it runs.

    it('O10: does NOT return menu when branch override end_time is in the past', function () {
        // HQ: always-open (00:00–23:59). Branch override: end_time = 1 minute ago.
        // After the branch override kicks in, the window is already closed.
        // `getCurrentMenu` binds PHP `now()` (respects APP_TIMEZONE) — use the app
        // Pin the clock to midday: these windows are built from now()±offsets, so
        // near midnight the offsets wrap the date and the window stops containing
        // "now" — the assertion then fails for a reason that has nothing to do with
        // branch overrides. Thursday, and days_of_week=127 covers every day anyway.
        Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));

        // timezone here so the sentinel matches what the query will compare against.
        $pastEnd = Carbon::now()->subMinute()->format('H:i:s');

        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        BranchScheduleOverride::factory()->create([
            'menu_schedule_id' => $schedule->id,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->orgId,
            'start_time' => null,
            'end_time' => $pastEnd,
        ]);

        // Also delete the beforeEach schedule so only our schedule is active.
        $this->schedule->forceDelete();

        $result = app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId);

        expect($result)->toBeNull();
    });

    it('O12: shop GET surfaces schedules added to a branch menu after it was cloned from master', function () {
        // Regression for fix/sync-activate-scheduler:
        // When a menu has master_menu_id set, getEffectiveSchedules previously queried
        // master_menu_id instead of $menu->id, hiding schedules that admin added on the
        // branch menu after cloning.
        $masterMenu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => null,
            'is_master' => true,
            'status' => 'Active',
        ]);

        $this->menu->update(['master_menu_id' => $masterMenu->id]);

        // New schedule lives on the branch menu (added by HQ admin after cloning).
        $newSchedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '12:00:00',
            'end_time' => '14:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        $ids = $this->actingAs($this->manager)
            ->getJson($this->base)
            ->assertOk()
            ->json('data.*.id');

        expect($ids)->toContain($newSchedule->id)
            ->and($ids)->toContain($this->schedule->id);
    });

    it('O11: DOES return menu when branch override end_time extends a closed HQ window', function () {
        // HQ: window ended 1 minute ago. Branch override: end_time = 1 hour from now.
        // The COALESCED end_time is in the future → menu resolves.
        // Historical note: this used to bind on MySQL CURRENT_TIME (UTC); after the
        // timezone fix `getCurrentMenu` binds PHP `now()` (APP_TIMEZONE-aware), so
        // computing the sentinel in the app timezone is now the correct baseline.
        // Pin the clock to midday: these windows are built from now()±offsets, so
        // near midnight the offsets wrap the date and the window stops containing
        // "now" — the assertion then fails for a reason that has nothing to do with
        // branch overrides. Thursday, and days_of_week=127 covers every day anyway.
        Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));

        $pastEnd = Carbon::now()->subMinute()->format('H:i:s');
        $futureEnd = Carbon::now()->addHour()->format('H:i:s');

        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '00:00:00',
            'end_time' => $pastEnd,
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        BranchScheduleOverride::factory()->create([
            'menu_schedule_id' => $schedule->id,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->orgId,
            'start_time' => null,
            'end_time' => $futureEnd,
        ]);

        // Delete the beforeEach schedule (07:00–09:00) so only our schedule is checked.
        $this->schedule->forceDelete();

        $result = app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($this->menu->id);
    });

    it('O11b: the branch-overridden end_time is an inclusive upper bound in getCurrentMenu', function () {
        // HQ window is always-open (00:00–23:59). Branch override closes at exactly
        // 12:00:00. Frozen at 12:00:00 sharp the COALESCED end_time (12:00) >= now
        // (12:00) so the menu resolves; one second later it does not.
        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $this->menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        BranchScheduleOverride::factory()->create([
            'menu_schedule_id' => $schedule->id,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->orgId,
            'start_time' => null,
            'end_time' => '12:00:00',
        ]);

        // Remove the beforeEach 07:00–09:00 schedule so only our window applies.
        $this->schedule->forceDelete();

        // Exactly on the overridden close time → inclusive → returned.
        Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0)); // Thursday 12:00:00 sharp
        expect(app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId))
            ->not->toBeNull();

        // One second past the overridden close time → window closed → null.
        Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 1));
        expect(app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId))->toBeNull();

        Carbon::setTestNow();
    });
});

// ===========================================================================
//  O13 — PUT rejects forbidden fields (shop can only edit start_time / end_time)
// ===========================================================================

describe('O13: forbidden fields on branch override PUT', function () {
    // HQ owns is_active and priority. Shop overrides cover timing,
    // days_of_week and — since #1970 — the calendar window. Anything else must
    // fail loud (422) rather than silently drop, which is Laravel's default
    // when a field is absent from rules().
    it('accepts days_of_week (now shop-overridable)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_time' => '07:30', 'days_of_week' => 127])
            ->assertOk();
    });

    it('rejects priority', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['priority' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);
    });

    it('rejects is_active', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    });

    // #1970 reversed these two: the shop may now narrow or shift the calendar
    // window HQ set. They used to assert 422.
    it('accepts start_date (now shop-overridable)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_date' => '2026-01-01'])
            ->assertOk()
            ->assertJsonPath('data.start_date', '2026-01-01');
    });

    it('accepts end_date (now shop-overridable)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['end_date' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('data.end_date', '2026-12-31');
    });

    it('rejects an end_date before the effective start_date', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_date' => '2026-02-10', 'end_date' => '2026-02-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    });

    it('accepts a single-day window (dates are inclusive bounds)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_date' => '2026-02-10', 'end_date' => '2026-02-10'])
            ->assertOk();
    });

    it('rejects menu_id (branch cannot rebind the override to another menu)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['menu_id' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['menu_id']);
    });

    it('accepts start_time + end_time together (allowlist happy path)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_time' => '07:30', 'end_time' => '10:00'])
            ->assertOk();
    });
});

// ===========================================================================
//  O13b — effective (COALESCED) cross-field validation runs on branch overrides
//  Regression: withValidator() read the 'branch' request attribute, but the
//  ResolveShopFromSlug middleware stores the branch under the 'shop' key. The
//  effective start<end guard short-circuited (branch=null) and never fired, so
//  an inverted override slipped through with a 200 instead of a 422.
// ===========================================================================

describe('O13b: effective time cross-field validation', function () {
    // beforeEach HQ schedule window is 07:00:00–09:00:00.

    it('rejects a start_time override that lands at/after the HQ end_time', function () {
        // Effective start (10:00) >= effective end (HQ 09:00) → 422.
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_time' => '10:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    });

    it('rejects an end_time override that lands at/before the HQ start_time', function () {
        // Effective end (06:00) <= effective start (HQ 07:00) → 422.
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['end_time' => '06:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    });

    it('rejects a start_time override that inverts against an existing end override', function () {
        // Existing override already narrows end to 10:00.
        BranchScheduleOverride::factory()->create([
            'menu_schedule_id' => $this->schedule->id,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->orgId,
            'start_time' => null,
            'end_time' => '10:00:00',
        ]);

        // New partial override sets start to 11:00 → effective 11:00 >= 10:00 → 422.
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_time' => '11:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    });

    it('accepts a valid override inside the HQ window', function () {
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_time' => '07:30', 'end_time' => '08:30'])
            ->assertOk();
    });

    // Zero-length window (effective start == effective end). O13b above only
    // exercises strictly-after / strictly-before; these pin the exact-equality
    // edge. The guard does a RAW STRING compare `$effectiveStart >= $effectiveEnd`
    // (see BranchMenuScheduleUpsertRequest::withValidator), so the result depends
    // on whether the branch sends H:i or H:i:s — the HQ default is stored H:i:s.

    it('rejects a zero-length start_time override sent in H:i:s (matches HQ default format)', function () {
        // Effective start (09:00:00) == effective end (HQ 09:00:00) →
        // "09:00:00" >= "09:00:00" is true → 422. Confirms the >= (equality)
        // branch of the guard fires when the two operands share a format.
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_time' => '09:00:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    });

    it('rejects a zero-length end_time override equal to the effective HQ start_time', function () {
        // Effective end ("07:00") vs effective start (HQ "07:00:00"):
        // "07:00:00" >= "07:00" is true (longer prefix-equal string sorts higher)
        // → 422. Caught here only because the HQ side is the longer string.
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['end_time' => '07:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    });

    it('KNOWN BUG: a zero-length start_time override sent as H:i slips through as 200', function () {
        // Effective start ("09:00", H:i) vs effective end (HQ "09:00:00", H:i:s).
        // The guard string-compares "09:00" >= "09:00:00", which is FALSE (the
        // shorter prefix-equal string sorts lower), so the zero-length window is
        // NOT rejected and a 200 is returned. The HQ create rule (V1) blocks
        // start == end, so branch overrides SHOULD reject this too. Product bug —
        // characterized here, not fixed (out of scope for the coverage pass).
        // Fix candidate: normalise both operands to H:i:s before comparing.
        $this->actingAs($this->manager)
            ->putJson($this->overrideUrl, ['start_time' => '09:00'])
            ->assertStatus(200);
    });
});

// ===========================================================================
//  O18 — Shop activate/pause schedule (PUT .../active)
//  Branch menus own their own schedule rows, so the shop toggles is_active
//  directly on the schedule. Paused windows stop showing menu to customers
//  (getCurrentMenu filters is_active=true) but stay in the shop-manager list.
// ===========================================================================

describe('O18: shop activate/pause schedule', function () {
    it('pauses a schedule (is_active=false) and persists it on the schedule row', function () {
        $response = $this->actingAs($this->manager)
            ->putJson($this->activeUrl, ['is_active' => false])
            ->assertOk();

        expect($response->json('data.is_active'))->toBeFalse()
            ->and($this->schedule->fresh()->is_active)->toBeFalse();
    });

    it('re-activates a paused schedule (is_active=true)', function () {
        $this->schedule->update(['is_active' => false]);

        $response = $this->actingAs($this->manager)
            ->putJson($this->activeUrl, ['is_active' => true])
            ->assertOk();

        expect($response->json('data.is_active'))->toBeTrue()
            ->and($this->schedule->fresh()->is_active)->toBeTrue();
    });

    it('keeps paused schedules in the shop-manager list so they can be re-activated', function () {
        $this->schedule->update(['is_active' => false]);

        $ids = $this->actingAs($this->manager)
            ->getJson($this->base)
            ->assertOk()
            ->json('data.*.id');

        expect($ids)->toContain($this->schedule->id);
    });

    it('hides a paused schedule from the customer-facing getCurrentMenu', function () {
        // HQ window is always-open; pausing it must close the menu for customers.
        $this->schedule->update([
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'is_active' => false,
        ]);

        expect(app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId))->toBeNull();

        // Re-activating restores customer visibility.
        $this->schedule->update(['is_active' => true]);
        expect(app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId))
            ->not->toBeNull();
    });

    it('requires is_active in the body (422 when missing)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->activeUrl, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    });

    it('rejects a non-boolean is_active (422)', function () {
        $this->actingAs($this->manager)
            ->putJson($this->activeUrl, ['is_active' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    });

    it('forbids branch staff from toggling (403)', function () {
        $staff = makeShopStaff($this->orgId, $this->branch->id);

        $this->actingAs($staff)
            ->putJson($this->activeUrl, ['is_active' => false])
            ->assertForbidden();
    });

    it('forbids a shop manager of a different branch (403)', function () {
        $otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        $otherManager = makeShopManager($this->orgId, $otherBranch->id);

        $this->actingAs($otherManager)
            ->putJson($this->activeUrl, ['is_active' => false])
            ->assertForbidden();
    });

    it('returns 404 when the schedule belongs to a different menu', function () {
        $otherMenu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);
        $otherSchedule = MenuSchedule::factory()->create([
            'menu_id' => $otherMenu->id,
            'start_time' => '07:00:00',
            'end_time' => '09:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        $crossMenuUrl = "/api/v1/shops/{$this->branch->slug}/menus/{$this->menu->id}/schedules/{$otherSchedule->id}/active";

        $this->actingAs($this->manager)
            ->putJson($crossMenuUrl, ['is_active' => false])
            ->assertNotFound();
    });
});

// ===========================================================================
//  O14 — sibling branch isolation: A overrides window, B still uses HQ default
// ===========================================================================

it('O14: branch A override does NOT affect getCurrentMenu on branch B', function () {
    // HQ window: 00:00–23:59 (always open). Branch A closes at 1 min ago.
    // Pin the clock to midday: these windows are built from now()±offsets, so
    // near midnight the offsets wrap the date and the window stops containing
    // "now" — the assertion then fails for a reason that has nothing to do with
    // branch overrides. Thursday, and days_of_week=127 covers every day anyway.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));

    // Branch B has no override so still resolves against HQ (open).
    $pastEnd = Carbon::now()->subMinute()->format('H:i:s');

    // Branch B lives in the same org so the schedule (via master menu) applies to both.
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    // Each branch needs its own branch menu that clones the same set of schedules.
    // Simplest fixture: create a menu for branch B pointing at a fresh schedule.
    $menuB = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $branchB->id,
        'status' => 'Active',
        'valid_from' => null,
        'valid_to' => null,
    ]);

    // HQ schedule for branch A menu — always open.
    $scheduleA = MenuSchedule::factory()->create([
        'menu_id' => $this->menu->id,
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'days_of_week' => 127,
        'is_active' => true,
    ]);

    // HQ schedule for branch B menu — always open (mirrors the master).
    MenuSchedule::factory()->create([
        'menu_id' => $menuB->id,
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'days_of_week' => 127,
        'is_active' => true,
    ]);

    // Branch A closes the window (via override) 1 minute ago.
    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $scheduleA->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'start_time' => null,
        'end_time' => $pastEnd,
    ]);

    // Remove the shared beforeEach 07-09 schedule so only always-open windows exist.
    $this->schedule->forceDelete();

    $service = app(MenuService::class);

    expect($service->getCurrentMenu($this->branch->id, $this->orgId))->toBeNull(
        'Branch A overrode end_time to the past → window closed'
    );
    expect($service->getCurrentMenu($branchB->id, $this->orgId))->not->toBeNull(
        'Branch B has no override → resolves HQ always-open window'
    );
});

// ===========================================================================
//  O15 — HQ soft-delete keeps override row but excludes from effective list
// ===========================================================================

it('O15: HQ soft-deletes schedule → override row survives in DB but effective list drops it', function () {
    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $this->schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'start_time' => '07:30:00',
        'end_time' => '10:00:00',
    ]);

    // Soft-delete (not forceDelete) — FK cascade does not fire.
    $this->schedule->delete();

    // Override row is untouched.
    expect(BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->exists())->toBeTrue();

    // Effective list excludes it because the join filters `whereNull deleted_at`.
    $ids = $this->actingAs($this->manager)
        ->getJson($this->base)
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->not->toContain($this->schedule->id);
});

// ===========================================================================
//  O16 — Carbon::setTestNow() reflects in getCurrentMenu() (post-timezone-fix)
// ===========================================================================

it('O16: Carbon::setTestNow() is honoured by getCurrentMenu() after the tz fix', function () {
    // Freeze time inside a schedule window that the beforeEach schedule (07:00–09:00) covers.
    // Bit 4 (Thursday) is set in 127 so days_of_week always matches.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 8, 0, 0)); // Thursday 08:00

    $result = app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId);
    expect($result)->not->toBeNull()->and($result->id)->toBe($this->menu->id);

    // Move outside the window; menu should no longer resolve.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 10, 0, 0)); // Thursday 10:00

    $result = app(MenuService::class)->getCurrentMenu($this->branch->id, $this->orgId);
    expect($result)->toBeNull();

    Carbon::setTestNow();
});

// ===========================================================================
//  O17 — by-day matched times reflect branch override
// ===========================================================================

it('O17: listActiveBranchMenusForShopByDay returns COALESCED matched times', function () {
    // HQ: 08:00–12:00 on Thursday. Branch override: 07:30–13:00.
    // Note: for by-day listing, `menus.branch_id` must equal the query branch id.
    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'master_menu_id' => (string) Str::uuid(), // by-day requires non-null master_menu_id
        'status' => 'Active',
        // MenuFactory randomizes is_master — the by-day query filters
        // is_master=false, so an unpinned value made this test flaky.
        'is_master' => false,
    ]);

    $schedule = MenuSchedule::factory()->create([
        'menu_id' => $branchMenu->id,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
        'days_of_week' => 1 << 4, // Thursday
        'is_active' => true,
    ]);

    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'start_time' => '07:30:00',
        'end_time' => '13:00:00',
    ]);

    $result = app(MenuService::class)
        ->listActiveBranchMenusForShopByDay($this->branch->id, 4); // dayOfWeek=Thursday

    $row = collect($result->items())->firstWhere('id', $branchMenu->id);

    expect($row)->not->toBeNull()
        ->and($row->matched_start_time)->toBe('07:30:00')
        ->and($row->matched_end_time)->toBe('13:00:00');
});

// ===========================================================================
//  D1–D5 — days_of_week override (shop set riêng ngày, không chỉ giờ)
// ===========================================================================

it('D1: PUT days_of_week stores the override and GET returns effective days + HQ defaults', function () {
    // HQ: all 7 days (127). Shop: Mon–Fri only (bit1..bit5 = 62).
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['days_of_week' => 62])
        ->assertOk();

    $override = BranchScheduleOverride::where('menu_schedule_id', $this->schedule->id)
        ->where('branch_id', $this->branch->id)
        ->first();
    expect($override->days_of_week)->toBe(62);

    $this->actingAs($this->manager)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.0.is_overridden', true)
        ->assertJsonPath('data.0.days_of_week', 62)
        ->assertJsonPath('data.0.days_of_week_labels', ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
        ->assertJsonPath('data.0.hq_defaults.days_of_week', 127);
});

it('D2: PUT days_of_week=null resets to HQ days', function () {
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['days_of_week' => 62])
        ->assertOk();
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['days_of_week' => null])
        ->assertOk();

    $this->actingAs($this->manager)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.0.days_of_week', 127);
});

it('D3: rejects out-of-range bitmask', function () {
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['days_of_week' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('days_of_week');

    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['days_of_week' => 128])
        ->assertUnprocessable();
});

it('D4: getCurrentMenu honours the branch days override', function () {
    // HQ window covers every day; shop narrows to Sunday-only (bit0 = 1).
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['days_of_week' => 1])
        ->assertOk();

    $service = app(MenuService::class);

    // A Monday inside the time window → no menu for THIS branch.
    Carbon::setTestNow('2026-07-20 08:00:00'); // Monday
    expect($service->getCurrentMenu($this->branch->id, $this->orgId))->toBeNull();

    // A Sunday inside the window → resolves.
    Carbon::setTestNow('2026-07-19 08:00:00'); // Sunday
    expect($service->getCurrentMenu($this->branch->id, $this->orgId)?->id)
        ->toBe($this->menu->id);

    Carbon::setTestNow();
});

it('D5: sibling branch keeps HQ days when branch A overrides days', function () {
    [$orgB, $branchB] = [null, null];
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->branch->console_brand_id,
        'is_active' => true,
        // #1091 — same UTC pin as mkOrgAndBranch: this test's sentinels are
        // naive-UTC wall clocks.
        'timezone' => 'UTC',
    ]);
    $menuB = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $branchB->id,
        'status' => 'Active',
        'valid_from' => null,
        'valid_to' => null,
    ]);
    MenuSchedule::factory()->create([
        'menu_id' => $menuB->id,
        'start_time' => '07:00:00',
        'end_time' => '09:00:00',
        'days_of_week' => 127,
        'is_active' => true,
    ]);

    // Branch A narrows to Sunday-only; branch B untouched.
    $this->actingAs($this->manager)
        ->putJson($this->overrideUrl, ['days_of_week' => 1])
        ->assertOk();

    $service = app(MenuService::class);
    Carbon::setTestNow('2026-07-20 08:00:00'); // Monday

    expect($service->getCurrentMenu($this->branch->id, $this->orgId))->toBeNull()
        ->and($service->getCurrentMenu($branchB->id, $this->orgId)?->id)->toBe($menuB->id);

    Carbon::setTestNow();
});
