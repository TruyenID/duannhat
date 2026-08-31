<?php

declare(strict_types=1);

/**
 * Floating section is "a menu thu nhỏ" — its shop-level topping overrides use
 * the SAME tier-1 mechanism as the menu. These tests pin:
 *   1. ToppingPricingService tier-1 resolves a floating-section-product override
 *      (parity with the menu-product override).
 *   2. ShopFloatingSectionToppingOverrideService sync validates + persists +
 *      replaces, sharing validation with the menu service.
 */

use App\Models\FloatingSection;
use App\Models\FloatingSectionProductToppingItemOverride;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Topping\ShopFloatingSectionToppingOverrideService;
use App\Services\Topping\ToppingPricingService;

beforeEach(function () {
    $this->product = Product::factory()->create();
    $this->sku = ProductSku::factory()->create(['product_id' => $this->product->id]);
    $this->group = ToppingGroup::factory()->create();
    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $this->product->id,
    ]);

    // HQ base extra_price = 1000.
    ToppingGroupItemSku::factory()->withSku($this->sku)->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 1000,
    ]);

    // Create via the relation (the FloatingSectionProduct factory carries a
    // stale selling_price/is_price_overridden pair that no longer maps to a
    // column — price lives on the SKU child, not the product row).
    $section = FloatingSection::factory()->create();
    $this->sectionProduct = $section->products()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
});

// =============================================================================
//  Tier-1 resolver parity
// =============================================================================

it('resolves a floating section product topping override over the HQ base price', function () {
    FloatingSectionProductToppingItemOverride::factory()->create([
        'floating_section_product_id' => $this->sectionProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->sku->id,
        'is_hidden' => false,
        'override_price' => 3000,
    ]);

    $price = (new ToppingPricingService)->resolveSnapshotPrice(
        toppingGroupItemId: $this->item->id,
        productSkuId: $this->sku->id,
        productId: $this->product->id,
        toppingGroupId: $this->group->id,
        floatingSectionProductId: $this->sectionProduct->id,
    );

    expect($price)->toBe(3000.0);
});

it('falls back to the HQ base price when no floating section override exists', function () {
    $price = (new ToppingPricingService)->resolveSnapshotPrice(
        toppingGroupItemId: $this->item->id,
        productSkuId: $this->sku->id,
        productId: $this->product->id,
        toppingGroupId: $this->group->id,
        floatingSectionProductId: $this->sectionProduct->id,
    );

    expect($price)->toBe(1000.0);
});

// =============================================================================
//  Service sync
// =============================================================================

it('persists a floating section topping override on sync', function () {
    $service = new ShopFloatingSectionToppingOverrideService;

    $result = $service->sync($this->sectionProduct, $this->group, [
        [
            'topping_group_item_id' => $this->item->id,
            'product_sku_id' => $this->sku->id,
            'is_hidden' => false,
            'override_price' => 2500,
        ],
    ]);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseHas('floating_section_product_topping_item_overrides', [
        'floating_section_product_id' => $this->sectionProduct->id,
        'topping_group_item_id' => $this->item->id,
        'override_price' => 2500,
    ]);
});

it('replaces existing overrides on sync (empty array clears all)', function () {
    $service = new ShopFloatingSectionToppingOverrideService;

    $service->sync($this->sectionProduct, $this->group, [
        ['topping_group_item_id' => $this->item->id, 'product_sku_id' => null, 'is_hidden' => false, 'override_price' => 500],
    ]);

    $service->sync($this->sectionProduct, $this->group, []);

    $this->assertDatabaseMissing('floating_section_product_topping_item_overrides', [
        'floating_section_product_id' => $this->sectionProduct->id,
    ]);
});

it('rejects an override for a topping item outside the group', function () {
    $service = new ShopFloatingSectionToppingOverrideService;
    $otherItem = ToppingGroupItem::factory()->create(); // different group

    expect(fn () => $service->sync($this->sectionProduct, $this->group, [
        ['topping_group_item_id' => $otherItem->id, 'product_sku_id' => null, 'is_hidden' => false, 'override_price' => 100],
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects override_price set together with is_hidden', function () {
    $service = new ShopFloatingSectionToppingOverrideService;

    expect(fn () => $service->sync($this->sectionProduct, $this->group, [
        ['topping_group_item_id' => $this->item->id, 'product_sku_id' => null, 'is_hidden' => true, 'override_price' => 100],
    ]))->toThrow(InvalidArgumentException::class);
});
