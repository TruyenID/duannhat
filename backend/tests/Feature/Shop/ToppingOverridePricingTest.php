<?php

declare(strict_types=1);

/**
 * Plan-013 (Phase 2 Extensions / plan-audit logic-risk) — per-product topping
 * override_price MUST be applied at order pricing.
 *
 * `ToppingPricingService::resolveSnapshotPrice()` previously queried ONLY
 * `topping_group_item_skus` (HQ base tier), so the whole
 * `product_topping_group_item_overrides` feature was dead at runtime: the
 * customer saw the overridden price on the read path but the order line was
 * charged the HQ base price. These tests pin the documented precedence
 * (DESIGN.md D7-D11 + the ProductToppingGroupItemOverride model docblock) and
 * the >= 0 clamp.
 */

use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Topping\ToppingPricingService;

beforeEach(function () {
    $this->service = new ToppingPricingService;
    $this->group = ToppingGroup::factory()->create();
    $this->item = ToppingGroupItem::factory()->create(['topping_group_id' => $this->group->id]);
    $this->product = Product::factory()->create();
    $this->sku = ProductSku::factory()->create(['product_id' => $this->product->id]);

    // HQ base extra_price for the chosen SKU = 1000.
    ToppingGroupItemSku::factory()->withSku($this->sku)->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 1000,
    ]);
});

it('applies the per-product override_price over the HQ base extra_price', function () {
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->sku->id,
        'is_hidden' => false,
        'override_price' => 3000,
    ]);

    $price = $this->service->resolveSnapshotPrice(
        $this->item->id,
        $this->sku->id,
        $this->product->id,
        $this->group->id,
    );

    expect($price)->toBe(3000.0);
});

it('honours override_price of 0 (free topping for this product)', function () {
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->sku->id,
        'is_hidden' => false,
        'override_price' => 0,
    ]);

    $price = $this->service->resolveSnapshotPrice(
        $this->item->id,
        $this->sku->id,
        $this->product->id,
        $this->group->id,
    );

    expect($price)->toBe(0.0);
});

it('lets an exact-SKU override win over a wildcard (NULL) override', function () {
    // Wildcard override for the whole item.
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => null,
        'is_hidden' => false,
        'override_price' => 2000,
    ]);
    // Scoped override for the chosen SKU — must win.
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->sku->id,
        'is_hidden' => false,
        'override_price' => 4500,
    ]);

    $price = $this->service->resolveSnapshotPrice(
        $this->item->id,
        $this->sku->id,
        $this->product->id,
        $this->group->id,
    );

    expect($price)->toBe(4500.0);
});

it('ignores a hidden override and falls back to the HQ base price', function () {
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->sku->id,
        'is_hidden' => true,
        'override_price' => null,
    ]);

    $price = $this->service->resolveSnapshotPrice(
        $this->item->id,
        $this->sku->id,
        $this->product->id,
        $this->group->id,
    );

    expect($price)->toBe(1000.0);
});

it('falls back to base when override_price is NULL (use group default)', function () {
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->sku->id,
        'is_hidden' => false,
        'override_price' => null,
    ]);

    $price = $this->service->resolveSnapshotPrice(
        $this->item->id,
        $this->sku->id,
        $this->product->id,
        $this->group->id,
    );

    expect($price)->toBe(1000.0);
});

it('does not leak another product\'s override into this product\'s price', function () {
    $otherProduct = Product::factory()->create();
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $otherProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->sku->id,
        'is_hidden' => false,
        'override_price' => 9999,
    ]);

    $price = $this->service->resolveSnapshotPrice(
        $this->item->id,
        $this->sku->id,
        $this->product->id,
        $this->group->id,
    );

    expect($price)->toBe(1000.0);
});

it('returns a negative extra_price verbatim (discount topping — biz rule)', function () {
    // A discount topping carries a negative extra_price and MUST apply as a
    // real discount at pricing (the line-level floor in CustomerOrderService
    // stops the whole line going below zero — see the discount-topping
    // pricing test below).
    $discountItem = ToppingGroupItem::factory()->create(['topping_group_id' => $this->group->id]);
    ToppingGroupItemSku::factory()->withSku($this->sku)->create([
        'topping_group_item_id' => $discountItem->id,
        'extra_price' => -500,
    ]);

    $price = $this->service->resolveSnapshotPrice($discountItem->id, $this->sku->id);

    expect($price)->toBe(-500.0);
});
