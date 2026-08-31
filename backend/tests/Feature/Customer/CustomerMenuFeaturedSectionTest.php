<?php

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;

/**
 * #1187 — the customer "featured" carousel reads MenuSection.is_featured.
 * It used to scan the display name for a handful of hard-coded words and
 * emoji, so renaming a section silently emptied the carousel.
 */
const FEATURED_ORG = '00000000-0000-0000-0000-000000000001';

beforeEach(function () {
    $this->branch = Branch::factory()->create(['console_organization_id' => FEATURED_ORG]);
    $zone = Zone::factory()->create(['organization_id' => FEATURED_ORG, 'branch_id' => $this->branch->id]);
    Table::factory()->create([
        'organization_id' => FEATURED_ORG,
        'branch_id' => $this->branch->id,
        'zone_id' => $zone->id,
        'qr_token' => 'featured-token',
        'is_active' => true,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => FEATURED_ORG,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $this->addSection = function (string $name, bool $isFeatured, int $order = 1): MenuSection {
        $section = MenuSection::factory()->create(['name' => $name, 'is_featured' => $isFeatured]);
        $this->menu->menuSections()->attach($section, ['display_order' => $order]);

        $product = Product::factory()->active()->create([
            'name' => $name.' item',
            'organization_id' => FEATURED_ORG,
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000]);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $this->menu->id,
            'product_id' => $product->id,
            'menu_section_id' => $section->id,
            'is_active' => true,
            'display_order' => 0,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'selling_price' => 1000,
            'is_active' => true,
        ]);

        return $section;
    };
});

function featuredCategories(): array
{
    return test()->getJson('/api/v1/customer/tables/featured-token/menu')
        ->assertOk()
        ->json('data.categories');
}

it('marks a section featured from the flag, whatever it is called', function () {
    // A name that matches NONE of the old hard-coded strings — this is exactly
    // the case that used to break: the shop renames, the carousel empties.
    ($this->addSection)('店長イチオシ', true, 1);
    ($this->addSection)('ドリンク', false, 2);

    $categories = featuredCategories();

    expect($categories)->toHaveCount(2)
        ->and($categories[0]['name'])->toBe('店長イチオシ')
        ->and($categories[0]['is_featured'])->toBeTrue()
        ->and($categories[1]['is_featured'])->toBeFalse();
});

it('does not feature a section merely because its name says so', function () {
    // The inverse of the old bug: a shop naming a section "Featured" without
    // ticking the flag must NOT be pulled into the carousel.
    ($this->addSection)('Featured', false, 1);
    ($this->addSection)('おすすめ', false, 2);

    expect(array_column(featuredCategories(), 'is_featured'))->toBe([false, false]);
});

it('sends is_featured on every category so the client never guesses', function () {
    ($this->addSection)('Mains', false, 1);

    $categories = featuredCategories();

    expect($categories[0])->toHaveKey('is_featured')
        ->and($categories[0]['is_featured'])->toBeBool();
});
