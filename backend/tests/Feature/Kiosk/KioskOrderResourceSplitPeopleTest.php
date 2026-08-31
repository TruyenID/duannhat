<?php

/**
 * Plan-039 follow-up — `KioskOrderResource` exposes `split_people_count`
 * + the DERIVED `amount_per_person` so the kiosk can skip `/split/people`
 * and jump straight to `/split/method` with the per-person figure already
 * on screen.
 *
 * `amount_per_person = round(total_amount / split_people_count, 2)` is a
 * money-rounding path that shipped with ZERO test coverage. These cases
 * pin it: the rounding is exact (incl. non-even division), and every
 * non-`by_people` / missing-count branch yields `null` so the kiosk falls
 * back to its own chooser instead of dividing by null.
 *
 * See `app/Http/Resources/KioskOrderResource.php` L119-L133.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->deviceToken = Str::random(32);
    Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
    ]);
});

/**
 * Attach a freshly-created checkout order to the kiosk table and GET the
 * kiosk order payload. A checkout order returns its STORED total_amount
 * from the pricing service (verified in KioskOrdersTest), so the derived
 * amount_per_person divides that exact frozen total.
 */
function kioskSplitOrder(array $overrides = []): array
{
    $order = CustomerOrder::factory()->checkout()->create(array_merge([
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'paid_amount' => 0,
    ], $overrides));

    test()->table->update(['current_order_id' => $order->id]);

    $data = test()->withHeaders(['Authorization' => 'Bearer '.test()->deviceToken])
        ->getJson('/api/v1/kiosk/orders?table_id='.test()->table->id)
        ->assertOk()
        ->json('data');

    return [$order, $data];
}

it('always exposes split_people_count + amount_per_person keys', function () {
    [, $data] = kioskSplitOrder(['total_amount' => 3000, 'split_mode' => null]);

    expect($data)->toHaveKeys(['split_mode', 'split_mode_locked', 'split_people_count', 'amount_per_person']);
});

it('derives amount_per_person by exact even division for by_people', function () {
    [, $data] = kioskSplitOrder([
        'total_amount' => 3000,
        'split_mode' => 'even',
        'split_people_count' => 4,
    ]);

    expect($data['split_people_count'])->toBe(4);
    expect($data['amount_per_person'])->toBe(750.0); // 3000 / 4
});

it('rounds amount_per_person to 2 decimals on non-even division', function () {
    [, $data] = kioskSplitOrder([
        'total_amount' => 1000,
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    // 1000 / 3 = 333.3333... → round(…, 2) = 333.33. NOT truncated, NOT
    // the full repeating decimal. The 3 * 333.33 = 999.99 residual is the
    // cashier's problem at the counter — the kiosk only displays the hint.
    expect($data['amount_per_person'])->toBe(333.33);
});

it('rounds half-up on the .xx5 boundary', function () {
    // 100.10 / 4 = 25.025 → round(…, 2) = 25.03 (PHP round half-away-from-zero).
    [, $data] = kioskSplitOrder([
        'total_amount' => 100.10,
        'split_mode' => 'even',
        'split_people_count' => 4,
    ]);

    expect($data['amount_per_person'])->toBe(25.03);
});

it('returns null amount_per_person when by_people but no headcount declared', function () {
    [, $data] = kioskSplitOrder([
        'total_amount' => 3000,
        'split_mode' => 'even',
        'split_people_count' => null,
    ]);

    expect($data['split_people_count'])->toBeNull();
    expect($data['amount_per_person'])->toBeNull(); // guard: never divide by null
});

it('returns null amount_per_person for by_items even when a count lingers', function () {
    // split_people_count can persist from an earlier by_people choice; the
    // resource must not compute a per-person amount for a by_items order.
    [, $data] = kioskSplitOrder([
        'total_amount' => 3000,
        'split_mode' => 'by_items',
        'split_people_count' => 3,
    ]);

    expect($data['split_mode'])->toBe('by_items');
    expect($data['split_people_count'])->toBe(3); // raw column still surfaced
    expect($data['amount_per_person'])->toBeNull(); // but no derived split
});

it('returns null amount_per_person when split_mode is unset', function () {
    [, $data] = kioskSplitOrder([
        'total_amount' => 3000,
        'split_mode' => null,
        'split_people_count' => null,
    ]);

    expect($data['split_mode'])->toBeNull();
    expect($data['amount_per_person'])->toBeNull();
});

it('treats a 1-person count as no split (guarded by min:2 upstream) — division still exact', function () {
    // The /split-mode endpoint enforces min:2, but the resource itself has
    // no floor; a count of 1 (e.g. legacy/hand-seeded data) must still not
    // crash and returns the full total as the per-person amount.
    [, $data] = kioskSplitOrder([
        'total_amount' => 3000,
        'split_mode' => 'even',
        'split_people_count' => 1,
    ]);

    expect($data['amount_per_person'])->toBe(3000.0);
});
