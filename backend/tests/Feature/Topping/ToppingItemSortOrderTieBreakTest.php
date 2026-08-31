<?php

declare(strict_types=1);

/**
 * #2046 — the customer menu showed topping items in a different order than the
 * admin arranged.
 *
 * The read paths all had `orderBy('sort_order')` and were, on their own,
 * correct. The defect was in the DATA: `sort_order` carries no uniqueness
 * constraint and the add-item path defaulted an omitted value to 0, so a new
 * item silently landed on top of whatever already sat at position 0. On a tie
 * MySQL is free to return rows in physical order — measured on dev data, a
 * relation without an explicit tie-break returned 5,0,1,2,3,4 — so the order
 * drifted with no one touching the data.
 *
 * Two guards, because either alone leaves a hole: the read paths tie-break on
 * the unique UUIDv7 `id` (deterministic even while ties exist), and the write
 * path appends instead of colliding (so new ties stop being created).
 */

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\Zone;
use App\Services\Topping\ProductToppingGroupService;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
    $this->zone = Zone::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $this->table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'topping-sort-order',
        'is_active' => true,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Pho']);
    $menu->menuSections()->attach($section);

    $this->dish = Product::factory()->active()->create(['organization_id' => $this->orgId]);
    $dishSku = ProductSku::factory()->create([
        'product_id' => $this->dish->id,
        'selling_price' => 10000,
        'is_active' => true,
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $this->dish->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $dishSku->id,
        'selling_price' => 10000,
        'is_active' => true,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->dish->brand_id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);
    $this->dish->toppingGroups()->attach($this->group->id, ['sort_order' => 0]);

    $this->menuUrl = '/api/v1/customer/tables/topping-sort-order/menu';

    // Add a topping item at an explicit `sort_order`, with the price row the
    // customer menu needs to render it as a simple (non-variant) line.
    $this->addTopping = function (string $name, int $sortOrder): ToppingGroupItem {
        $product = Product::factory()->active()->create([
            'organization_id' => $this->orgId,
            'name' => $name,
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);

        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $product->id,
            'sort_order' => $sortOrder,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $sku->id,
            'extra_price' => 0,
        ]);

        return $item;
    };
});

it('orders tied topping items deterministically on the customer menu', function () {
    // Three items, TWO of them tied at sort_order 0 — the exact shape found in
    // production data (a group with 6 items but only 5 distinct sort_order).
    $tiedA = ($this->addTopping)('Tied A', 0);
    $tiedB = ($this->addTopping)('Tied B', 0);
    ($this->addTopping)('Last', 1);

    // The tie must resolve by the unique UUIDv7 id, so the expected order is
    // computable rather than whatever the storage engine happens to return.
    $expectedTieOrder = collect([$tiedA, $tiedB])
        ->sortBy('id')
        ->pluck('product.name')
        ->all();

    $names = collect(
        $this->getJson($this->menuUrl)
            ->assertOk()
            ->json('data.categories.0.items.0.toppingGroups.0.items')
    )->pluck('name')->all();

    expect($names)->toBe([...$expectedTieOrder, 'Last']);

    // Stable across repeated reads — a tie that resolved by physical row order
    // could agree once by luck and drift later.
    $again = collect(
        $this->getJson($this->menuUrl)
            ->assertOk()
            ->json('data.categories.0.items.0.toppingGroups.0.items')
    )->pluck('name')->all();

    expect($again)->toBe($names);
});

it('appends a new topping item instead of colliding at sort_order 0', function () {
    ($this->addTopping)('First', 0);
    ($this->addTopping)('Second', 1);

    $newProduct = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->group->brand_id,
    ]);

    // No `sort_order` supplied — the request rule allows omitting it, which is
    // exactly how the ties were created.
    $item = app(ProductToppingGroupService::class)
        ->addGroupItem($this->group, ['product_id' => $newProduct->id]);

    expect((int) $item->sort_order)->toBe(2);

    // And the group is still tie-free.
    $sortOrders = ToppingGroupItem::where('topping_group_id', $this->group->id)
        ->pluck('sort_order')
        ->map(fn ($value) => (int) $value)
        ->all();

    expect($sortOrders)->toHaveCount(count(array_unique($sortOrders)));
});

it('still honours an explicitly supplied sort_order', function () {
    // syncItems() sets positions deliberately; the append default must not
    // override an explicit value.
    ($this->addTopping)('First', 0);

    $newProduct = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->group->brand_id,
    ]);

    $item = app(ProductToppingGroupService::class)
        ->addGroupItem($this->group, ['product_id' => $newProduct->id, 'sort_order' => 7]);

    expect((int) $item->sort_order)->toBe(7);
});

it('backfills tied sort_order without changing the visible order', function () {
    $tiedA = ($this->addTopping)('Tied A', 0);
    $tiedB = ($this->addTopping)('Tied B', 0);
    ($this->addTopping)('Last', 1);

    $before = collect(
        $this->getJson($this->menuUrl)->assertOk()
            ->json('data.categories.0.items.0.toppingGroups.0.items')
    )->pluck('name')->all();

    $service = app(ProductToppingGroupService::class);

    // Dry run reports the work but writes nothing.
    expect($service->backfillToppingItemSortOrder(false))->not->toBeEmpty();
    expect((int) $tiedA->fresh()->sort_order)->toBe(0);
    expect((int) $tiedB->fresh()->sort_order)->toBe(0);

    $service->backfillToppingItemSortOrder(true);

    // Ties are gone: 0..n-1, all distinct.
    $sortOrders = ToppingGroupItem::where('topping_group_id', $this->group->id)
        ->orderBy('sort_order')
        ->pluck('sort_order')
        ->map(fn ($value) => (int) $value)
        ->all();

    expect($sortOrders)->toBe([0, 1, 2]);

    // The customer-visible order is UNCHANGED — the backfill compacts the stored
    // numbers, it does not reshuffle a group that already displayed correctly.
    $after = collect(
        $this->getJson($this->menuUrl)->assertOk()
            ->json('data.categories.0.items.0.toppingGroups.0.items')
    )->pluck('name')->all();

    expect($after)->toBe($before);

    // Idempotent — a second pass finds nothing.
    expect($service->backfillToppingItemSortOrder(false))->toBe([]);
});
