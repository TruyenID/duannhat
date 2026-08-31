<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuPromotion;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\Till;
use App\Omnify\Enums\MenuStatusEnum;
use App\Services\Pos\TillSessionService;
use App\Services\Product\MenuService;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Business-time contract — issue #1091.
 *
 * TempoFast runs branches in Vietnam (UTC+7) and Japan (UTC+9) off one backend
 * whose `app.timezone` is UTC. Every "what day/time is it for this shop?"
 * decision therefore has to resolve against `branches.timezone`, never against
 * the server clock and never against the viewing user's timezone.
 *
 * Today the codebase uses FOUR different clocks for that one question. This file
 * pins all four so the correct ones cannot regress and the incorrect ones cannot
 * be "fixed" by accident without someone reading #1091 first.
 *
 * Every test freezes the clock. A time-dependent test that reads the real now()
 * is exactly how the Sunday-only and midnight-only failures in 6e124f62 stayed
 * invisible for weeks.
 */
beforeEach(function () {
    $this->org = Organization::query()->first() ?? Organization::factory()->create();
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->org->id]);
});

function branchInZone(object $ctx, string $timezone): Branch
{
    return Branch::factory()->create([
        'console_organization_id' => $ctx->org->id,
        'console_brand_id' => $ctx->brand->console_brand_id,
        'is_active' => true,
        'timezone' => $timezone,
    ]);
}

/**
 * The instant that exposes every UTC-vs-branch-day bug: 2026-07-25 23:59:00 UTC.
 *
 *   UTC                  → Sat 2026-07-25 23:59   (Saturday)
 *   Asia/Tokyo   (+9)    → Sun 2026-07-26 08:59   (Sunday, next day)
 *   Asia/Ho_Chi_Minh (+7)→ Sun 2026-07-26 06:59   (Sunday, next day)
 *
 * Both shops have already opened for business on the 26th while the server still
 * says the 25th, and the ISO weekday differs too (6 vs 7).
 */
function freezeAtCrossDayInstant(): Carbon
{
    $instant = Carbon::parse('2026-07-25 23:59:00', 'UTC');
    Carbon::setTestNow($instant);

    return $instant;
}

afterEach(function () {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// The instant itself — proves the fixture really does straddle a date boundary
// ---------------------------------------------------------------------------

it('uses an instant where the server day and both shop days genuinely disagree', function () {
    freezeAtCrossDayInstant();

    expect(now()->toDateString())->toBe('2026-07-25')
        ->and(now()->setTimezone('Asia/Tokyo')->toDateString())->toBe('2026-07-26')
        ->and(now()->setTimezone('Asia/Ho_Chi_Minh')->toDateString())->toBe('2026-07-26')
        // ISO weekday: server says Saturday(6), both shops say Sunday(7).
        ->and(now()->isoWeekday())->toBe(6)
        ->and(now()->setTimezone('Asia/Tokyo')->isoWeekday())->toBe(7);
});

// ---------------------------------------------------------------------------
// ✅ CORRECT TODAY — MenuPromotion resolves the daily window per branch tz.
//    This is the reference pattern the other modules must adopt. Lock it.
// ---------------------------------------------------------------------------

it('evaluates a promotion daily window in the branch timezone, not the server timezone', function (string $timezone, string $localFrom, string $localTo) {
    freezeAtCrossDayInstant();
    $branch = branchInZone($this, $timezone);

    MenuPromotion::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $branch->id,
        'discount_percent' => 10,
        'applies_to' => 'all_items',
        'is_active' => true,
        // Window that contains the shop's LOCAL morning but not 23:59 UTC.
        'daily_time_from' => $localFrom,
        'daily_time_to' => $localTo,
        'weekdays' => [],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    $resolved = app(MenuPromotionService::class)
        ->resolveActivePromotion($branch->id, (string) Str::uuid(), []);

    expect($resolved)->not->toBeNull();
})->with([
    // 08:59 Tokyo / 06:59 Ho Chi Minh — both inside a local breakfast window,
    // both OUTSIDE the 23:59 the server clock would compare against.
    'Tokyo +9' => ['Asia/Tokyo', '06:00:00', '10:00:00'],
    'Ho Chi Minh +7' => ['Asia/Ho_Chi_Minh', '05:00:00', '08:00:00'],
]);

it('excludes a promotion whose local window has already closed at the shop', function () {
    freezeAtCrossDayInstant();
    $branch = branchInZone($this, 'Asia/Tokyo'); // local 08:59

    MenuPromotion::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $branch->id,
        'discount_percent' => 10,
        'applies_to' => 'all_items',
        'is_active' => true,
        'daily_time_from' => '00:00:00',
        'daily_time_to' => '06:00:00', // closed since 06:00 local
        'weekdays' => [],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    expect(app(MenuPromotionService::class)->resolveActivePromotion($branch->id, (string) Str::uuid(), []))
        ->toBeNull();
});

it('matches ISO weekday 7 for a Sunday at the shop while the server still says Saturday', function () {
    // Regression for 6e124f62: fixtures used weekdays [0..6], which omits Sunday
    // and carries an invalid 0. MenuPromotion.yaml defines ISO 8601 1..7.
    freezeAtCrossDayInstant();
    $branch = branchInZone($this, 'Asia/Tokyo'); // Sunday 08:59 local

    MenuPromotion::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $branch->id,
        'discount_percent' => 10,
        'applies_to' => 'all_items',
        'is_active' => true,
        'daily_time_from' => '00:00:00',
        'daily_time_to' => '23:59:59',
        'weekdays' => [7], // Sunday ONLY, in the shop's frame
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    expect(app(MenuPromotionService::class)->resolveActivePromotion($branch->id, (string) Str::uuid(), []))
        ->not->toBeNull();
});

it('rejects the legacy zero-based weekday encoding', function () {
    // [0..6] must NOT be treated as "every day": 0 is not a valid ISO weekday and
    // 7 (Sunday) is absent. If this ever starts passing, someone has re-introduced
    // a JS-style encoding and every Sunday promotion will silently misfire.
    freezeAtCrossDayInstant();
    $branch = branchInZone($this, 'Asia/Tokyo'); // Sunday local

    MenuPromotion::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $branch->id,
        'discount_percent' => 10,
        'applies_to' => 'all_items',
        'is_active' => true,
        'daily_time_from' => '00:00:00',
        'daily_time_to' => '23:59:59',
        'weekdays' => [0, 1, 2, 3, 4, 5, 6],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    expect(app(MenuPromotionService::class)->resolveActivePromotion($branch->id, (string) Str::uuid(), []))
        ->toBeNull();
});

// ---------------------------------------------------------------------------
// ✅ FIXED (#1091) — business time follows the BRANCH clock via BusinessClock.
// ---------------------------------------------------------------------------

it('#1091: till business_date follows the SHOP day, not the server day', function (string $timezone) {
    freezeAtCrossDayInstant();
    $branch = branchInZone($this, $timezone);
    $till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->org->id,
        'default_currency_code' => 'JPY',
    ]);

    $session = app(TillSessionService::class)->open([
        'branch_id' => $branch->id,
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'till_id' => $till->id,
        'opening_counts' => [],
        'opened_by_id' => (string) Str::uuid(),
    ]);

    // The shop opened on the 26th local — business_date now says the 26th
    // even though the server (UTC) clock still reads the 25th.
    expect($session->business_date->toDateString())->toBe('2026-07-26')
        ->and(now()->toDateString())->toBe('2026-07-25')
        ->and(now()->setTimezone($timezone)->toDateString())->toBe('2026-07-26');
})->with([
    'Tokyo +9' => ['Asia/Tokyo'],
    'Ho Chi Minh +7' => ['Asia/Ho_Chi_Minh'],
]);

it('#1091: getCurrentMenu evaluates the schedule window at the branch clock', function () {
    freezeAtCrossDayInstant();
    $branch = branchInZone($this, 'Asia/Tokyo'); // local Sun 08:59

    $menu = Menu::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $branch->id,
        'status' => MenuStatusEnum::Active->value,
    ]);
    MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        // A breakfast menu in the shop's own frame. It IS open at 08:59 Tokyo.
        'start_time' => '06:00:00',
        'end_time' => '10:00:00',
        'days_of_week' => 127,
        'is_active' => true,
    ]);

    $resolved = app(MenuService::class)->getCurrentMenu($branch->id, (string) $this->org->id);

    // The shop is mid-breakfast (08:59 Tokyo); the server clock reading 23:59
    // UTC no longer hides the window.
    expect($resolved)->not->toBeNull()
        ->and((string) $resolved->id)->toBe((string) $menu->id);
});
