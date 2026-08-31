<?php

use App\Models\Brand;
use App\Models\MenuProduct;
use App\Models\MenuProductToppingItemOverride;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Topping\ShopMenuToppingOverrideService;
use App\Services\Topping\ToppingPricingService;
use Illuminate\Support\Str;

/**
 * Characterisation: what does Cloud charge when a SHOP tier-1 override row
 * EXISTS but carries a NULL override_price ("use the group default"), and an HQ
 * tier-2 override also exists for the same item?
 *
 * This pins the Cloud answer so the workstation's answer can be compared
 * against it — the two engines price the same basket and must agree, or the
 * same order costs different money depending on whether it went through Cloud
 * or the LAN, and an offline order re-priced from the Cloud snapshot gets
 * rejected as tampered.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'price_strategy' => 'flat',
        'is_active' => true,
    ]);
    ProductToppingGroup::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
    ]);

    $toppingProduct = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->product->product_type_id,
    ]);
    $this->toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'selling_price' => 0,
        'is_active' => true,
    ]);
    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
    ]);

    // Tier 3 — catalogue base.
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'extra_price' => 100,
    ]);
});

function priceWith(?string $menuProductId): float
{
    return app(ToppingPricingService::class)->resolveSnapshotPrice(
        (string) test()->item->id,
        (string) test()->toppingSku->id,
        (string) test()->product->id,
        (string) test()->group->id,
        $menuProductId,
    );
}

it('falls through a NULL-price tier-1 row to the HQ tier-2 override', function () {
    // HQ says 250 for this product.
    ProductToppingGroupItemOverride::create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => false,
        'override_price' => 250,
    ]);

    // The shop has a row for this menu line but set no price on it — the
    // "use the group default" shape.
    $override = MenuProductToppingItemOverride::create([
        'menu_product_id' => (string) Str::uuid(),
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => false,
        'override_price' => null,
    ]);

    // Cloud: the NULL-price tier-1 row does NOT short-circuit, so tier-2 wins.
    expect(priceWith((string) $override->menu_product_id))->toBe(250.0);
});

it('uses the tier-1 price when it carries one', function () {
    ProductToppingGroupItemOverride::create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => false,
        'override_price' => 250,
    ]);

    $override = MenuProductToppingItemOverride::create([
        'menu_product_id' => (string) Str::uuid(),
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => false,
        'override_price' => 400,
    ]);

    expect(priceWith((string) $override->menu_product_id))->toBe(400.0);
});

it('falls back to the catalogue base when neither tier overrides', function () {
    expect(priceWith(null))->toBe(100.0);
});

it('#1203 refuses to store an override row that neither hides nor prices', function () {
    // The shape that made the two engines disagree can no longer be created:
    // "use the group default" is expressed by having no row at all.
    $menuProduct = MenuProduct::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
    ]);

    expect(fn () => app(ShopMenuToppingOverrideService::class)->sync(
        $menuProduct,
        $this->group,
        [[
            'topping_group_item_id' => (string) $this->item->id,
            'product_sku_id' => (string) $this->toppingSku->id,
            'is_hidden' => false,
            'override_price' => null,
        ]],
    ))->toThrow(InvalidArgumentException::class, 'either hide the topping or set a price');
});

it('#1203 still accepts a hide-only row and a priced row', function () {
    $menuProduct = MenuProduct::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
    ]);

    $service = app(ShopMenuToppingOverrideService::class);

    expect($service->sync($menuProduct, $this->group, [[
        'topping_group_item_id' => (string) $this->item->id,
        'product_sku_id' => (string) $this->toppingSku->id,
        'is_hidden' => true,
        'override_price' => null,
    ]]))->toHaveCount(1);

    expect($service->sync($menuProduct, $this->group, [[
        'topping_group_item_id' => (string) $this->item->id,
        'product_sku_id' => (string) $this->toppingSku->id,
        'is_hidden' => false,
        'override_price' => 400,
    ]]))->toHaveCount(1);
});
