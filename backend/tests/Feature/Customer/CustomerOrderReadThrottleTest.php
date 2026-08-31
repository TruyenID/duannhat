<?php

/**
 * GET /api/v1/customer/orders/{id} used to carry no throttle at all: the route
 * had no ->middleware(), the enclosing group adds none, and `throttleApi()` is
 * never called anywhere — so the `api` group resolves to SubstituteBindings
 * only. An anonymous, unbounded public read.
 *
 * It now uses the named `customer-order-read` limiter (120/min, keyed by ORDER
 * ID). customer-web polls this endpoint while a guest waits on the payment
 * screen, so the ceiling needs to be deliberate.
 *
 * The keying is the part worth locking down. Every phone on a shop's wifi
 * shares one NAT egress IP and customer-web runs with auth off, so an IP key
 * would drop all 40 tables into one bucket — the same failure already fixed on
 * `kiosk-audit` and `workstation`. These tests pin both halves: the ceiling
 * exists, AND two orders read from one IP never share a bucket.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    RateLimiter::clear('customer-order-read');

    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
});

/**
 * Two distinct orders so the per-order keying can actually be observed.
 *
 * Name is prefixed because Pest shares one global function namespace across
 * every test file — a plain `makeOrder()` collides with SetSplitModeTest.
 */
function makeThrottleTestOrder($branch): CustomerOrder
{
    return CustomerOrder::factory()->create(['branch_id' => $branch->id]);
}

it('lets a guest poll the payment screen well inside the ceiling', function () {
    // customer-web's fastest cadence is 5s → 12 req/min per device. A split
    // bill with a few phones on one order stays far below 120; none of it may
    // 429, or the screen the whole polling design exists to fix goes stale.
    $order = makeThrottleTestOrder($this->branch);

    for ($i = 0; $i < 60; $i++) {
        expect($this->getJson("/api/v1/customer/orders/{$order->id}")->status())
            ->not->toBe(429);
    }
});

it('429s past 120 reads on one order', function () {
    $order = makeThrottleTestOrder($this->branch);

    for ($i = 0; $i < 120; $i++) {
        expect($this->getJson("/api/v1/customer/orders/{$order->id}")->status())
            ->not->toBe(429);
    }

    $this->getJson("/api/v1/customer/orders/{$order->id}")->assertStatus(429);
});

it('gives each order its own bucket, so one shop NAT cannot starve itself', function () {
    // THE regression that matters. With an IP key, exhausting order A would
    // 429 order B from the same client — i.e. one table's phone spinning
    // would lock every other table in the restaurant out of its bill.
    $noisy = makeThrottleTestOrder($this->branch);
    $quiet = makeThrottleTestOrder($this->branch);

    for ($i = 0; $i < 121; $i++) {
        $this->getJson("/api/v1/customer/orders/{$noisy->id}");
    }
    $this->getJson("/api/v1/customer/orders/{$noisy->id}")->assertStatus(429);

    // Same test client, same IP, untouched order → must be unaffected.
    expect($this->getJson("/api/v1/customer/orders/{$quiet->id}")->status())
        ->not->toBe(429);
});

it('keeps the sibling split-status read on its own budget', function () {
    // Only `orders/{id}` was throttled. Its neighbours under the same prefix
    // must not have been swept into the same bucket by a stray group-level
    // middleware — customer-web calls split-status on mount while polling.
    $order = makeThrottleTestOrder($this->branch);

    for ($i = 0; $i < 121; $i++) {
        $this->getJson("/api/v1/customer/orders/{$order->id}");
    }
    $this->getJson("/api/v1/customer/orders/{$order->id}")->assertStatus(429);

    expect($this->getJson("/api/v1/customer/orders/{$order->id}/split-status")->status())
        ->not->toBe(429);
});
