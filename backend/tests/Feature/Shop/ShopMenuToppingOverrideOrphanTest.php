<?php

declare(strict_types=1);

/**
 * Regression guard for the "Topping group item … does not belong to this
 * topping group" 422 that blocked every shop-menu topping edit once an
 * override was left dangling behind a soft-deleted item.
 *
 *   1. Soft-deleting a ToppingGroupItem must purge its override rows (so no
 *      orphan is ever created).
 *   2. sync() must silently drop an override row whose item is already
 *      soft-deleted, rather than reject the whole request — sibling edits keep
 *      working even if an orphan sneaks in some other way.
 */

use App\Models\MenuProduct;
use App\Models\MenuProductToppingItemOverride;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Services\Topping\ShopMenuToppingOverrideService;

beforeEach(function () {
    $this->service = new ShopMenuToppingOverrideService;
    $this->menuProduct = MenuProduct::factory()->create();
    $this->group = ToppingGroup::factory()->create();
    $this->liveItem = ToppingGroupItem::factory()->create(['topping_group_id' => $this->group->id]);
    $this->liveSku = ProductSku::factory()->create();
});

it('purges override rows when its topping item is soft-deleted', function () {
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $this->menuProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->liveItem->id,
        'product_sku_id' => null,
        'is_hidden' => false,
        'override_price' => 5000,
    ]);

    expect(MenuProductToppingItemOverride::where('topping_group_item_id', $this->liveItem->id)->count())->toBe(1);

    $this->liveItem->delete();

    expect(MenuProductToppingItemOverride::where('topping_group_item_id', $this->liveItem->id)->count())->toBe(0);
});

it('drops an orphan override row instead of rejecting the whole sync', function () {
    // An item that gets soft-deleted AFTER an override was recorded for it.
    $orphanItem = ToppingGroupItem::factory()->create(['topping_group_id' => $this->group->id]);
    $orphanItem->delete(); // deleting-hook already ran, but simulate the pre-existing orphan below

    // Re-create the orphan override directly (bypassing the hook) to model
    // legacy dangling rows that predate the cleanup hook.
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $this->menuProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $orphanItem->id,
        'product_sku_id' => null,
        'is_hidden' => false,
        'override_price' => 999,
    ]);

    // Client passes through the orphan row PLUS a legitimate edit on the live item.
    $result = $this->service->sync($this->menuProduct, $this->group, [
        ['topping_group_item_id' => $orphanItem->id, 'product_sku_id' => null, 'is_hidden' => false, 'override_price' => 999],
        ['topping_group_item_id' => $this->liveItem->id, 'product_sku_id' => null, 'is_hidden' => true, 'override_price' => null],
    ]);

    // No exception; only the live item's override survives.
    expect($result)->toHaveCount(1)
        ->and($result->first()->topping_group_item_id)->toBe($this->liveItem->id)
        ->and($result->first()->is_hidden)->toBeTrue();
});

it('still rejects a genuinely foreign item id', function () {
    $otherGroup = ToppingGroup::factory()->create();
    $foreignItem = ToppingGroupItem::factory()->create(['topping_group_id' => $otherGroup->id]);

    expect(fn () => $this->service->sync($this->menuProduct, $this->group, [
        ['topping_group_item_id' => $foreignItem->id, 'product_sku_id' => null, 'is_hidden' => true, 'override_price' => null],
    ]))->toThrow(InvalidArgumentException::class);
});
