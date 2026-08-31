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
        'slug' => 'cancel-shop',
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
        'status' => 'free',
        'current_order_id' => null,
    ]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
    ]);
});

// =========================================================================
//  void_reason is mandatory and is recorded
// =========================================================================

it('stamps the supplied void_reason onto the order and its items', function () {
    $order = createVoidableOrder();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Het nguyen lieu',
        ])
        ->assertSuccessful();

    $order->refresh();

    expect($order->status->value)->toBe('voided')
        ->and($order->void_reason)->toBe('Het nguyen lieu')
        ->and($order->voided_at)->not->toBeNull();

    expect($order->items()->pluck('void_reason')->all())
        ->each->toBe('Het nguyen lieu');
});

it('rejects a void with no reason', function (mixed $reason) {
    $order = createVoidableOrder();

    $this->actingAs($this->manager)
        ->postJson(
            "/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void",
            $reason === null ? [] : ['void_reason' => $reason],
        )
        ->assertStatus(422);

    expect($order->refresh()->status->value)->toBe('open');
})->with([
    'omitted' => null,
    'empty string' => '',
]);

// =========================================================================
//  Status guard — everything but a settled bill can be voided
// =========================================================================

it('voids an order in any pre-settlement status', function (string $status) {
    $order = createVoidableOrder();
    $order->update(['status' => $status]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Khach doi y',
        ])
        ->assertSuccessful();

    expect($order->refresh()->status->value)->toBe('voided');
})->with([
    'pending', 'awaiting_confirmation', 'confirmed',
    'open', 'dining', 'checkout', 'paying',
]);

it('returns 409 when voiding a closed order', function () {
    $order = createVoidableOrder();
    $order->update([
        'status' => 'closed',
        'checkout_at' => now()->subMinutes(10),
        'closed_at' => now(),
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Too late',
        ])
        ->assertStatus(409);

    expect($order->refresh()->status->value)->toBe('closed');
});

// =========================================================================
//  The retired /cancel alias must be gone
// =========================================================================

it('no longer exposes the /cancel alias', function () {
    $order = createVoidableOrder();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/cancel")
        ->assertNotFound();

    expect($order->refresh()->status->value)->toBe('open');
});

// =========================================================================
//  Side effects
// =========================================================================

it('releases the table held by the voided order', function () {
    $order = createVoidableOrder();

    expect($this->table->refresh()->current_order_id)->toBe($order->id);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Nham ban',
        ])
        ->assertSuccessful();

    $this->table->refresh();

    expect($this->table->current_order_id)->toBeNull()
        ->and($this->table->status->value)->toBe('free');
});

function createVoidableOrder(): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-C'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 2400,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 2400,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'created_by_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 2,
        'unit_price' => 1200,
        'original_unit_price' => 1200,
        'subtotal' => 2400,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);

    test()->table->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    return $order->refresh();
}
