<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrderItem;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use App\Omnify\Enums\ProductStatusEnum;
use Illuminate\Testing\TestResponse;

/**
 * #902 — a customer (or any caller of CustomerOrderService::addItems) must not
 * be able to add an order line for a product that is not sellable: only an
 * `active` product with an `is_active` SKU may be ordered. draft / pending /
 * approved (approved but never activated) / inactive (paused) / rejected
 * products, and inactive SKUs, are rejected with a 422 before any line lands.
 *
 * The companion (CustomerMenuService) must also hide non-active products so the
 * customer never sees a card the order gate would reject ("thấy = đặt được").
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'sellability-token',
        'is_active' => true,
        'status' => 'free',
    ]);
});

/** A ProductSku whose parent product carries the given lifecycle status. */
function sgSku(string $status, bool $skuActive = true): ProductSku
{
    $product = Product::factory()->create(['status' => $status]);

    return ProductSku::factory()
        ->state(['is_active' => $skuActive])
        ->create(['product_id' => $product->id]);
}

function sgOrder(array $items): TestResponse
{
    return test()->postJson('/api/v1/customer/tables/sellability-token/orders', [
        'items' => $items,
    ]);
}

// =========================================================================
//  Order gate (#1) — happy path
// =========================================================================

it('adds a line for an active product with an active SKU', function () {
    $sku = sgSku(ProductStatusEnum::Active->value);

    sgOrder([['product_sku_id' => $sku->id, 'quantity' => 1]])
        ->assertStatus(201);

    expect(CustomerOrderItem::where('product_sku_id', $sku->id)->count())->toBe(1);
});

it('accepts an off-menu but active SKU (off-menu ≠ non-sellable)', function () {
    // Active product + active SKU that sits on NO menu — the price falls back
    // to ProductSku::selling_price. The gate must NOT over-block this.
    $sku = sgSku(ProductStatusEnum::Active->value);

    sgOrder([['product_sku_id' => $sku->id, 'quantity' => 1]])
        ->assertStatus(201);

    expect(CustomerOrderItem::where('product_sku_id', $sku->id)->count())->toBe(1);
});

// =========================================================================
//  Order gate (#1) — blocked
// =========================================================================

it('rejects a non-sellable product status with 422 and adds no line', function (string $status) {
    $sku = sgSku($status);

    sgOrder([['product_sku_id' => $sku->id, 'quantity' => 1]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.product_sku_id');

    expect(CustomerOrderItem::where('product_sku_id', $sku->id)->count())->toBe(0);
    // create + addItems share one transaction — a rejected line rolls the
    // whole order back, so nothing is persisted.
    $this->assertDatabaseCount('customer_orders', 0);
})->with([
    'draft' => [ProductStatusEnum::Draft->value],
    'pending' => [ProductStatusEnum::Pending->value],
    'approved (not yet activated)' => [ProductStatusEnum::Approved->value],
    'inactive (paused)' => [ProductStatusEnum::Inactive->value],
    'rejected' => [ProductStatusEnum::Rejected->value],
]);

it('rejects an inactive SKU even when the product is active', function () {
    $sku = sgSku(ProductStatusEnum::Active->value, skuActive: false);

    sgOrder([['product_sku_id' => $sku->id, 'quantity' => 1]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.product_sku_id');

    expect(CustomerOrderItem::where('product_sku_id', $sku->id)->count())->toBe(0);
});

it('rejects a non-sellable product even via an active menu line (defense-in-depth)', function () {
    // A stale but active MenuProductSku pointing at a now-paused product must
    // still be blocked — the gate sits before the menu/non-menu price branch.
    $product = Product::factory()->create(['status' => ProductStatusEnum::Inactive->value]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'is_active' => true,
    ]);
    $mps = MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => 500,
    ]);

    sgOrder([[
        'product_sku_id' => $sku->id,
        'menu_product_sku_id' => $mps->id,
        'quantity' => 1,
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.product_sku_id');

    expect(CustomerOrderItem::where('product_sku_id', $sku->id)->count())->toBe(0);
});

// =========================================================================
//  Menu display gate (#4) — CustomerMenuService
// =========================================================================

it('hides non-active products from the customer menu even on active menu lines', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    $sellable = Product::factory()->create([
        'status' => ProductStatusEnum::Active->value,
        'name' => 'Sellable Latte',
        'organization_id' => $this->orgId,
    ]);
    $paused = Product::factory()->create([
        'status' => ProductStatusEnum::Inactive->value,
        'name' => 'Paused Mocha',
        'organization_id' => $this->orgId,
    ]);

    foreach ([$sellable, $paused] as $product) {
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 400]);
        $mp = MenuProduct::factory()->create([
            'menu_id' => $menu->id,
            'product_id' => $product->id,
            'is_active' => true,
            'display_order' => 0,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $mp->id,
            'product_sku_id' => $sku->id,
            'is_active' => true,
            'selling_price' => 400,
        ]);
    }

    $names = collect($this->getJson('/api/v1/customer/tables/sellability-token/menu')
        ->assertOk()
        ->json('data.categories'))
        ->flatMap(fn ($c) => collect($c['items'])->pluck('name'))
        ->all();

    expect($names)->toContain('Sellable Latte')
        ->and($names)->not->toContain('Paused Mocha');
});
