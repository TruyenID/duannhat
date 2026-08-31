<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Plan 007 — POS reminds staff of a customer's unpaid-from-previous-visit
 * debt when they return for a new order. The wire contract is:
 *
 *   GET /api/v1/shops/{shopSlug}/customers/{customer}/outstanding
 *   → { data: CustomerOrder[], total_owed: string }
 *
 * An "outstanding" order is one in the `paying` lifecycle with paid_amount
 * strictly less than total_amount. `checkout` (no payment yet) is NOT a
 * debt; `closed` and `voided` are settled/cancelled and also excluded.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'outstanding-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Khách',
        'phone' => '0912345678',
    ]);
});

/** Seed helper: order with given status, totals, and payment coverage. */
function seedOutstandingOrder(
    string $status,
    int $total,
    int $paid,
    string $code,
): CustomerOrder {
    return CustomerOrder::create([
        'order_code' => $code,
        'order_type' => 'takeaway',
        'status' => $status,
        'subtotal' => $total,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => $paid,
        'total_tip' => 0,
        'opened_at' => now()->subHours(2),
        'checkout_at' => now()->subHour(),
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

it('returns empty data and total_owed="0.00" when the customer has no unpaid orders', function () {
    seedOutstandingOrder('closed', 1000, 1000, 'ORD-CLSD-01');

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$this->customer->id}/outstanding")
        ->assertOk();

    expect($response->json('data'))->toBe([]);
    expect($response->json('total_owed'))->toBe('0.00');
});

it('returns orders in status=paying with paid < total, sums total_owed correctly', function () {
    seedOutstandingOrder('paying', 4450, 3000, 'ORD-DEBT-01'); // owe 1450
    seedOutstandingOrder('paying', 2000, 500, 'ORD-DEBT-02');  // owe 1500

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$this->customer->id}/outstanding")
        ->assertOk();

    $codes = collect($response->json('data'))->pluck('order_code')->sort()->values()->all();
    expect($codes)->toBe(['ORD-DEBT-01', 'ORD-DEBT-02']);
    expect($response->json('total_owed'))->toBe('2950.00');
});

it('excludes closed / voided / checkout / open / dining orders', function () {
    seedOutstandingOrder('paying', 4000, 1000, 'ORD-KEEP-PAY'); // KEEP
    seedOutstandingOrder('checkout', 3000, 0, 'ORD-SKIP-CHKO'); // SKIP — not yet paid anything
    seedOutstandingOrder('closed', 2000, 2000, 'ORD-SKIP-CLSD');
    seedOutstandingOrder('voided', 1500, 0, 'ORD-SKIP-VOID');
    seedOutstandingOrder('dining', 1000, 0, 'ORD-SKIP-DINE');
    seedOutstandingOrder('open', 1000, 0, 'ORD-SKIP-OPEN');

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$this->customer->id}/outstanding")
        ->assertOk();

    $codes = collect($response->json('data'))->pluck('order_code')->all();
    expect($codes)->toBe(['ORD-KEEP-PAY']);
    expect($response->json('total_owed'))->toBe('3000.00');
});

it('excludes paying orders that actually equal their total (edge: paid == total)', function () {
    // Nominally status=paying but paid_amount == total_amount — boundary case,
    // not a debt. whereColumn paid_amount < total_amount must exclude it.
    seedOutstandingOrder('paying', 2000, 2000, 'ORD-EQUAL-01');

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$this->customer->id}/outstanding")
        ->assertOk();

    expect($response->json('data'))->toBe([]);
    expect($response->json('total_owed'))->toBe('0.00');
});

it('scopes outstanding to the shop branch — orders from a sister branch do not leak', function () {
    $sisterShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'sister-shop',
        'is_active' => true,
    ]);

    // Debt at SISTER shop — must NOT show up when querying THIS shop.
    CustomerOrder::create([
        'order_code' => 'ORD-SISTER-DEBT',
        'order_type' => 'takeaway',
        'status' => 'paying',
        'subtotal' => 5000, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 5000,
        'paid_amount' => 1000, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $this->customer->id,
        'branch_id' => $sisterShop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // Debt at THIS shop — must show up.
    seedOutstandingOrder('paying', 2000, 500, 'ORD-THIS-DEBT'); // owe 1500

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$this->customer->id}/outstanding")
        ->assertOk();

    $codes = collect($response->json('data'))->pluck('order_code')->all();
    expect($codes)->toBe(['ORD-THIS-DEBT']);
    expect($response->json('total_owed'))->toBe('1500.00');
});

it('returns 403 for a customer from a different organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $foreignCustomer = Customer::factory()->create([
        'organization_id' => $otherOrgId,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$foreignCustomer->id}/outstanding")
        ->assertForbidden();
});
