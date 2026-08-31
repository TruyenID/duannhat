<?php

declare(strict_types=1);

/**
 * #1275 — a SKU-less ("wildcard") `topping_group_item_skus` row that can never
 * be read used to be accepted silently.
 *
 * ToppingPricingService tier 3 sorts `product_sku_id IS NULL` LAST, so a scoped
 * row always wins for its own SKU. Once every active SKU of a topping carries a
 * scoped row, an added wildcard row prices NOTHING — but admin still counts it
 * as a "variant". That is the reported "admin shows 2 variants, customer shows
 * 1": the operator typed a price, admin displayed it, the customer menu and the
 * order gate both correctly ignored it, and nothing said so. Eleven rows in the
 * dev data were in that state.
 *
 * The guard must stay narrow: a wildcard row is the NORMAL way to price a
 * topping whose SKUs are not individually scoped (51 legitimate rows in dev),
 * so only the "no SKU left for it to apply to" case may be refused.
 */

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Topping\ProductToppingGroupService;

/**
 * Two ACTIVE SKUs on one product, distinct option values so their
 * option_signature differs (product_skus is UNIQUE on product_id +
 * option_signature).
 *
 * @return array{0: ProductSku, 1: ProductSku}
 */
function variantSkuPair(Product $product): array
{
    $option = ProductOption::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'position' => 0,
    ]);
    $first = ProductOptionValue::factory()->create(['option_id' => $option->id, 'position' => 0, 'is_active' => true]);
    $second = ProductOptionValue::factory()->create(['option_id' => $option->id, 'position' => 1, 'is_active' => true]);

    return [
        ProductSku::factory()->create(['product_id' => $product->id, 'option_value1_id' => $first->id, 'is_active' => true]),
        ProductSku::factory()->create(['product_id' => $product->id, 'option_value1_id' => $second->id, 'is_active' => true]),
    ];
}

beforeEach(function () {
    $this->service = app(ProductToppingGroupService::class);
    $this->group = ToppingGroup::factory()->create(['modifier_type' => 'add']);
    $this->toppingProduct = Product::factory()->create();
    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $this->toppingProduct->id,
    ]);
});

it('refuses a SKU-less price row when every active SKU is already scoped', function () {
    $onlySku = ProductSku::factory()->create([
        'product_id' => $this->toppingProduct->id,
        'is_active' => true,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $onlySku->id,
        'extra_price' => 120,
    ]);

    // Exactly the reported action: "add another price" on a single-SKU topping.
    expect(fn () => $this->service->createItemSku($this->item, [
        'product_sku_id' => null,
        'extra_price' => 150,
    ]))->toThrow(InvalidArgumentException::class);

    // Nothing written — the operator gets an error instead of a dead row.
    expect(ToppingGroupItemSku::where('topping_group_item_id', $this->item->id)->count())->toBe(1);
});

it('still allows a SKU-less price row when a SKU has no scoped row', function () {
    // Two active SKUs, only one scoped → the wildcard is the price for the
    // other one. This is the shape 51 rows in dev rely on; refusing it would
    // break ordinary pricing.
    //
    // Two SKUs need distinct option values: product_skus is UNIQUE on
    // (product_id, option_signature), so an option-less product can only ever
    // hold ONE sku — which is also why the reported topping (1 sku, 0 options)
    // could never gain a second variant.
    [$scoped, $other] = variantSkuPair($this->toppingProduct);

    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $scoped->id,
        'extra_price' => 120,
    ]);

    $row = $this->service->createItemSku($this->item, [
        'product_sku_id' => null,
        'extra_price' => 150,
    ]);

    expect($row->product_sku_id)->toBeNull()
        ->and((float) $row->extra_price)->toEqual(150.0);
});

it('still allows the first SKU-less price row on a topping with no scoped rows', function () {
    // The plain simple-topping path: one wildcard row carries the price.
    ProductSku::factory()->create(['product_id' => $this->toppingProduct->id, 'is_active' => true]);

    $row = $this->service->createItemSku($this->item, [
        'product_sku_id' => null,
        'extra_price' => 90,
    ]);

    expect($row->product_sku_id)->toBeNull();
});

it('ignores INACTIVE SKUs when deciding whether the wildcard is dead', function () {
    // An inactive SKU is not orderable, so it cannot be the thing that keeps a
    // wildcard row alive — with the only ACTIVE sku already scoped, the row is
    // still dead and must be refused.
    [$activeSku, $deadSku] = variantSkuPair($this->toppingProduct);
    $deadSku->update(['is_active' => false]);

    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $activeSku->id,
        'extra_price' => 120,
    ]);

    expect(fn () => $this->service->createItemSku($this->item, [
        'product_sku_id' => null,
        'extra_price' => 150,
    ]))->toThrow(InvalidArgumentException::class);
});

it('keeps refusing a SECOND wildcard row (pre-existing guard)', function () {
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => null,
        'extra_price' => 90,
    ]);

    expect(fn () => $this->service->createItemSku($this->item, [
        'product_sku_id' => null,
        'extra_price' => 150,
    ]))->toThrow(InvalidArgumentException::class, 'A price override without a SKU already exists for this item.');
});
