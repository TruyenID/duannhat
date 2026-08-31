<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * #1223 — POS menu search has to match what the cashier can SEE.
 *
 * Product names live in `product_translations`, one row per locale. The
 * `products.name` column is only a fallback, written ja → en → vi by preference,
 * while the POS displays `localizedName()` for the cashier's Accept-Language. So
 * a shop that filled in all three languages showed a Vietnamese cashier
 * Vietnamese names and searched Japanese: typing exactly what was on the screen
 * returned "no products found".
 *
 * Every locale is searched, not just the current one — that is the requirement
 * ("findable in any of the three"), and a cashier serving a foreign guest may
 * type a name in a language other than the UI is set to. The current locale
 * still decides only how results are DISPLAYED.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'pos-search-probe',
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    // The shape that breaks it: base column is JAPANESE (the ja → en → vi
    // preference), and the Vietnamese name exists only as a translation row.
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
        'name' => '牛肉フォー',
    ]);
    $product->translateOrNew('vi')->name = 'Phở bò tái';
    $product->translateOrNew('en')->name = 'Rare Beef Pho';
    $product->translateOrNew('ja')->name = '牛肉フォー';
    $product->save();

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'name' => 'Lサイズ',
        'sku' => 'PHO-L-001',
        'selling_price' => 1000,
        'is_active' => true,
    ]);
    $sku->translateOrNew('vi')->name = 'Cỡ lớn';
    $sku->save();

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $this->menu->id,
        'product_id' => $product->id,
        'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $this->search = fn (string $term, string $locale = 'vi') => $this->actingAs($this->user)
        ->withHeader('X-Shop-Slug', $this->branch->slug)
        ->withHeader('Accept-Language', $locale)
        ->getJson("/api/v1/pos/menus/{$this->menu->id}/products?search=".urlencode($term))
        ->assertOk()
        ->json('data');
});

it('finds a product by its Vietnamese name when the base column is Japanese', function () {
    // The reported symptom, exactly: cashier on `vi` sees "Phở bò tái", types it,
    // and used to get nothing because the query ran against 牛肉フォー.
    expect(($this->search)('Phở bò tái'))->toHaveCount(1);
    expect(($this->search)('phở'))->toHaveCount(1);
});

it('finds the same product by its English and Japanese names', function () {
    expect(($this->search)('Rare Beef'))->toHaveCount(1)
        ->and(($this->search)('牛肉'))->toHaveCount(1);
});

it('finds a product typed in a language other than the cashier UI locale', function () {
    // A cashier on Vietnamese serving a Japanese guest types the Japanese name.
    expect(($this->search)('牛肉フォー', 'vi'))->toHaveCount(1)
        ->and(($this->search)('Phở bò tái', 'ja'))->toHaveCount(1);
});

it('still finds an SKU by its variant name and by its raw code', function () {
    // Pre-existing behaviour that must survive: the barcode code and the base
    // variant name, plus the variant name's translation.
    expect(($this->search)('PHO-L-001'))->toHaveCount(1)
        ->and(($this->search)('Lサイズ'))->toHaveCount(1)
        ->and(($this->search)('Cỡ lớn'))->toHaveCount(1);
});

it('returns nothing for a term that matches no locale', function () {
    expect(($this->search)('zzz-not-a-dish'))->toHaveCount(0);
});

it('returns one row per menu product, not one per matching translation', function () {
    // The row has three translations and all three contain "o"; a join instead
    // of whereHas would return the product three times and break the paginate
    // count. This is why the query uses whereHas.
    $data = ($this->search)('o');

    expect($data)->toHaveCount(1);
});
