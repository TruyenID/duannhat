<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ProductSku;
use App\Services\Shop\BranchOpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * #1160 R4 — a scheduled takeaway pickup must land inside the shop's opening
 * hours (`branches.weekly_hours`), judged on the BRANCH's clock (#1091).
 *
 * Per the business-time contract every assertion here freezes the clock and
 * the branch-timezone cases cover ≥3 zones, because the whole point is that
 * "22:00" means the shop's 22:00 — not the app's, the DB's, or the customer's.
 */
$weekdayHours = fn (string $open, string $close) => [
    'mon' => ['open' => $open, 'close' => $close, 'closed' => false],
    'tue' => ['open' => $open, 'close' => $close, 'closed' => false],
    'wed' => ['open' => $open, 'close' => $close, 'closed' => false],
    'thu' => ['open' => $open, 'close' => $close, 'closed' => false],
    'fri' => ['open' => $open, 'close' => $close, 'closed' => false],
    'sat' => ['open' => $open, 'close' => $close, 'closed' => false],
    'sun' => ['closed' => true],
];

beforeEach(function () {
    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->makeBranch = function (?array $weeklyHours, string $timezone = 'Asia/Tokyo') {
        return Branch::factory()->create([
            'console_organization_id' => '00000000-0000-0000-0000-000000000001',
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
            'timezone' => $timezone,
            'weekly_hours' => $weeklyHours,
        ]);
    };

    $this->sku = ProductSku::factory()->create();

    $this->orderAt = function (Branch $branch, string $pickupUtc) {
        return $this->postJson("/api/v1/customer/branches/{$branch->slug}/orders", [
            'customer_takeaway_phone' => '090-0000-0000',
            'pickup_type' => 'scheduled',
            'scheduled_pickup_time' => $pickupUtc,
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ]);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

// =============================================================================
// Unit — window semantics
// =============================================================================

it('accepts an instant inside the window and rejects one past closing', function () use ($weekdayHours) {
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    // 2026-04-27 is a Monday. 17:59 JST is in; 18:01 JST is out.
    expect(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-27 17:59', 'Asia/Tokyo')))->toBeTrue()
        ->and(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-27 18:01', 'Asia/Tokyo')))->toBeFalse()
        ->and(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-27 08:59', 'Asia/Tokyo')))->toBeFalse();
});

it('rejects a day marked closed', function () use ($weekdayHours) {
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    // 2026-04-26 is a Sunday — closed all day.
    expect(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-26 12:00', 'Asia/Tokyo')))->toBeFalse();
});

it('treats close <= open as an overnight window', function () {
    $branch = ($this->makeBranch)([
        'mon' => ['open' => '18:00', 'close' => '02:00', 'closed' => false],
        'tue' => ['closed' => true],
    ]);

    // Monday 23:00 and the Tuesday 01:00 tail both belong to Monday's window;
    // Tuesday 03:00 does not.
    expect(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-27 23:00', 'Asia/Tokyo')))->toBeTrue()
        ->and(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-28 01:00', 'Asia/Tokyo')))->toBeTrue()
        ->and(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-28 03:00', 'Asia/Tokyo')))->toBeFalse();
});

it('imposes no constraint when weekly_hours is unset or empty', function () {
    // Fail-open by design: most shops never filled this in, and refusing every
    // scheduled pickup because a JSON column is empty is the worse bug.
    expect(BranchOpeningHours::isOpenAt(($this->makeBranch)(null), CarbonImmutable::parse('2026-04-27 03:00', 'Asia/Tokyo')))->toBeTrue()
        ->and(BranchOpeningHours::isOpenAt(($this->makeBranch)([]), CarbonImmutable::parse('2026-04-27 03:00', 'Asia/Tokyo')))->toBeTrue();
});

it('ignores a day whose open/close pair is unusable', function () {
    $branch = ($this->makeBranch)([
        'mon' => ['open' => 'noon', 'close' => '18:00', 'closed' => false],
    ]);

    // Garbage in one field must not silently narrow the window to nothing for
    // a shop that thinks it published hours — the day simply isn't enforced.
    expect(BranchOpeningHours::isOpenAt($branch, CarbonImmutable::parse('2026-04-27 12:00', 'Asia/Tokyo')))->toBeTrue();
});

it('quotes the closing time of the targeted day', function () use ($weekdayHours) {
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    expect(BranchOpeningHours::closingAt($branch, CarbonImmutable::parse('2026-04-27 20:00', 'Asia/Tokyo'))?->format('H:i'))
        ->toBe('18:00');
});

// =============================================================================
// Business time — the SAME instant is open at one branch, shut at another
// =============================================================================

it('judges the instant on the branch clock, not the app clock', function () use ($weekdayHours) {
    // 2026-04-27T12:30:00Z = 21:30 Tokyo (open, closes 22:00)
    //                      = 19:30 Ho Chi Minh (open, closes 22:00)
    //                      = 12:30 London (open, closes 22:00)
    // Now push it two hours later: 23:30 Tokyo is SHUT while 21:30 Ho Chi Minh
    // and 14:30 London are still open — one instant, three verdicts.
    $tokyo = ($this->makeBranch)($weekdayHours('09:00', '22:00'), 'Asia/Tokyo');
    $hanoi = ($this->makeBranch)($weekdayHours('09:00', '22:00'), 'Asia/Ho_Chi_Minh');
    $london = ($this->makeBranch)($weekdayHours('09:00', '22:00'), 'Europe/London');

    $instant = CarbonImmutable::parse('2026-04-27T14:30:00Z');

    expect(BranchOpeningHours::isOpenAt($tokyo, $instant))->toBeFalse()
        ->and(BranchOpeningHours::isOpenAt($hanoi, $instant))->toBeTrue()
        ->and(BranchOpeningHours::isOpenAt($london, $instant))->toBeTrue();
});

// =============================================================================
// API — the endpoint is the real gate, not just the picker
// =============================================================================

it('rejects a scheduled pickup after closing with PICKUP_OUTSIDE_OPENING_HOURS', function () use ($weekdayHours) {
    Carbon::setTestNow('2026-04-27 01:00:00'); // 10:00 JST, Monday
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    // 2026-04-27T13:00:00Z = 22:00 JST — four hours past closing.
    ($this->orderAt)($branch, '2026-04-27T13:00:00Z')
        ->assertStatus(422)
        ->assertJsonPath('error', 'PICKUP_OUTSIDE_OPENING_HOURS')
        // The client quotes this back to the customer ("we close at 18:00").
        ->assertJsonPath('closes_at', fn ($closesAt) => str_contains((string) $closesAt, '18:00'));
});

it('accepts a scheduled pickup inside opening hours', function () use ($weekdayHours) {
    Carbon::setTestNow('2026-04-27 01:00:00'); // 10:00 JST, Monday
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    // 2026-04-27T08:00:00Z = 17:00 JST — an hour before closing.
    ($this->orderAt)($branch, '2026-04-27T08:00:00Z')->assertCreated();
});

it('gates immediate pickups on opening hours too', function () use ($weekdayHours) {
    // REVERSES the #1160 carve-out. That one read an "immediate" order as a
    // customer standing at the counter, but this endpoint is the remote
    // customer-web basket: at 22:00 the order lands in an empty kitchen. See
    // CustomerBranchClosedOrderingTest for the full #1167 suite.
    Carbon::setTestNow('2026-04-27 13:00:00'); // 22:00 JST — past closing
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    $this->postJson("/api/v1/customer/branches/{$branch->slug}/orders", [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'immediate',
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonPath('error', 'BRANCH_CLOSED');
});

it('leaves shops without published hours unaffected', function () {
    Carbon::setTestNow('2026-04-27 01:00:00');
    $branch = ($this->makeBranch)(null);

    // 03:00 JST at a shop with no schedule — allowed, same as before #1160.
    ($this->orderAt)($branch, '2026-04-27T18:00:00Z')->assertCreated();
});
