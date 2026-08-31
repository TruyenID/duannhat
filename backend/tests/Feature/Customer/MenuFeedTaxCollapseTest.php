<?php

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\TaxType;
use App\Models\Zone;
use App\Services\Customer\TaxResolver;

/**
 * #1218 — the menu feed's `tax_type_id` is not a raw column, it is a COLLAPSE.
 *
 * The workstation never sees the tiers separately: it resolves only what this
 * one column hands it (see `tier_collapse_note` in tax_resolution_golden.json).
 * So the collapse has to walk the same order TaxResolver::resolveType does —
 *
 *     menu-item override → section in this menu → whole menu → product
 *
 * — or a receipt printed on the LAN disagrees with the invoice Cloud books for
 * the same basket. That is the #1203 failure shape: two engines, one basket,
 * two prices, and nobody notices until an offline order is rejected as tampered
 * or a customer is handed the wrong total.
 *
 * These tests exist because the collapse is the ONLY place the new tiers reach
 * the workstation. The resolver being right (TaxResolverMenuSectionTierTest) is
 * not sufficient — the Go side never calls it.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
    $zone = Zone::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $zone->id,
        'qr_token' => 'collapse-token',
    ]);

    $this->brandId = $this->branch->console_brand_id ?? $this->branch->id;

    $this->standard = TaxType::factory()->standard()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandId,
    ]);
    $this->reduced = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandId,
    ]);
    $this->exempt = TaxType::factory()->exempt()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandId,
    ]);

    /**
     * Build a one-item menu, with a tax type optionally set at each tier.
     */
    $this->buildMenu = function (
        ?string $menuTaxTypeId = null,
        ?string $sectionTaxTypeId = null,
        ?string $itemTaxTypeId = null,
        ?string $productTaxTypeId = null,
    ): void {
        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brandId,
            'status' => 'Active',
            'priority' => 1,
            'tax_type_id' => $menuTaxTypeId,
        ]);

        $section = MenuSection::factory()->create(['name' => 'Food']);
        $menu->menuSections()->attach($section->id, [
            'display_order' => 0,
            'tax_type_id' => $sectionTaxTypeId,
        ]);

        $product = Product::factory()->active()->create([
            'name' => 'Bento',
            'organization_id' => $this->orgId,
            'tax_type_id' => $productTaxTypeId,
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000]);

        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $menu->id,
            'product_id' => $product->id,
            'menu_section_id' => $section->id,
            'is_active' => true,
            'display_order' => 0,
            'tax_type_id' => $itemTaxTypeId,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'selling_price' => 1000,
            'is_active' => true,
        ]);
    };

    $this->feedTaxTypeId = fn (): ?string => $this
        ->getJson('/api/v1/customer/tables/collapse-token/menu')
        ->json('data.categories.0.items.0.tax_type_id');
});

it('sends the whole-menu type when nothing more specific is set', function () {
    ($this->buildMenu)(menuTaxTypeId: $this->reduced->id, productTaxTypeId: $this->standard->id);

    // The feature in one line: a 持ち帰り menu ships 8% to the register without
    // any item being touched — and it beats the product, per the ruling.
    expect(($this->feedTaxTypeId)())->toBe($this->reduced->id);
});

it('sends the section type over the menu type', function () {
    ($this->buildMenu)(
        menuTaxTypeId: $this->reduced->id,
        sectionTaxTypeId: $this->standard->id,
        productTaxTypeId: $this->exempt->id,
    );

    expect(($this->feedTaxTypeId)())->toBe($this->standard->id);
});

it('sends the menu-item override over everything', function () {
    ($this->buildMenu)(
        menuTaxTypeId: $this->standard->id,
        sectionTaxTypeId: $this->standard->id,
        itemTaxTypeId: $this->reduced->id,
        productTaxTypeId: $this->standard->id,
    );

    expect(($this->feedTaxTypeId)())->toBe($this->reduced->id);
});

it('falls through to the product when no menu tier sets one', function () {
    ($this->buildMenu)(productTaxTypeId: $this->reduced->id);

    expect(($this->feedTaxTypeId)())->toBe($this->reduced->id);
});

it('sends null when no tier anywhere sets one, leaving the register its own defaults', function () {
    // Null is meaningful on the wire: the workstation then walks its own
    // branch → brand defaults. Sending a resolved id here instead would rob it
    // of that, and an offline register would stamp a stale default.
    ($this->buildMenu)();

    expect(($this->feedTaxTypeId)())->toBeNull();
});

it('matches what the resolver would decide for the same line', function () {
    // The collapse and the resolver are two implementations of one chain. This
    // pins them together on the case that separates them most: section set,
    // product set, and they disagree.
    ($this->buildMenu)(
        sectionTaxTypeId: $this->reduced->id,
        productTaxTypeId: $this->standard->id,
    );

    $menuProduct = MenuProduct::query()->with('product.taxType')->firstOrFail();

    $resolved = (new TaxResolver)->resolveForLine(
        $menuProduct->product,
        $menuProduct->taxType,
        $this->branch->id,
        (string) $this->brandId,
        $menuProduct->menu_id,
        $menuProduct->menu_section_id,
    );

    expect(($this->feedTaxTypeId)())->toBe($resolved->taxTypeId)
        ->and($resolved->taxTypeId)->toBe($this->reduced->id);
});
