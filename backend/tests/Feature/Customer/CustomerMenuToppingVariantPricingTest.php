<?php

/**
 * Customer takeaway menu — topping/combo VARIANT pricing must match the order
 * engine and must never surface an inactive variant.
 *
 * Reproduces the shop-reported bug (Combo nem cuốn): the takeaway menu showed
 * ¥450 — the price of an INACTIVE ProductSku (PHUPE002TO) — while the active
 * variant was ¥300, and an HQ per-product override was ignored entirely.
 *
 * Root cause: CustomerMenuService::transformToppingGroups read raw
 * topping_group_item_skus.extra_price and iterated every SKU without checking
 * ProductSku.is_active — a separate, simpler code path from the order-time
 * resolver (ToppingPricingService::resolveSnapshotPrice).
 *
 * These tests pin the two invariants:
 *   1. Inactive variants are dropped from the menu (and never become default).
 *   2. The displayed variant price flows through resolveSnapshotPrice, so an
 *      HQ ProductToppingGroupItemOverride is honoured.
 */

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuProductToppingItemOverride;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;

const ORG_ID = '00000000-0000-0000-0000-000000000001';

beforeEach(function () {
    $this->branch = Branch::factory()->create([
        'console_organization_id' => ORG_ID,
    ]);
});

/**
 * Build an active takeaway menu with one parent product ("Combo") in a section,
 * and attach a variant topping group to it. The topping product carries a
 * `size` option so transformToppingGroups enters the variant branch. Returns
 * [parentProduct, toppingGroup, toppingGroupItem, [variantLabel => sku]].
 */
function makeVariantComboMenu(Branch $branch, array $variants): array
{
    $menu = Menu::factory()->create([
        'organization_id' => ORG_ID,
        'branch_id' => $branch->id,
        'brand_id' => $branch->console_brand_id ?? $branch->id,
        'status' => 'Active',
        'service_type' => 'Takeaway',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Combo']);
    $menu->menuSections()->attach($section);

    $parent = Product::factory()->active()->create([
        'name' => 'Combo nem cuon',
        'organization_id' => ORG_ID,
    ]);
    $parentSku = ProductSku::factory()->create([
        'product_id' => $parent->id,
        'selling_price' => 1000,
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $parent->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $parentSku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    // Topping product WITH a size option → variant branch.
    $toppingProduct = Product::factory()->active()->create([
        'name' => 'Nem cuon',
        'organization_id' => ORG_ID,
    ]);
    $option = ProductOption::factory()->create([
        'product_id' => $toppingProduct->id,
        'key' => 'size',
        'name' => 'Size',
        'position' => 0,
    ]);

    $group = ToppingGroup::factory()->create([
        'organization_id' => ORG_ID,
        'min_select' => 0,
        'max_select' => 1,
        'is_active' => true,
    ]);
    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
    ]);

    $skus = [];
    foreach ($variants as $index => $variant) {
        $optionValue = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => $variant['label'],
            'label' => $variant['label'],
            'position' => $index,
        ]);
        $productSku = ProductSku::factory()->create([
            'product_id' => $toppingProduct->id,
            'option_value1_id' => $optionValue->id,
            'name' => $variant['label'],
            'is_active' => $variant['is_active'],
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $productSku->id,
            'extra_price' => $variant['extra_price'],
        ]);
        $skus[$variant['label']] = $productSku;
    }

    $parent->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    return [$parent, $group, $item, $skus];
}

function resolveComboToppingVariants(Branch $branch): array
{
    $item = collect(
        test()->getJson("/api/v1/customer/branches/{$branch->slug}/menu")
            ->assertOk()
            ->json('data.categories')
    )
        ->flatMap(fn ($c) => $c['items'])
        ->firstWhere('name', 'Combo nem cuon');

    expect($item)->not->toBeNull();

    return $item['toppingGroups'][0]['items'][0]['variants'];
}

// =========================================================================
//  Bug 1 — inactive variants are never shown, never default
// =========================================================================

it('drops the inactive variant and defaults to the active one', function () {
    // Inactive PHUPE002TO @450 is listed first (sort position 0) — the old
    // code would have shown it AND marked it default. Active variant @300 is
    // the only orderable one.
    [$parent, $group, $item, $skus] = makeVariantComboMenu($this->branch, [
        ['label' => 'PHUPE002TO', 'extra_price' => 450, 'is_active' => false],
        ['label' => 'Combo nem cuon', 'extra_price' => 300, 'is_active' => true],
    ]);

    $variants = resolveComboToppingVariants($this->branch);

    // Only the active variant survives.
    expect($variants)->toHaveCount(1)
        ->and($variants[0]['sku_id'])->toBe($skus['Combo nem cuon']->id)
        ->and($variants[0]['name'])->toBe('Combo nem cuon')
        ->and((float) $variants[0]['price'])->toBe(300.0)
        ->and($variants[0]['default'])->toBeTrue();
});

it('drops the topping item entirely when every variant is inactive', function () {
    makeVariantComboMenu($this->branch, [
        ['label' => 'PHUPE002TO', 'extra_price' => 450, 'is_active' => false],
    ]);

    $item = collect(
        $this->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
            ->assertOk()
            ->json('data.categories')
    )
        ->flatMap(fn ($c) => $c['items'])
        ->firstWhere('name', 'Combo nem cuon');

    // Group has no orderable items → items array is empty (topping picker
    // must not render an empty/unorderable variant).
    expect($item['toppingGroups'][0]['items'])->toBe([]);
});

// =========================================================================
//  Bug 2 — displayed price flows through the order-engine resolver
// =========================================================================

it('honours an HQ per-product override for the displayed variant price', function () {
    // Active variant base extra_price = 300, but an HQ per-product override
    // sets it to 250 for this parent product. The order engine would charge
    // 250 — the menu must display 250, not the raw 300.
    [$parent, $group, $item, $skus] = makeVariantComboMenu($this->branch, [
        ['label' => 'Combo nem cuon', 'extra_price' => 300, 'is_active' => true],
    ]);

    ProductToppingGroupItemOverride::create([
        'product_id' => $parent->id,
        'topping_group_id' => $group->id,
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $skus['Combo nem cuon']->id,
        'is_hidden' => false,
        'override_price' => 250,
    ]);

    $variants = resolveComboToppingVariants($this->branch);

    expect($variants)->toHaveCount(1)
        ->and((float) $variants[0]['price'])->toBe(250.0);
});

it('falls back to the base extra_price when no override exists', function () {
    [$parent, $group, $item, $skus] = makeVariantComboMenu($this->branch, [
        ['label' => 'Combo nem cuon', 'extra_price' => 300, 'is_active' => true],
    ]);

    $variants = resolveComboToppingVariants($this->branch);

    expect((float) $variants[0]['price'])->toBe(300.0);
});

// =========================================================================
//  Bug 4 — SHOP override (tier 1) now flows to the customer display too,
//  matching the workstation resolver. Previously only the admin display
//  honoured it while customer + order priced the base.
// =========================================================================

it('honours a SHOP override (tier 1) for the displayed variant price', function () {
    // Base 300; shop sets an override of 500 on this menu line. The order
    // engine + workstation charge 500 → the customer menu must display 500.
    [$parent, $group, $item, $skus] = makeVariantComboMenu($this->branch, [
        ['label' => 'Combo nem cuon', 'extra_price' => 300, 'is_active' => true],
    ]);

    $menuProduct = MenuProduct::where('product_id', $parent->id)->firstOrFail();
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $menuProduct->id,
        'topping_group_id' => $group->id,
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $skus['Combo nem cuon']->id,
        'is_hidden' => false,
        'override_price' => 500,
    ]);

    $variants = resolveComboToppingVariants($this->branch);

    expect((float) $variants[0]['price'])->toBe(500.0);
});

it('shop override (tier 1) wins over an HQ override (tier 2)', function () {
    // HQ says 250, shop says 500. Tier-1 shop wins — matching the workstation
    // resolver (local_pos_menus.go tier-1 beats tier-2).
    [$parent, $group, $item, $skus] = makeVariantComboMenu($this->branch, [
        ['label' => 'Combo nem cuon', 'extra_price' => 300, 'is_active' => true],
    ]);

    ProductToppingGroupItemOverride::create([
        'product_id' => $parent->id,
        'topping_group_id' => $group->id,
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $skus['Combo nem cuon']->id,
        'is_hidden' => false,
        'override_price' => 250,
    ]);

    $menuProduct = MenuProduct::where('product_id', $parent->id)->firstOrFail();
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $menuProduct->id,
        'topping_group_id' => $group->id,
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $skus['Combo nem cuon']->id,
        'is_hidden' => false,
        'override_price' => 500,
    ]);

    $variants = resolveComboToppingVariants($this->branch);

    expect((float) $variants[0]['price'])->toBe(500.0);
});
