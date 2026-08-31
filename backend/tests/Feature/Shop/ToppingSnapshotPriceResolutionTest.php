<?php

declare(strict_types=1);

/**
 * Plan-013 — money-critical topping price resolution (test-gap coverage).
 *
 * `ToppingPricingService::resolveSnapshotPrice()` is the single point that
 * decides how much a chosen topping costs when an order line is priced. It
 * hits the DB (`topping_group_item_skus`) and applies a NULL-fallback:
 *
 *   - an exact per-SKU override row wins over the simple NULL row, and
 *   - the NULL ("no variant") row is the fallback when no SKU-specific
 *     override exists.
 *
 * A wrong resolution here silently over/under-charges every order, so the
 * fallback ordering + the "no price row" failure path deserve explicit
 * coverage. Before this file the method had ZERO tests (all pricing coverage
 * was for the pure `priceLine()` math, which never touches the DB).
 */

use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductToppingItemOverride;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Topping\ToppingPricingService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->service = new ToppingPricingService;
    $this->item = ToppingGroupItem::factory()->create();
});

it('resolves the exact per-SKU override even when a NULL fallback row also exists', function () {
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    // Simple NULL row (would apply if there were no variant override)...
    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 1000,
    ]);
    // ...but the chosen SKU has its own override — it must win.
    ToppingGroupItemSku::factory()->withSku($sku)->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 3000,
    ]);

    $price = $this->service->resolveSnapshotPrice($this->item->id, $sku->id);

    expect($price)->toBe(3000.0);
});

it('falls back to the NULL row when the chosen SKU has no override', function () {
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    // Only the simple NULL row exists.
    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 1500,
    ]);

    $price = $this->service->resolveSnapshotPrice($this->item->id, $sku->id);

    expect($price)->toBe(1500.0);
});

it('does not leak another item\'s override row into the fallback', function () {
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    // A NULL row belonging to a DIFFERENT item — must be ignored.
    $otherItem = ToppingGroupItem::factory()->create();
    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $otherItem->id,
        'extra_price' => 9999,
    ]);

    // This item's own NULL fallback is 2000.
    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 2000,
    ]);

    $price = $this->service->resolveSnapshotPrice($this->item->id, $sku->id);

    expect($price)->toBe(2000.0);
});

it('throws topping_item_no_price when neither a SKU override nor a NULL row exists', function () {
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    // No ToppingGroupItemSku rows at all for this item.
    expect(fn () => $this->service->resolveSnapshotPrice($this->item->id, $sku->id))
        ->toThrow(ValidationException::class);

    try {
        $this->service->resolveSnapshotPrice($this->item->id, $sku->id);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('items.toppings');
        expect($e->errors()['items.toppings'][0])->toContain('topping_item_no_price');
    }
});

// =========================================================================
//  Tier-1 SHOP override (menu_product_topping_item_overrides). Applied only
//  when the caller passes $menuProductId — the order engine + customer menu
//  do; the offline snapshot (product-keyed) does not, so it is unaffected.
//  Parity target: workstation local_pos_menus.go (tier-1 wins tier-2).
// =========================================================================

it('applies the shop override (tier 1) over the base extra_price when menuProductId is passed', function () {
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);
    $menu = Menu::factory()->create();
    $menuProduct = MenuProduct::factory()->create(['menu_id' => $menu->id, 'product_id' => $product->id]);

    ToppingGroupItemSku::factory()->withSku($sku)->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 300,
    ]);
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $menuProduct->id,
        'topping_group_id' => $this->item->topping_group_id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $sku->id,
        'is_hidden' => false,
        'override_price' => 500,
    ]);

    // With menu line context → shop tier applies (500).
    expect($this->service->resolveSnapshotPrice($this->item->id, $sku->id, $product->id, $this->item->topping_group_id, $menuProduct->id))
        ->toBe(500.0);

    // Without it (offline / off-menu) → shop tier skipped, base 300.
    expect($this->service->resolveSnapshotPrice($this->item->id, $sku->id, $product->id, $this->item->topping_group_id))
        ->toBe(300.0);
});

it('shop override (tier 1) wins over the HQ per-product override (tier 2)', function () {
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);
    $menu = Menu::factory()->create();
    $menuProduct = MenuProduct::factory()->create(['menu_id' => $menu->id, 'product_id' => $product->id]);

    ToppingGroupItemSku::factory()->withSku($sku)->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 300,
    ]);
    ProductToppingGroupItemOverride::create([
        'product_id' => $product->id,
        'topping_group_id' => $this->item->topping_group_id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $sku->id,
        'is_hidden' => false,
        'override_price' => 250,
    ]);
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $menuProduct->id,
        'topping_group_id' => $this->item->topping_group_id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $sku->id,
        'is_hidden' => false,
        'override_price' => 500,
    ]);

    // Tier-1 shop (500) beats tier-2 HQ (250).
    expect($this->service->resolveSnapshotPrice($this->item->id, $sku->id, $product->id, $this->item->topping_group_id, $menuProduct->id))
        ->toBe(500.0);
});

it('preserves decimal precision (Decimal(15,2)) on the resolved extra_price', function () {
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    ToppingGroupItemSku::factory()->withSku($sku)->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 2500.75,
    ]);

    $price = $this->service->resolveSnapshotPrice($this->item->id, $sku->id);

    expect($price)->toBe(2500.75);
});
