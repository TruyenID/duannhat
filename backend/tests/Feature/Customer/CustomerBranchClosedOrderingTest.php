<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use App\Services\Shop\BranchOpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * #1167 — a customer must not be able to order take-away from a shop that is
 * currently shut. The shop publishes `branches.weekly_hours`; ordering "for
 * now" outside those hours is refused with 422 BRANCH_CLOSED and the next
 * opening quoted back.
 *
 * Two deliberate carve-outs are pinned here as tests, because both are product
 * decisions rather than oversights:
 *  - a SCHEDULED pickup is still accepted while the shop is shut, as long as
 *    the slot itself is inside opening hours (pre-ordering tomorrow's lunch);
 *  - DINE-IN is never gated — the customer is sitting in the restaurant, and a
 *    party still at the table at closing time must be able to order.
 *
 * Per the business-time contract every assertion freezes the clock, and the
 * verdict is read on the BRANCH's clock (#1091) — covered at ≥3 timezones.
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

    // "Order it now" — the shape customer-web posts from the take-away basket.
    $this->orderNow = function (Branch $branch, array $extra = []) {
        return $this->postJson("/api/v1/customer/branches/{$branch->slug}/orders", [
            'customer_takeaway_phone' => '090-0000-0000',
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
            ...$extra,
        ]);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

// =============================================================================
// The gate — ordering "for now" while shut
// =============================================================================

it('refuses a take-away order placed after closing time', function () use ($weekdayHours) {
    // Mirrors the reported case: hours 06:00–11:00, customer orders at 11:30.
    Carbon::setTestNow('2026-04-27 02:30:00'); // 11:30 JST, Monday
    $branch = ($this->makeBranch)($weekdayHours('06:00', '11:00'));

    ($this->orderNow)($branch)
        ->assertStatus(422)
        ->assertJsonPath('error', 'BRANCH_CLOSED')
        // Quoted back so the client can say "we open again at 06:00" —
        // tomorrow (Tuesday), today's window having already closed.
        ->assertJsonPath('opens_at', fn ($opensAt) => str_contains((string) $opensAt, '2026-04-28T06:00'));
});

it('refuses a take-away order placed before opening time', function () use ($weekdayHours) {
    Carbon::setTestNow('2026-04-26 20:00:00'); // 05:00 JST Monday — an hour early
    $branch = ($this->makeBranch)($weekdayHours('06:00', '11:00'));

    ($this->orderNow)($branch)
        ->assertStatus(422)
        ->assertJsonPath('error', 'BRANCH_CLOSED')
        // Still TODAY's opening, not tomorrow's.
        ->assertJsonPath('opens_at', fn ($opensAt) => str_contains((string) $opensAt, '2026-04-27T06:00'));
});

it('refuses a take-away order on a day marked closed', function () use ($weekdayHours) {
    Carbon::setTestNow('2026-04-26 03:00:00'); // 12:00 JST, Sunday — 定休日
    $branch = ($this->makeBranch)($weekdayHours('06:00', '11:00'));

    ($this->orderNow)($branch)
        ->assertStatus(422)
        ->assertJsonPath('error', 'BRANCH_CLOSED')
        // Skips the closed day entirely → Monday's opening.
        ->assertJsonPath('opens_at', fn ($opensAt) => str_contains((string) $opensAt, '2026-04-27T06:00'));
});

it('accepts a take-away order in the last minute before closing', function () use ($weekdayHours) {
    // The boundary is inclusive on both ends — 11:00 sharp is still open.
    Carbon::setTestNow('2026-04-27 02:00:00'); // 11:00 JST, Monday
    $branch = ($this->makeBranch)($weekdayHours('06:00', '11:00'));

    ($this->orderNow)($branch)->assertCreated();
});

it('accepts an after-midnight order inside an overnight window', function () {
    Carbon::setTestNow('2026-04-27 16:00:00'); // Tuesday 01:00 JST
    $branch = ($this->makeBranch)([
        'mon' => ['open' => '18:00', 'close' => '02:00', 'closed' => false],
        'tue' => ['closed' => true],
    ]);

    // Tuesday is marked closed, but Monday's window runs until 02:00 Tuesday —
    // the shop is physically open and taking orders.
    ($this->orderNow)($branch)->assertCreated();
});

it('leaves shops without published hours completely unaffected', function () {
    Carbon::setTestNow('2026-04-27 18:00:00'); // 03:00 JST
    $branch = ($this->makeBranch)(null);

    // Fail-open by design: most shops never filled weekly_hours in, and
    // refusing every order because a JSON column is empty is the worse bug.
    ($this->orderNow)($branch)->assertCreated();
    ($this->orderNow)(($this->makeBranch)([]))->assertCreated();
});

// =============================================================================
// Carve-outs — pre-order and dine-in
// =============================================================================

it('still accepts a pre-order scheduled inside tomorrow opening hours', function () use ($weekdayHours) {
    Carbon::setTestNow('2026-04-27 14:00:00'); // 23:00 JST Monday — shut
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    // 2026-04-28T03:00:00Z = 12:00 JST Tuesday — inside hours.
    ($this->orderNow)($branch, [
        'pickup_type' => 'scheduled',
        'scheduled_pickup_time' => '2026-04-28T03:00:00Z',
    ])->assertCreated();
});

it('never gates dine-in on opening hours', function () use ($weekdayHours) {
    Carbon::setTestNow('2026-04-27 14:00:00'); // 23:00 JST Monday — an hour past closing
    $branch = ($this->makeBranch)($weekdayHours('09:00', '22:00'));

    $zone = Zone::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $branch->id,
    ]);
    $table = Table::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $branch->id,
        'zone_id' => $zone->id,
        'qr_token' => 'closed-shop-dine-in-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    // The party is sitting in the restaurant; last orders are the staff's call,
    // not the schedule's.
    $this->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertCreated();
});

// =============================================================================
// Business time — one instant, three verdicts
// =============================================================================

it('judges "closed right now" on the branch clock, not the app clock', function () use ($weekdayHours) {
    // 2026-04-27T14:30:00Z = 23:30 Tokyo (SHUT, closed at 22:00)
    //                      = 21:30 Ho Chi Minh (open)
    //                      = 15:30 London (open)
    Carbon::setTestNow('2026-04-27T14:30:00Z');

    $tokyo = ($this->makeBranch)($weekdayHours('09:00', '22:00'), 'Asia/Tokyo');
    $hanoi = ($this->makeBranch)($weekdayHours('09:00', '22:00'), 'Asia/Ho_Chi_Minh');
    $london = ($this->makeBranch)($weekdayHours('09:00', '22:00'), 'Europe/London');

    ($this->orderNow)($tokyo)->assertStatus(422)->assertJsonPath('error', 'BRANCH_CLOSED');
    ($this->orderNow)($hanoi)->assertCreated();
    ($this->orderNow)($london)->assertCreated();
});

// =============================================================================
// Unit — nextOpeningAt
// =============================================================================

it('finds the next opening later the same day', function () use ($weekdayHours) {
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    expect(BranchOpeningHours::nextOpeningAt($branch, CarbonImmutable::parse('2026-04-27 07:00', 'Asia/Tokyo'))?->format('Y-m-d H:i'))
        ->toBe('2026-04-27 09:00');
});

it('skips closed days when looking for the next opening', function () use ($weekdayHours) {
    $branch = ($this->makeBranch)($weekdayHours('09:00', '18:00'));

    // Saturday evening, past closing → Sunday is 定休日 → Monday 09:00.
    expect(BranchOpeningHours::nextOpeningAt($branch, CarbonImmutable::parse('2026-04-25 19:00', 'Asia/Tokyo'))?->format('Y-m-d H:i'))
        ->toBe('2026-04-27 09:00');
});

it('wraps a whole week for a shop open on a single weekday', function () {
    $branch = ($this->makeBranch)(['sat' => ['open' => '10:00', 'close' => '16:00', 'closed' => false]]);

    // Saturday after closing → next Saturday.
    expect(BranchOpeningHours::nextOpeningAt($branch, CarbonImmutable::parse('2026-04-25 17:00', 'Asia/Tokyo'))?->format('Y-m-d H:i'))
        ->toBe('2026-05-02 10:00');
});

it('has no next opening when the branch publishes no hours', function () {
    expect(BranchOpeningHours::nextOpeningAt(($this->makeBranch)(null), CarbonImmutable::parse('2026-04-27 07:00', 'Asia/Tokyo')))
        ->toBeNull();
});
