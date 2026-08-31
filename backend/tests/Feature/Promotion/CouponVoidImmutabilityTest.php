<?php

/**
 * Plan-019 — BR-COUP07: void-order coupon release + closed-order immutability.
 *
 * Two guarantees, previously untested end-to-end:
 *
 *   1. Voiding an order that still carries a coupon (status NOT closed) must
 *      RELEASE it — redemption `released_at` stamped, coupon `times_used`
 *      decremented, order `discount_amount` cleared — so a scrapped order
 *      never keeps a redemption reserved.
 *
 *   2. A `closed` order is immutable: apply/release are rejected with 409
 *      `order_not_modifiable`, void is rejected with 409, and the coupon
 *      counter + redemption ledger are left exactly as booked (the redemption
 *      is a permanent record of settled revenue).
 *
 * Endpoints exercised:
 *   POST   /api/v1/shops/{shopSlug}/orders/{customerOrder}/apply-coupon
 *   DELETE /api/v1/shops/{shopSlug}/orders/{customerOrder}/coupon
 *   POST   /api/v1/shops/{shopSlug}/orders/{customerOrder}/void
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Role;
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
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'void-coupon-shop',
        'is_active' => true,
    ]);

    $managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'VOID10',
        'discount_type' => 'fixed',
        'discount_value' => 20000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // Create an open order and give it a subtotal the apply path reads.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();
    $this->order = CustomerOrder::first();
    $this->order->update(['subtotal' => 200000, 'total_amount' => 200000]);
});

// ─── 1. void releases the coupon (order not closed) ──────────────────────

it('releases the coupon when a non-closed order is voided', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'VOID10'])
        ->assertOk();

    expect($this->coupon->fresh()->times_used)->toBe(1);
    $redemption = CouponRedemption::where('customer_order_id', $this->order->id)->firstOrFail();
    expect($redemption->released_at)->toBeNull();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/void", [
            'void_reason' => 'customer left',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    // Coupon released: counter restored, ledger row stamped, order cleared.
    expect($this->coupon->fresh()->times_used)->toBe(0);
    expect($redemption->fresh()->released_at)->not->toBeNull();

    $freshOrder = $this->order->fresh();
    expect($freshOrder->coupon_id)->toBeNull();
    expect((float) $freshOrder->discount_amount)->toBe(0.0);
    // Ledger row is preserved (soft release), not deleted — audit trail intact.
    expect(CouponRedemption::where('customer_order_id', $this->order->id)->count())->toBe(1);
});

it('voids cleanly and touches no counter when the order carries no coupon', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/void", [
            'void_reason' => 'no coupon here',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    expect($this->coupon->fresh()->times_used)->toBe(0);
    expect(CouponRedemption::count())->toBe(0);
});

// ─── 2. closed-order immutability (BR-COUP07) ────────────────────────────

it('rejects apply-coupon on a closed order with 409 order_not_modifiable', function () {
    $this->order->update(['status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'VOID10'])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'order_not_modifiable');

    expect($this->coupon->fresh()->times_used)->toBe(0);
    expect(CouponRedemption::count())->toBe(0);
});

it('rejects release-coupon on a closed order with 409 order_not_modifiable', function () {
    // Apply while open, then settle → closed. The redemption is now a permanent
    // record of booked revenue and must not be releasable.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'VOID10'])
        ->assertOk();
    $this->order->update(['status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/coupon")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'order_not_modifiable');

    // Counter + ledger untouched: the settled redemption stays booked.
    expect($this->coupon->fresh()->times_used)->toBe(1);
    $redemption = CouponRedemption::where('customer_order_id', $this->order->id)->firstOrFail();
    expect($redemption->released_at)->toBeNull();
});

it('rejects voiding a closed order and preserves the coupon counter (immutable ledger)', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'VOID10'])
        ->assertOk();
    $this->order->update(['status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/void", [
            'void_reason' => 'too late',
        ])
        ->assertStatus(409);

    // BR-COUP07: a closed order's redemption is immutable — never released.
    expect($this->coupon->fresh()->times_used)->toBe(1);
    $redemption = CouponRedemption::where('customer_order_id', $this->order->id)->firstOrFail();
    expect($redemption->released_at)->toBeNull();
    expect($this->order->fresh()->coupon_id)->toBe($this->coupon->id);
});
