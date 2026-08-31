<?php

declare(strict_types=1);

/**
 * Regression guard for the admin Shop→Menu topping screen bug: a saved shop
 * override collapsed `extra_price` to the effective (overridden) value, so on
 * reload the "default price" column mirrored the override and the HQ base was
 * lost. The resource must expose the untouched HQ base as `base_extra_price`
 * ALONGSIDE the effective `extra_price`.
 */

use App\Http\Resources\MenuToppingGroupItemSkuResource;
use App\Models\ProductSku;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Http\Request;

it('serializes base_extra_price as the HQ base, distinct from an effective override', function () {
    $item = ToppingGroupItem::factory()->create();
    $sku = ProductSku::factory()->create();

    /** @var ToppingGroupItemSku $row */
    $row = ToppingGroupItemSku::factory()->withSku($sku)->create([
        'topping_group_item_id' => $item->id,
        'extra_price' => 1000,
    ]);

    // Simulate what MenuToppingGroupItemResource stamps when an override exists.
    $row->effective_extra_price = 3000;

    $data = (new MenuToppingGroupItemSkuResource($row))->toArray(Request::create('/'));

    expect($data['extra_price'])->toBe('3000')             // effective (overridden)
        ->and($data['base_extra_price'])->toBe('1000.00'); // untouched HQ base (decimal:2 cast)
});

it('base_extra_price equals extra_price when no override is stamped', function () {
    $item = ToppingGroupItem::factory()->create();
    $sku = ProductSku::factory()->create();

    /** @var ToppingGroupItemSku $row */
    $row = ToppingGroupItemSku::factory()->withSku($sku)->create([
        'topping_group_item_id' => $item->id,
        'extra_price' => 1500,
    ]);

    $data = (new MenuToppingGroupItemSkuResource($row))->toArray(Request::create('/'));

    expect($data['extra_price'])->toBe('1500.00')
        ->and($data['base_extra_price'])->toBe('1500.00');
});
