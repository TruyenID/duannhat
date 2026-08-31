<?php

/*
 * Plan-004 logic-risk audit — two money-path regressions on the order-payment
 * flow:
 *
 *   1. A stock shortage at close() must NEVER roll back the payment the
 *      customer already handed over. close() runs inside the same transaction
 *      that confirms the final cash payment; before the fix an
 *      InsufficientStockException from the stock-out submit rolled the whole
 *      transaction back, losing the collected payment AND leaving the order
 *      un-closed. The stock deduction is now ring-fenced so money survives.
 *
 *   2. `payment_method_id` must be tenant-scoped. A bare
 *      `exists:payment_methods,id` accepted ANY method row, so a caller on
 *      shop A could stamp a payment with another branch's / organisation's
 *      private method. It is now scoped to the order's org + (org-wide OR own
 *      branch).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

function seedOrgWithShop(string $slug): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => $slug,
        'is_active' => true,
    ]);

    return ['orgId' => $orgId, 'brand' => $brand, 'shop' => $shop];
}

function seedManagerFor(string $orgId): User
{
    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $manager = User::factory()->create(['console_organization_id' => $orgId]);
    $manager->assignRole($role, $orgId);

    return $manager;
}

function seedTrackStockSku(array $ctx, float $price): ProductSku
{
    $pt = ProductType::factory()->create([
        'organization_id' => $ctx['orgId'],
        'brand_id' => $ctx['brand']->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $ctx['orgId'],
        'brand_id' => $ctx['brand']->id,
        'product_type_id' => $pt->id,
    ]);

    return ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => $price,
        'is_active' => true,
        // track_stock → Phase 1 SKU stock-out fires at close (made_to_order is skipped).
        'inventory_mode' => 'track_stock',
    ]);
}

function seedCheckoutOrderFor(array $ctx, ProductSku $sku, int $total): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-MS-'.Str::random(5),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => $total,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'branch_id' => $ctx['shop']->id,
        'brand_id' => $ctx['brand']->id,
        'organization_id' => $ctx['orgId'],
    ]);

    $order->items()->create([
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'unit_price' => $total,
        'original_unit_price' => $total,
        'subtotal' => $total,
        'status' => 'served',
        'served_at' => now(),
        'tax_rate' => 0,
    ]);

    return $order;
}

// =============================================================================
//  Finding 1 — stock shortage must not roll back a collected payment
// =============================================================================

it('keeps the collected payment and closes the order even when stock-out fails at close', function () {
    $ctx = seedOrgWithShop('money-safety-shop');
    $manager = seedManagerFor($ctx['orgId']);

    // Warehouse that DISALLOWS negative sales → a track_stock SKU with zero
    // on-hand throws InsufficientStockException during the close stock-out.
    Warehouse::factory()->create([
        'organization_id' => $ctx['orgId'],
        'branch_id' => $ctx['shop']->id,
        'is_active' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => false,
    ]);

    $sku = seedTrackStockSku($ctx, 3000);
    $order = seedCheckoutOrderFor($ctx, $sku, 3000);

    $cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $ctx['orgId'],
        'branch_id' => $ctx['shop']->id,
    ]);

    $this->actingAs($manager)
        ->postJson("/api/v1/shops/{$ctx['shop']->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $cash->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
        ])
        ->assertCreated();

    // The payment survived the stock failure …
    $this->assertDatabaseHas('order_payments', [
        'customer_order_id' => $order->id,
        'status' => 'succeeded',
        'amount' => 3000,
    ]);

    // … and the order still closed with the money recorded.
    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(3000.0);
});

// =============================================================================
//  Finding 2 — payment_method_id must be tenant-scoped
// =============================================================================

it('rejects a payment method owned by another organisation', function () {
    $a = seedOrgWithShop('tenant-a-shop');
    $b = seedOrgWithShop('tenant-b-shop');
    $manager = seedManagerFor($a['orgId']);

    Warehouse::factory()->create([
        'organization_id' => $a['orgId'], 'branch_id' => $a['shop']->id,
        'is_active' => true, 'auto_approve_stock_out' => true, 'allow_negative_sales' => true,
    ]);

    $sku = seedTrackStockSku($a, 3000);
    $order = seedCheckoutOrderFor($a, $sku, 3000);

    // Cash method that belongs to a DIFFERENT organisation entirely.
    $foreignCash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $b['orgId'],
        'branch_id' => $b['shop']->id,
    ]);

    $this->actingAs($manager)
        ->postJson("/api/v1/shops/{$a['shop']->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $foreignCash->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method_id');

    $this->assertDatabaseMissing('order_payments', ['customer_order_id' => $order->id]);
});

it('rejects a branch-scoped payment method owned by another branch in the same org', function () {
    $ctx = seedOrgWithShop('same-org-shop-a');
    $manager = seedManagerFor($ctx['orgId']);

    // A second branch in the SAME organisation.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $ctx['orgId'],
        'console_brand_id' => $ctx['brand']->console_brand_id,
        'slug' => 'same-org-shop-b',
        'is_active' => true,
    ]);

    $sku = seedTrackStockSku($ctx, 3000);
    $order = seedCheckoutOrderFor($ctx, $sku, 3000);

    // Method scoped to the OTHER branch (branch_id set, not org-wide).
    $otherBranchCash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $ctx['orgId'],
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($manager)
        ->postJson("/api/v1/shops/{$ctx['shop']->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $otherBranchCash->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method_id');
});

it('accepts an org-wide (branch_id null) payment method', function () {
    $ctx = seedOrgWithShop('org-wide-shop');
    $manager = seedManagerFor($ctx['orgId']);

    Warehouse::factory()->create([
        'organization_id' => $ctx['orgId'], 'branch_id' => $ctx['shop']->id,
        'is_active' => true, 'auto_approve_stock_out' => true, 'allow_negative_sales' => true,
    ]);

    $sku = seedTrackStockSku($ctx, 3000);
    $order = seedCheckoutOrderFor($ctx, $sku, 3000);

    // Org-wide method — branch_id NULL, same organisation (BR-PM05).
    $orgWideCash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $ctx['orgId'],
        'branch_id' => null,
    ]);

    $this->actingAs($manager)
        ->postJson("/api/v1/shops/{$ctx['shop']->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $orgWideCash->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
        ])
        ->assertCreated();
});

it('rejects an inactive payment method on the POS payment path', function () {
    $ctx = seedOrgWithShop('inactive-method-shop');
    $manager = seedManagerFor($ctx['orgId']);

    $sku = seedTrackStockSku($ctx, 3000);
    $order = seedCheckoutOrderFor($ctx, $sku, 3000);

    $inactiveCash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $ctx['orgId'],
        'branch_id' => $ctx['shop']->id,
        'is_active' => false,
    ]);

    $this->actingAs($manager)
        ->postJson("/api/v1/shops/{$ctx['shop']->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $inactiveCash->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method_id');

    $this->assertDatabaseMissing('order_payments', ['customer_order_id' => $order->id]);
});
