<?php

declare(strict_types=1);

/**
 * #1275 — the customer menu decided "does this topping have variants?" from the
 * topping PRODUCT'S option axis, not from the price rows that actually make a
 * variant orderable.
 *
 * `options` is eager-loaded filtered to is_active = true (getMenuForBranch), so
 * deactivating the option axis on a topping whose per-SKU `topping_group_item_
 * skus` rows still exist collapsed the topping to ONE line: every variant but
 * one vanished from the picker AND from the order gate, silently. Five topping
 * items in the dev data were in exactly that state.
 *
 * The fix asks the data instead — price rows pointing at a live ProductSku —
 * and is deliberately ADDITIVE, so the second test here matters as much as the
 * first: a single-SKU simple topping must keep the flat `sku_id` + `price`
 * shape and must NOT grow a one-entry variant picker (129 items in dev).
 */

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\Zone;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
    $this->zone = Zone::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $this->table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'topping-variant-signal',
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

    // The dish the toppings hang off.
    $this->dish = Product::factory()->active()->create(['organization_id' => $this->orgId]);
    $dishSku = ProductSku::factory()->create([
        'product_id' => $this->dish->id,
        'selling_price' => 10000,
        'is_active' => true,
    ]);
    $this->menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $this->dish->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $this->menuProduct->id,
        'product_sku_id' => $dishSku->id,
        'selling_price' => 10000,
        'is_active' => true,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);
    $this->dish->toppingGroups()->attach($this->group->id, ['sort_order' => 0]);

    $this->menuUrl = '/api/v1/customer/tables/topping-variant-signal/menu';
});

it('shows both variants of a topping whose option axis was deactivated', function () {
    $toppingProduct = Product::factory()->active()->create(['organization_id' => $this->orgId]);

    // The option axis exists but is TURNED OFF — this is the trap: the SKUs and
    // their price rows are untouched and still orderable, but the eager-load
    // filters the axis away so `$toppingProduct->options` reads as empty.
    $option = ProductOption::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => false,
        'position' => 0,
    ]);
    $small = ProductOptionValue::factory()->create(['option_id' => $option->id, 'label' => 'S', 'position' => 0, 'is_active' => true]);
    $large = ProductOptionValue::factory()->create(['option_id' => $option->id, 'label' => 'L', 'position' => 1, 'is_active' => true]);

    $smallSku = ProductSku::factory()->create(['product_id' => $toppingProduct->id, 'option_value1_id' => $small->id, 'is_active' => true]);
    $largeSku = ProductSku::factory()->create(['product_id' => $toppingProduct->id, 'option_value1_id' => $large->id, 'is_active' => true]);

    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $toppingProduct->id,
        'sort_order' => 0,
    ]);
    // Two REAL per-SKU price rows — two orderable variants at two prices.
    ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $item->id, 'product_sku_id' => $smallSku->id, 'extra_price' => 120]);
    ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $item->id, 'product_sku_id' => $largeSku->id, 'extra_price' => 150]);

    $toppingItem = $this->getJson($this->menuUrl)
        ->assertOk()
        ->json('data.categories.0.items.0.toppingGroups.0.items.0');

    expect($toppingItem)->toHaveKey('variants');

    $variants = collect($toppingItem['variants']);
    expect($variants)->toHaveCount(2)
        // Both prices reachable — before the fix only one line at one price
        // survived and the other variant could not be ordered at all.
        ->and($variants->pluck('price')->sort()->values()->all())->toEqual([120.0, 150.0])
        ->and($variants->pluck('sku_id')->sort()->values()->all())
        ->toBe(collect([$smallSku->id, $largeSku->id])->sort()->values()->all());
});

it('keeps the flat shape for a single-SKU simple topping (no one-entry picker)', function () {
    $toppingProduct = Product::factory()->active()->create(['organization_id' => $this->orgId]);
    $onlySku = ProductSku::factory()->create(['product_id' => $toppingProduct->id, 'is_active' => true]);

    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $toppingProduct->id,
        'sort_order' => 0,
    ]);
    // The wildcard row the simple-topping path seeds: price, no SKU identity.
    ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $item->id, 'product_sku_id' => null, 'extra_price' => 90]);

    $toppingItem = $this->getJson($this->menuUrl)
        ->assertOk()
        ->json('data.categories.0.items.0.toppingGroups.0.items.0');

    // A wildcard row is a price, not a variant — it must never turn the topping
    // into a picker, or customer-web would ask the guest to choose from one.
    expect($toppingItem)->not->toHaveKey('variants')
        ->and($toppingItem['sku_id'])->toBe($onlySku->id)
        ->and($toppingItem['price'])->toEqual(90.0);
});

it('keeps the flat shape when only ONE per-SKU price row exists', function () {
    // One scoped row is still one orderable thing: additive fix must not flip
    // this to the variant shape either.
    $toppingProduct = Product::factory()->active()->create(['organization_id' => $this->orgId]);
    $onlySku = ProductSku::factory()->create(['product_id' => $toppingProduct->id, 'is_active' => true]);

    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $toppingProduct->id,
        'sort_order' => 0,
    ]);
    ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $item->id, 'product_sku_id' => $onlySku->id, 'extra_price' => 110]);

    $toppingItem = $this->getJson($this->menuUrl)
        ->assertOk()
        ->json('data.categories.0.items.0.toppingGroups.0.items.0');

    expect($toppingItem)->not->toHaveKey('variants')
        ->and($toppingItem['sku_id'])->toBe($onlySku->id)
        ->and($toppingItem['price'])->toEqual(110.0);
});
