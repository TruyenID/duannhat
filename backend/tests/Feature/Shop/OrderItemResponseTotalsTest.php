<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

/*
 * Plan 007 — Phase 1: assert item-mutation endpoints return the full
 * CustomerOrderResource with recomputed totals + nested items + tables.
 *
 * The Shop controller swapped item-only responses to CustomerOrderResource
 * in T1.1–T1.3 so POS clients refresh cart and tab-chip in one round trip.
 * These tests lock that contract.
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
        'slug' => 'item-totals-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 500,
        'is_active' => true,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
    ]);
});

// =========================================================================
//  POST /items — response carries recomputed totals + items + tables
// =========================================================================

it('POST /items returns the full order with recomputed subtotal and total_amount', function () {
    $order = createTotalsOrder();

    // Starting state: 1 item qty=2 @ unit_price=1200 → subtotal 2400
    expect((float) $order->fresh()->subtotal)->toBe(2400.0);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 3],
            ],
        ])
        ->assertCreated();

    // New item: qty=3 @ unit_price=500 → +1500. Order subtotal → 3900.
    expect((float) $response->json('data.subtotal'))->toBe(3900.0);
    expect((float) $response->json('data.total_amount'))->toBe(3900.0);
    expect($response->json('data.items'))->toHaveCount(2);
    expect($response->json('data.tables'))->toBeArray();
});

// =========================================================================
//  PATCH /items/{id} — quantity change recomputes totals
// =========================================================================

it('PATCH /items/{id} with quantity change returns the full order with updated totals', function () {
    $order = createTotalsOrder();
    $item = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}", [
            'quantity' => 5,
        ])
        ->assertOk();

    // qty 5 @ unit_price 1200 → subtotal 6000
    expect((float) $response->json('data.subtotal'))->toBe(6000.0);
    expect((float) $response->json('data.total_amount'))->toBe(6000.0);

    $updated = collect($response->json('data.items'))->firstWhere('id', $item->id);
    expect((float) $updated['quantity'])->toBe(5.0);
});

// =========================================================================
//  POST /items/{id}/void — voided item reduces subtotal, still appears
// =========================================================================

it('POST /items/{id}/void returns the full order with subtotal reduced by the voided item', function () {
    $order = createTotalsOrder(itemCount: 2);

    // Starting state: 2 items × (qty=2, unit_price=1200) → subtotal 4800
    expect((float) $order->fresh()->subtotal)->toBe(4800.0);

    $target = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$target->id}/void", [
            'void_reason' => 'Customer changed mind',
        ])
        ->assertOk();

    // Voided item (2400) excluded from subtotal → remaining 2400.
    expect((float) $response->json('data.subtotal'))->toBe(2400.0);
    expect((float) $response->json('data.total_amount'))->toBe(2400.0);

    // Voided item still appears in items[] with status = voided.
    $voided = collect($response->json('data.items'))->firstWhere('id', $target->id);
    expect($voided['status'])->toBe('voided');
    expect($voided['void_reason'])->toBe('Customer changed mind');
});

// =========================================================================
//  DELETE /items/{id} — status 200 (not 204), body carries updated totals
// =========================================================================

it('DELETE /items/{id} returns 200 with the full order body and reduced subtotal', function () {
    $order = createTotalsOrder(itemCount: 2);
    $target = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$target->id}")
        ->assertOk();

    expect((float) $response->json('data.subtotal'))->toBe(2400.0);
    expect((float) $response->json('data.total_amount'))->toBe(2400.0);

    // removeItem delegates to voidItem — the item stays in the list but voided.
    $removed = collect($response->json('data.items'))->firstWhere('id', $target->id);
    expect($removed['status'])->toBe('voided');
});

// =========================================================================
//  Wire contract: snake_case relation keys + eager-loaded product_sku
// =========================================================================

it('items expose product_sku (snake_case, not productSku) when eager-loaded', function () {
    $order = createTotalsOrder();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $items = $response->json('data.items');
    expect($items)->not->toBeEmpty();
    foreach ($items as $item) {
        expect($item)->not->toHaveKey('productSku');
        expect($item)->toHaveKey('product_sku');
        expect($item['product_sku'])->toHaveKey('id');
    }
});

it('item.product_sku eager-loads the product so FE can show product+variant name', function () {
    $order = createTotalsOrder();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $items = $response->json('data.items');
    foreach ($items as $item) {
        $sku = $item['product_sku'];
        expect($sku)->toHaveKey('product_id');
        expect($sku)->toHaveKey('selling_price');
        // Eager-loaded product gives the FE the parent name for the cart line.
        expect($sku)->toHaveKey('product');
        expect($sku['product'])->toHaveKey('name');
    }
});

it('resolves unit_price from the explicit menu_product_sku_id when the SKU is in multiple menus', function () {
    // Two menus both carry this SKU with different prices.
    // menuA overrides to 1350 (this is the one staff picked from in UI),
    // menuB overrides to 2100 (wrong price — we must NOT pick this).
    $menuA = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
    ]);
    $menuProductA = MenuProduct::factory()->create([
        'menu_id' => $menuA->id,
        'product_id' => $this->sku->product_id,
        'is_active' => true,
    ]);
    $menuSkuA = MenuProductSku::factory()->create([
        'menu_product_id' => $menuProductA->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 1350,
        'is_active' => true,
    ]);

    $menuB = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
    ]);
    $menuProductB = MenuProduct::factory()->create([
        'menu_id' => $menuB->id,
        'product_id' => $this->sku->product_id,
        'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProductB->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 2100,
        'is_active' => true,
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-MP-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // Staff clicked the variant in menuA → FE sends menuSkuA's id.
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'menu_product_sku_id' => $menuSkuA->id,
                'quantity' => 1,
            ]],
        ])
        ->assertCreated();

    $items = $response->json('data.items');
    expect($items)->toHaveCount(1);
    // Must be 1350 (menuA's price), NOT 2100 (menuB) and NOT sku.selling_price.
    expect((float) $items[0]['unit_price'])->toBe(1350.0);
});

it('writes SKU selling_price into unit_price when no menu override exists', function () {
    // Update the shared SKU's selling_price to a distinctive number so we
    // can prove the addItems service read it, not a stale snapshot.
    $this->sku->update(['selling_price' => 7777]);

    // Fresh empty order — no pre-seeded items so we can assert the new
    // line's unit_price directly.
    $order = CustomerOrder::create([
        'order_code' => 'ORD-SP-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 2],
            ],
        ])
        ->assertCreated();

    $items = $response->json('data.items');
    expect($items)->toHaveCount(1);
    expect((float) $items[0]['unit_price'])->toBe(7777.0);
    expect((float) $items[0]['subtotal'])->toBe(15554.0);
});

// =========================================================================
//  Every mutation eager-loads items + tables so the FE avoids a GET
// =========================================================================

it('every item-mutation response eager-loads items[] and tables[]', function () {
    $order = createTotalsOrder();
    $item = $order->items->first();

    $addResponse = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $patchResponse = $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}", [
            'quantity' => 3,
        ])
        ->assertOk();

    $voidResponse = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Test void',
        ])
        ->assertOk();

    foreach ([$addResponse, $patchResponse, $voidResponse] as $response) {
        expect($response->json('data.items'))->toBeArray();
        expect($response->json('data.tables'))->toBeArray();
        expect($response->json('data.tables'))->toHaveCount(1);
        expect($response->json('data.tables.0.id'))->toBe($this->table->id);
    }
});

// =========================================================================
//  Helper
// =========================================================================

/**
 * Create an open dine-in order with $itemCount pending items (qty=2 @ 1200).
 */
function createTotalsOrder(int $itemCount = 1): CustomerOrder
{
    $subtotal = 2400 * $itemCount;

    $order = CustomerOrder::create([
        'order_code' => 'ORD-RT-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => $subtotal,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $subtotal,
        'paid_amount' => 0,
        'total_tip' => 0,
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

    for ($i = 0; $i < $itemCount; $i++) {
        $order->items()->create([
            'product_sku_id' => test()->sku->id,
            'quantity' => 2,
            'unit_price' => 1200,
            'original_unit_price' => 1200,
            'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
            'subtotal' => 2400,
            'status' => 'pending',
        ]);
    }

    return $order->load('items');
}
