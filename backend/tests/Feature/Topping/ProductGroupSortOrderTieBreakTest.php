<?php

declare(strict_types=1);

/**
 * #2109 — the customer menu showed topping GROUPS in a different order than the
 * admin arranged on the product screen.
 *
 * The pivot-level twin of #2046 (which fixed the order of ITEMS inside one
 * group). `product_topping_groups.sort_order` has no uniqueness constraint, and
 * `syncGroups()` defaulted an omitted entry to 0 — so a client that sent no
 * order map put EVERY group at position 0 (157 such rows in dev, 37 of 92
 * multi-group products tied). On a tie MySQL is free to return rows in physical
 * order, so the order drifted with no one touching the data.
 *
 * Two guards, because either alone leaves a hole: the read paths tie-break on
 * the pivot's auto-increment `id`, and the write path falls back to the array
 * index (the intended order) instead of 0.
 */

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
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
        'qr_token' => 'group-sort-order',
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

    $this->menuUrl = '/api/v1/customer/tables/group-sort-order/menu';

    // A topping group carrying one orderable item, attached to the dish at an
    // explicit pivot sort_order.
    $this->attachGroup = function (string $name, int $sortOrder): ToppingGroup {
        $group = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->dish->brand_id,
            'name' => $name,
            'is_active' => true,
            'min_select' => 0,
            'max_select' => null,
            'modifier_type' => 'add',
            'selection_type' => 'multiple',
            'price_strategy' => 'flat',
        ]);

        $toppingProduct = Product::factory()->active()->create(['organization_id' => $this->orgId]);
        $sku = ProductSku::factory()->create(['product_id' => $toppingProduct->id, 'is_active' => true]);
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => $toppingProduct->id,
            'sort_order' => 0,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $sku->id,
            'extra_price' => 0,
        ]);

        $this->dish->toppingGroups()->attach($group->id, ['sort_order' => $sortOrder]);

        return $group;
    };
});

it('orders tied topping groups deterministically on the customer menu', function () {
    // Two groups tied at 0 — the exact shape found in production data.
    $tiedA = ($this->attachGroup)('Tied A', 0);
    $tiedB = ($this->attachGroup)('Tied B', 0);
    ($this->attachGroup)('Last', 1);

    // The tie resolves by the pivot's auto-increment id, so the expected order
    // is computable rather than whatever the storage engine happens to return.
    $expectedTieOrder = ProductToppingGroup::where('product_id', $this->dish->id)
        ->whereIn('topping_group_id', [$tiedA->id, $tiedB->id])
        ->orderBy('id')
        ->pluck('topping_group_id')
        ->map(fn ($id) => ToppingGroup::find($id)->name)
        ->all();

    $names = collect(
        $this->getJson($this->menuUrl)
            ->assertOk()
            ->json('data.categories.0.items.0.toppingGroups')
    )->pluck('name')->all();

    expect($names)->toBe([...$expectedTieOrder, 'Last']);

    // Stable across repeated reads — a tie resolved by physical row order could
    // agree once by luck and drift later.
    $again = collect(
        $this->getJson($this->menuUrl)
            ->assertOk()
            ->json('data.categories.0.items.0.toppingGroups')
    )->pluck('name')->all();

    expect($again)->toBe($names);
});

it('falls back to the array order, not 0, when syncForProduct gets no sort map', function () {
    $first = ($this->attachGroup)('First', 0);
    $second = ($this->attachGroup)('Second', 1);
    $third = ($this->attachGroup)('Third', 2);

    // No sort map supplied — this is exactly how the ties were created. The
    // array order IS the intended order.
    app(ProductToppingGroupService::class)->syncForProduct(
        $this->dish,
        [$third->id, $first->id, $second->id],
    );

    $rows = ProductToppingGroup::where('product_id', $this->dish->id)
        ->orderBy('sort_order')
        ->pluck('topping_group_id')
        ->all();

    expect($rows)->toBe([$third->id, $first->id, $second->id]);

    // And no ties were created.
    $sortOrders = ProductToppingGroup::where('product_id', $this->dish->id)
        ->pluck('sort_order')
        ->map(fn ($v) => (int) $v)
        ->all();

    expect($sortOrders)->toBe([0, 1, 2]);
});

it('backfills tied group sort_order without changing the visible order', function () {
    ($this->attachGroup)('Tied A', 0);
    ($this->attachGroup)('Tied B', 0);
    ($this->attachGroup)('Last', 1);

    $before = collect(
        $this->getJson($this->menuUrl)->assertOk()
            ->json('data.categories.0.items.0.toppingGroups')
    )->pluck('name')->all();

    $service = app(ProductToppingGroupService::class);

    // Dry run reports the work but writes nothing.
    expect($service->backfillProductGroupSortOrder(false))->not->toBeEmpty();
    expect(
        ProductToppingGroup::where('product_id', $this->dish->id)->where('sort_order', 0)->count()
    )->toBe(2);

    $service->backfillProductGroupSortOrder(true);

    $sortOrders = ProductToppingGroup::where('product_id', $this->dish->id)
        ->orderBy('sort_order')
        ->pluck('sort_order')
        ->map(fn ($v) => (int) $v)
        ->all();

    expect($sortOrders)->toBe([0, 1, 2]);

    // The customer-visible order is UNCHANGED — the backfill compacts the stored
    // numbers, it does not reshuffle a product that already displayed correctly.
    $after = collect(
        $this->getJson($this->menuUrl)->assertOk()
            ->json('data.categories.0.items.0.toppingGroups')
    )->pluck('name')->all();

    expect($after)->toBe($before);

    // Idempotent — a second pass finds nothing.
    expect($service->backfillProductGroupSortOrder(false))->toBe([]);
});
