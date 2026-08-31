<?php

/**
 * Plan-019 — feature tests for the addItems auto-apply integration
 * (T2.6) and the resolveActivePromotion edge cases (T2.5).
 *
 * Covers the most subtle scenarios: midnight cross window, multi-
 * promotion match (highest discount_percent wins, Decision B3),
 * snapshot resilience after promotion edit/delete.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Services\Promotion\MenuPromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'autoapply-shop',
        'is_active' => true,
        'timezone' => 'Asia/Ho_Chi_Minh',
    ]);

    $this->service = app(MenuPromotionService::class);
});

it('matches a cross-midnight window during the late-evening half', function () {
    $promotion = makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: '21:00', to: '02:00',
        weekdays: null,
    );

    // 23:30 Vietnam time on a Monday.
    $at = CarbonImmutable::create(2026, 5, 7, 23, 30, 0, 'Asia/Ho_Chi_Minh');
    $resolved = $this->service->resolveActivePromotion(
        $this->shop->id,
        productId: 'whatever',
        categoryIds: [],
        at: $at,
    );

    expect($resolved?->id)->toBe($promotion->id);
});

it('matches a cross-midnight window during the early-morning half', function () {
    $promotion = makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: '21:00', to: '02:00',
        weekdays: null,
    );

    // 01:30 Vietnam time on a Friday (next day after our weekday test fixture).
    $at = CarbonImmutable::create(2026, 5, 8, 1, 30, 0, 'Asia/Ho_Chi_Minh');
    expect($this->service->resolveActivePromotion(
        $this->shop->id,
        productId: 'whatever',
        categoryIds: [],
        at: $at,
    )?->id)->toBe($promotion->id);
});

it('does NOT match a cross-midnight window outside both halves', function () {
    makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: '21:00', to: '02:00',
        weekdays: null,
    );

    // 12:00 noon — fully outside.
    $at = CarbonImmutable::create(2026, 5, 7, 12, 0, 0, 'Asia/Ho_Chi_Minh');
    expect($this->service->resolveActivePromotion(
        $this->shop->id,
        productId: 'whatever',
        categoryIds: [],
        at: $at,
    ))->toBeNull();
});

it('respects branch timezone (not UTC) for window evaluation', function () {
    makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: '21:00', to: '23:00',
        weekdays: null,
    );

    // 14:30 UTC = 21:30 Vietnam — in window.
    $atUtc = CarbonImmutable::create(2026, 5, 7, 14, 30, 0, 'UTC');
    expect($this->service->resolveActivePromotion(
        $this->shop->id,
        productId: 'whatever',
        categoryIds: [],
        at: $atUtc,
    ))->not->toBeNull();

    // 06:30 UTC = 13:30 Vietnam — out of window.
    $atUtcDay = CarbonImmutable::create(2026, 5, 7, 6, 30, 0, 'UTC');
    expect($this->service->resolveActivePromotion(
        $this->shop->id,
        productId: 'whatever',
        categoryIds: [],
        at: $atUtcDay,
    ))->toBeNull();
});

it('picks the higher discount_percent when multiple promotions match (B3)', function () {
    $low = makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: null, to: null, weekdays: null,
        discountPct: 10,
        name: 'low-pct-10',
    );
    $high = makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: null, to: null, weekdays: null,
        discountPct: 30,
        name: 'high-pct-30',
    );

    $resolved = $this->service->resolveActivePromotion(
        $this->shop->id,
        productId: 'any',
        categoryIds: [],
    );

    expect($resolved?->id)->toBe($high->id);
    expect((float) $resolved->discount_percent)->toBe(30.00);
});

it('skips inactive promotions even if in window', function () {
    $promotion = makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: null, to: null, weekdays: null,
    );
    $promotion->update(['is_active' => false]);

    expect($this->service->resolveActivePromotion(
        $this->shop->id,
        productId: 'any',
        categoryIds: [],
    ))->toBeNull();
});

it('respects weekdays filter (1=Mon..7=Sun ISO)', function () {
    makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: null, to: null,
        weekdays: [1, 2, 3, 4, 5], // weekdays only
    );

    // Monday 12:00 — match.
    $monday = CarbonImmutable::create(2026, 5, 7, 12, 0, 0, 'Asia/Ho_Chi_Minh');
    expect($this->service->resolveActivePromotion(
        $this->shop->id, productId: 'p', categoryIds: [], at: $monday,
    ))->not->toBeNull();

    // Saturday 12:00 — no match.
    $saturday = CarbonImmutable::create(2026, 5, 9, 12, 0, 0, 'Asia/Ho_Chi_Minh');
    expect($this->service->resolveActivePromotion(
        $this->shop->id, productId: 'p', categoryIds: [], at: $saturday,
    ))->toBeNull();
});

it('does not match promotions outside valid_from..valid_until', function () {
    $promotion = makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: null, to: null, weekdays: null,
    );
    // Move the window into the past.
    $promotion->update([
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->subDays(2),
    ]);

    expect($this->service->resolveActivePromotion(
        $this->shop->id, productId: 'p', categoryIds: [],
    ))->toBeNull();
});

// ─── helper ─────────────────────────────────────────────────────────────

function makeAutoApplyPromotion(
    Branch $branch,
    Brand $brand,
    string $orgId,
    ?string $from,
    ?string $to,
    ?array $weekdays,
    float $discountPct = 20,
    string $name = 'auto-apply',
): MenuPromotion {
    return MenuPromotion::factory()->create([
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
        'name' => $name,
        'discount_percent' => $discountPct,
        'applies_to' => 'all_items',
        'daily_time_from' => $from,
        'daily_time_to' => $to,
        'weekdays' => $weekdays,
        // Plan-019 — these tests pass `$at` with hard-coded dates (e.g.
        // CarbonImmutable::create(2026, 5, 7)) to verify weekday +
        // cross-midnight window logic. The validity range must straddle
        // any plausible `$at` so the (valid_from <= at AND valid_until
        // >= at) gate doesn't accidentally exclude the candidate when
        // the suite runs more than a few days after the fixture date.
        // Year-wide range is the simplest fix and matches how real
        // promotions are usually set up anyway.
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addYear(),
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
    ]);
}

// =========================================================================
//  #1091 §4.3 — weekdays are ISO 1..7 (Mon..Sun), evaluated at the branch
// =========================================================================

it('matches its weekday and only its weekday, for every ISO day 1..7', function (int $isoDay) {
    makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: null, to: null,
        weekdays: [$isoDay],
    );

    // 2026-07-20 is a Monday, so +($isoDay-1) days lands exactly on ISO
    // weekday $isoDay — including 2026-07-26 = Sunday = ISO 7 (the classic
    // trap: Carbon dayOfWeek says Sunday=0, isoWeekday says 7).
    $matchDay = CarbonImmutable::create(2026, 7, 20, 12, 0, 0, 'Asia/Ho_Chi_Minh')->addDays($isoDay - 1);

    expect($this->service->resolveActivePromotion(
        $this->shop->id, productId: 'whatever', categoryIds: [], at: $matchDay,
    ))->not->toBeNull("ISO weekday {$isoDay} must match on its own day");

    expect($this->service->resolveActivePromotion(
        $this->shop->id, productId: 'whatever', categoryIds: [], at: $matchDay->addDay(),
    ))->toBeNull("ISO weekday {$isoDay} must NOT match on the following day");
})->with([1, 2, 3, 4, 5, 6, 7]);

it('a legacy weekdays value of 0 matches NOTHING — never silently Sunday', function () {
    // The API layer rejects 0 (weekdays.* between:1,7); a pre-validation DB
    // row carrying 0 must fail closed rather than being reinterpreted as
    // Sunday behind the operator's back.
    makeAutoApplyPromotion(
        $this->shop, $this->brand, $this->orgId,
        from: null, to: null,
        weekdays: [0],
    );

    foreach (range(0, 6) as $offset) {
        $at = CarbonImmutable::create(2026, 7, 20, 12, 0, 0, 'Asia/Ho_Chi_Minh')->addDays($offset);
        expect($this->service->resolveActivePromotion(
            $this->shop->id, productId: 'whatever', categoryIds: [], at: $at,
        ))->toBeNull('weekday 0 must not match '.$at->englishDayOfWeek);
    }
});
