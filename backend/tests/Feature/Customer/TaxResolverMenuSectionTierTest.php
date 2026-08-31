<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\TaxResolver;

/**
 * #1218 — two new tiers between the menu-item override and the product:
 *
 *   1. MenuProduct.tax_type_id
 *   2. MenuMenuSection.tax_type_id   ← this section IN THIS MENU
 *   3. Menu.tax_type_id              ← the whole menu
 *   4. Product.tax_type_id
 *   5. branch default
 *   6. brand default
 *
 * They sit ABOVE the product by ruling: "the menu always wins; setting an item
 * wrongly is a human error, and with no menu value we fall back to the product."
 * So there is deliberately NO exemption for a 非課税 product — the test below
 * pins that, because it is the surprising half of the decision and the one a
 * future reader is most likely to try to "fix".
 */
beforeEach(function () {
    $orgId = '00000000-0000-0000-0000-000000000001';

    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $productType = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);

    $this->std = TaxType::factory()->standard()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);
    $this->red = TaxType::factory()->reduced()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);
    $this->exempt = TaxType::factory()->exempt()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);

    $this->product = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
        'tax_type_id' => $this->std->id,
    ])->load('taxType');

    $this->makeMenu = fn (?string $taxTypeId = null) => Menu::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'tax_type_id' => $taxTypeId,
    ]);

    $this->section = MenuSection::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->resolve = fn (Menu $menu, ?MenuSection $section = null, ?TaxType $itemOverride = null) => (new TaxResolver)
        ->resolveForLine(
            $this->product,
            $itemOverride,
            $this->branch->id,
            $this->brand->id,
            $menu->id,
            $section?->id,
        );
});

it('lets the whole-menu tax type beat the product', function () {
    // The point of the feature: a 持ち帰り menu is 8% without touching any item.
    $menu = ($this->makeMenu)($this->red->id);

    expect(($this->resolve)($menu)->rate)->toBe(8.0);
});

it('lets the section beat the menu', function () {
    $menu = ($this->makeMenu)($this->red->id);
    $menu->menuSections()->attach($this->section->id, [
        'tax_type_id' => $this->std->id,
        'display_order' => 0,
    ]);

    expect(($this->resolve)($menu, $this->section)->rate)->toBe(10.0);
});

it('lets the menu-item override beat both', function () {
    $menu = ($this->makeMenu)($this->std->id);
    $menu->menuSections()->attach($this->section->id, [
        'tax_type_id' => $this->std->id,
        'display_order' => 0,
    ]);

    expect(($this->resolve)($menu, $this->section, $this->red)->rate)->toBe(8.0);
});

it('falls back to the product when neither menu nor section sets one', function () {
    $menu = ($this->makeMenu)();
    $menu->menuSections()->attach($this->section->id, ['display_order' => 0]);

    expect(($this->resolve)($menu, $this->section)->rate)->toBe(10.0); // the product's own
});

it('overrides a tax-exempt product — by ruling, not by oversight', function () {
    // The accepted cost of "the menu always wins". A 非課税 product sold from an
    // 8% menu IS taxed at 8%. The escape hatch is tier 1: give that menu line
    // its own override, which still wins (asserted right after).
    $exemptProduct = Product::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->product->product_type_id,
        'tax_type_id' => $this->exempt->id,
    ])->load('taxType');

    $menu = ($this->makeMenu)($this->red->id);
    $resolver = new TaxResolver;

    expect($resolver->resolveForLine($exemptProduct, null, $this->branch->id, $this->brand->id, $menu->id, null)->rate)
        ->toBe(8.0);

    // …and the documented way back to 0%.
    expect($resolver->resolveForLine($exemptProduct, $this->exempt, $this->branch->id, $this->brand->id, $menu->id, null)->rate)
        ->toBe(0.0);
});

it('keeps one section independent per menu — the reason it lives on the pivot', function () {
    // Decision A. `menu_sections` is N:N with menus and heavily reused (139
    // pivot rows over 15 sections and 29 menus in the dev database), so a column
    // on the section itself would push a takeaway 8% into every other menu that
    // shows the same section.
    $takeaway = ($this->makeMenu)();
    $dineIn = ($this->makeMenu)();

    $takeaway->menuSections()->attach($this->section->id, [
        'tax_type_id' => $this->red->id,
        'display_order' => 0,
    ]);
    $dineIn->menuSections()->attach($this->section->id, ['display_order' => 0]);

    expect(($this->resolve)($takeaway, $this->section)->rate)->toBe(8.0)
        ->and(($this->resolve)($dineIn, $this->section)->rate)->toBe(10.0); // untouched
});

it('still reaches the branch default when nothing above it resolves', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $this->red->id,
    ]);

    $productNoType = Product::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->product->product_type_id,
        'tax_type_id' => null,
    ])->load('taxType');

    $menu = ($this->makeMenu)();

    expect((new TaxResolver)->resolveForLine($productNoType, null, $this->branch->id, $this->brand->id, $menu->id, null)->rate)
        ->toBe(8.0);
});

it('behaves exactly as before when no menu context is supplied', function () {
    // Every pre-#1218 call site passes no menu context. The walk must then be
    // the old four tiers, or this change would have moved rates on paths that
    // were never meant to be touched.
    expect((new TaxResolver)->resolveForLine($this->product, null, $this->branch->id, $this->brand->id)->rate)
        ->toBe(10.0);
});
