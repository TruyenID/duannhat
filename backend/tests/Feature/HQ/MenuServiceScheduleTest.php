<?php

/**
 * Plan 014 — MenuServiceScheduleTest
 *
 * Tests for MenuService::getCurrentMenu() schedule-aware behaviour.
 *
 * Key resolution rules under test:
 *   S1 — Menu with ZERO schedule rows → always-on (backward compat).
 *   S2 — Menu whose only schedule rows are soft-deleted → always-on fallback.
 *   S3 — Menu with only is_active=false schedule row → NOT returned.
 *   S4 — Menu with is_active=true, days_of_week=127 (all days), full-day
 *         window → IS returned.
 *   S5 — Menu with is_active=true but days_of_week excludes today → NOT returned.
 *   S6 — Two menus, same schedule window — higher priority number wins
 *         (getCurrentMenu orders by DESC priority).
 *   S7 — Menu with both a soft-deleted AND a live active schedule → IS
 *         returned (the live row satisfies orWhereHas).
 *
 * NOTE on day-bit computation:
 *   Carbon::dayOfWeek → 0=Sun … 6=Sat.
 *   $dayBit = 1 << now()->dayOfWeek.
 *   To build a bitmask that EXCLUDES today: 127 & ~$dayBit.
 *   This is done at test run time so the suite is day-of-week agnostic.
 *
 * NOTE on time window:
 *   start_time='00:00:00', end_time='23:59:59' covers any wall-clock time,
 *   so MySQL CURRENT_TIME always falls within the window.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Services\Product\MenuService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

// ---------------------------------------------------------------------------
// Shared helpers

/**
 * Build a bitmask for all 7 days EXCEPT the current day.
 * Carbon dayOfWeek: 0=Sun … 6=Sat → bit position = dayOfWeek value.
 */
function daysExcludingToday(): int
{
    $todayBit = 1 << now()->dayOfWeek;

    return 127 & (~$todayBit);
}

// ---------------------------------------------------------------------------
// beforeEach — minimal org + brand + branch_id shared across all cases

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'svc-sched-'.Str::random(4),
        'is_active' => true,
    ]);

    // Create a real branch so menus can reference it via FK.
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        // #1091 — getCurrentMenu now evaluates the schedule window at the
        // BRANCH timezone. These tests freeze UTC instants, so the branch is
        // pinned to UTC to keep every boundary assertion exact.
        'timezone' => 'UTC',
    ]);
    $this->branchId = $branch->id;

    $this->service = app(MenuService::class);
});

// ---------------------------------------------------------------------------
// Factory helper that creates an Active menu with null valid_from / valid_to
// so the date-range guards never filter it out.

function activeMenu(array $overrides = []): Menu
{
    /** @var TestCase $test */
    $test = test();

    return Menu::factory()->create(array_merge([
        'organization_id' => $test->orgId,
        'brand_id' => $test->brand->id,
        'branch_id' => $test->branchId,
        'status' => 'Active',
        'valid_from' => null,
        'valid_to' => null,
        'priority' => 10,
        'is_master' => false,
    ], $overrides));
}

// ---------------------------------------------------------------------------

describe('getCurrentMenu schedule resolution', function () {
    // S1 ─────────────────────────────────────────────────────────────────────
    it('returns a menu that has NO schedule rows (always-on backward compat)', function () {
        $menu = activeMenu();
        // No MenuSchedule rows created at all.

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });

    // S2 ─────────────────────────────────────────────────────────────────────
    it('returns a menu whose only schedule rows are soft-deleted (always-on fallback)', function () {
        $menu = activeMenu();

        $schedule = MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        // Soft-delete the only schedule — the menu now has zero non-deleted rows.
        $schedule->delete();

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });

    // S3 ─────────────────────────────────────────────────────────────────────
    it('does NOT return a menu that has a non-deleted but is_active=false schedule', function () {
        $menu = activeMenu();

        // A non-deleted schedule exists but is disabled — the menu is NOT always-on
        // (whereDoesntHave fails because a non-deleted row exists) and the
        // orWhereHas also fails because is_active=false excludes it from activeSchedules.
        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => false,   // ← disabled
        ]);

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->toBeNull();
    });

    // S4 ─────────────────────────────────────────────────────────────────────
    it('returns a menu with is_active=true, days_of_week=127, full-day window', function () {
        $menu = activeMenu();

        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,    // all days
            'is_active' => true,
        ]);

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });

    // S5 ─────────────────────────────────────────────────────────────────────
    it('does NOT return a menu whose active schedule excludes today\'s day bit', function () {
        $excludeToday = daysExcludingToday();

        // Skip the test if today already covers all days (shouldn't happen, but
        // guards against the edge case where daysExcludingToday() == 0).
        if ($excludeToday === 0) {
            $this->markTestSkipped('All day bits are set; cannot exclude today.');
        }

        $menu = activeMenu();

        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => $excludeToday,  // today's bit NOT included
            'is_active' => true,
        ]);

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->toBeNull();
    });

    // S6 ─────────────────────────────────────────────────────────────────────
    it('returns the menu with the LOWER priority number when two menus share the same window', function () {
        // getCurrentMenu uses ->orderBy('priority') — lower number = higher
        // priority (schema contract + menu reorder assigns 1-based top-down).
        $menuHigh = activeMenu(['priority' => 1]); // lower number → wins
        $menuLow = activeMenu(['priority' => 2]);

        foreach ([$menuHigh, $menuLow] as $m) {
            MenuSchedule::factory()->create([
                'menu_id' => $m->id,
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
                'days_of_week' => 127,
                'is_active' => true,
            ]);
        }

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menuHigh->id);
    });

    // S7 ─────────────────────────────────────────────────────────────────────
    it('returns a menu that has a soft-deleted schedule AND a live active schedule', function () {
        $menu = activeMenu();

        // Soft-deleted schedule — does NOT satisfy always-on (non-deleted rows still exist)
        // but also doesn't prevent the orWhereHas from matching the live one.
        $old = MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'days_of_week' => 127,
            'is_active' => true,
        ]);
        $old->delete();

        // Live active schedule that covers the full day.
        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => true,
        ]);

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });
});

// ---------------------------------------------------------------------------
// TC-MSCH-103 — optional calendar-date bounds on a schedule window
// ---------------------------------------------------------------------------

describe('getCurrentMenu date-range bounds (TC-MSCH-103)', function () {
    it('returns a menu whose window date range covers today', function () {
        $menu = activeMenu();
        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => true,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });

    it('does NOT return a menu whose window starts in the future', function () {
        $menu = activeMenu();
        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => true,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => null,
        ]);

        expect($this->service->getCurrentMenu($this->branchId, $this->orgId))->toBeNull();
    });

    it('does NOT return a menu whose window already ended', function () {
        $menu = activeMenu();
        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => true,
            'start_date' => null,
            'end_date' => now()->subDays(1)->toDateString(),
        ]);

        expect($this->service->getCurrentMenu($this->branchId, $this->orgId))->toBeNull();
    });

    it('treats null date bounds as unbounded (backward compat)', function () {
        $menu = activeMenu();
        MenuSchedule::factory()->create([
            'menu_id' => $menu->id,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => 127,
            'is_active' => true,
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });
});

// ---------------------------------------------------------------------------
// Inclusive time-boundary — getCurrentMenu compares
//   start_time <= now  AND  end_time >= now   (both inclusive).
// The window is the closed interval [start_time, end_time]. These cases freeze
// the wall clock EXACTLY on each edge and one second outside it to pin the
// boundary semantics. A fixed Thursday (Carbon dayOfWeek=4, bit 4 set in 127)
// keeps days_of_week matching regardless of when the suite runs.
// getCurrentMenu binds PHP now(), so Carbon::setTestNow() is honoured (see O16
// in BranchMenuScheduleTest).
// ---------------------------------------------------------------------------

/**
 * Active menu with a single 08:00–12:00, all-days, active schedule window —
 * the fixture the inclusive-boundary cases freeze the clock against.
 */
function boundaryMenu(): Menu
{
    $menu = activeMenu();
    MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
        'days_of_week' => 127,
        'is_active' => true,
    ]);

    return $menu;
}

describe('getCurrentMenu inclusive time boundary', function () {
    afterEach(fn () => Carbon::setTestNow());

    it('IS returned when current time is EXACTLY start_time (inclusive lower bound)', function () {
        $menu = boundaryMenu();

        Carbon::setTestNow(Carbon::create(2026, 1, 8, 8, 0, 0)); // Thursday 08:00:00 sharp

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });

    it('IS returned when current time is EXACTLY end_time (inclusive upper bound)', function () {
        $menu = boundaryMenu();

        Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0)); // Thursday 12:00:00 sharp

        $result = $this->service->getCurrentMenu($this->branchId, $this->orgId);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($menu->id);
    });

    it('is NOT returned one second before start_time (window not yet open)', function () {
        boundaryMenu();

        Carbon::setTestNow(Carbon::create(2026, 1, 8, 7, 59, 59));

        expect($this->service->getCurrentMenu($this->branchId, $this->orgId))->toBeNull();
    });

    it('is NOT returned one second after end_time (window already closed)', function () {
        boundaryMenu();

        Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 1));

        expect($this->service->getCurrentMenu($this->branchId, $this->orgId))->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Cross-tenant isolation (security #848) — getCurrentMenu must be scoped to the
// caller's organization; a foreign org_id must never see another tenant's menu.

describe('getCurrentMenu tenant isolation', function () {
    it('does not return a branch menu when queried with a foreign organization id', function () {
        activeMenu();

        $foreignOrgId = (string) Str::uuid();

        expect($this->service->getCurrentMenu($this->branchId, $foreignOrgId))->toBeNull();
    });

    it('only resolves a menu under its owning organization', function () {
        $menu = activeMenu();

        // Correct org sees it; the branch of another tenant does not leak it.
        expect($this->service->getCurrentMenu($this->branchId, $this->orgId)?->id)->toBe($menu->id)
            ->and($this->service->getCurrentMenu($this->branchId, (string) Str::uuid()))->toBeNull();
    });
});
