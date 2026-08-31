<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Warehouse;
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

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'split-bill-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Split',
        'last_name' => 'Bill',
    ]);

    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

// =========================================================================
//  Even split
// =========================================================================

it('even split of 1000 by 4 returns [250.00, 250.00, 250.00, 250.00]', function () {
    $order = createSplitOrder(1000);

    // Move to checkout so split-bill is allowed
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/split-bill?split_count=4")
        ->assertOk();

    expect($response->json('split_count'))->toBe(4);
    expect($response->json('per_person_amounts'))->toBe(['250.00', '250.00', '250.00', '250.00']);
    expect($response->json('rounding_note'))->toBeNull();
});

// =========================================================================
//  Uneven split — first person absorbs remainder
// =========================================================================

it('uneven split of 1000 by 3 returns [334.00, 333.00, 333.00] with rounding_note', function () {
    $order = createSplitOrder(1000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/split-bill?split_count=3")
        ->assertOk();

    expect($response->json('split_count'))->toBe(3);
    expect($response->json('per_person_amounts'))->toBe(['334.00', '333.00', '333.00']);
    expect($response->json('rounding_note'))->not->toBeNull();
});

// =========================================================================
//  Split remaining after partial payment
// =========================================================================

it('splitting remaining 600 after 400 paid by 3 gives [200.00, 200.00, 200.00]', function () {
    $order = createSplitOrder(1000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();

    // Card payment: 400 partial (non-auto-confirm so order stays in paying)
    $cardMethod = PaymentMethod::factory()->card()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $cardMethod->id,
            'amount' => 400,
        ])
        ->assertCreated();

    // Manually confirm the payment so paid_amount is updated
    $payment = $order->payments()->first();
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/confirm")
        ->assertOk();

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/split-bill?split_count=3")
        ->assertOk();

    expect($response->json('remaining_amount'))->toBe('600.00');
    expect($response->json('per_person_amounts'))->toBe(['200.00', '200.00', '200.00']);
});

// =========================================================================
//  Validation errors
// =========================================================================

it('split_count less than 2 returns 422', function () {
    $order = createSplitOrder(1000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/split-bill?split_count=1")
        ->assertStatus(422);
});

// =========================================================================
//  Status guard
// =========================================================================

it('requesting split-bill on an open (pre-checkout) order returns 409', function () {
    $order = createSplitOrder(1000);

    // Order is still 'open', not yet checked out
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/split-bill?split_count=2")
        ->assertStatus(409);
});

// =========================================================================
//  Helper
// =========================================================================

function createSplitOrder(float $totalAmount): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-SPL-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => $totalAmount,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $totalAmount,
        'paid_amount' => 0,
        'total_tip' => 0,
        'guest_count' => 4,
        'created_by_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    Table::where('id', test()->table->id)->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 2,
        'unit_price' => $totalAmount / 2,
        'original_unit_price' => $totalAmount / 2,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => $totalAmount,
        'status' => 'pending',
    ]);

    return $order->load('items');
}
