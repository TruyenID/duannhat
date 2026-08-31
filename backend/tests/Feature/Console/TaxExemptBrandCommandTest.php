<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * issue #1042 option A — `catalog:tax-exempt-brand` points a brand's whole
 * catalog at its 非課税 (0/0) tax type so the `prices_include_tax` toggle is a
 * pure display label. PRICES MUST NEVER MOVE.
 *
 * Plan 047 T4.14 moved the write block behind
 * App\Console\Maintenance\TaxExemptBrandPersistence; these tests pin the
 * command's observable behaviour so that refactor (and any future one) cannot
 * silently change what the operator gets.
 */
beforeEach(function () {
    $this->org = Organization::query()->first() ?? Organization::factory()->create();

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->org->id,
        'slug' => 'target-brand',
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->org->id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->standard = TaxType::factory()->standard()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'is_default' => true,
    ]);
    $this->exempt = TaxType::factory()->exempt()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'is_default' => false,
    ]);

    $this->setting = ShopOrderSetting::factory()->create([
        'organization_id' => $this->org->id,
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $this->standard->id,
        'prices_include_tax' => true,
    ]);
});

function makeProduct(object $ctx, array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'organization_id' => $ctx->org->id,
        'brand_id' => $ctx->brand->id,
        'product_type_id' => $ctx->productType->id,
        'tax_type_id' => $ctx->standard->id,
    ], $overrides));
}

function runTaxExempt(array $options = []): int
{
    return Artisan::call('catalog:tax-exempt-brand', array_merge(['--brand' => 'target-brand'], $options));
}

it('nulls menu-product tax overrides so they inherit the product', function () {
    $product = makeProduct($this);
    $menu = Menu::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
    ]);
    $override = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'tax_type_id' => $this->standard->id,
    ]);
    $alreadyInheriting = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => makeProduct($this)->id,
        'tax_type_id' => null,
    ]);

    runTaxExempt();

    expect($override->fresh()->tax_type_id)->toBeNull()
        ->and($alreadyInheriting->fresh()->tax_type_id)->toBeNull();
});

it('makes the exempt type the sole brand default', function () {
    makeProduct($this);

    runTaxExempt();

    expect($this->exempt->fresh()->is_default)->toBeTrue()
        ->and($this->standard->fresh()->is_default)->toBeFalse()
        ->and(TaxType::query()->where('brand_id', $this->brand->id)->where('is_default', true)->count())->toBe(1);
});

it('repoints branch default tax type but leaves the inclusive label alone without --off', function () {
    makeProduct($this);

    runTaxExempt();

    $setting = $this->setting->fresh();
    expect($setting->default_tax_type_id)->toBe($this->exempt->id)
        ->and((bool) $setting->prices_include_tax)->toBeTrue();
});

it('flips prices_include_tax to false with --off', function () {
    makeProduct($this);

    runTaxExempt(['--off' => true]);

    $setting = $this->setting->fresh();
    expect($setting->default_tax_type_id)->toBe($this->exempt->id)
        ->and((bool) $setting->prices_include_tax)->toBeFalse();
});

it('skips soft-deleted products', function () {
    $deleted = makeProduct($this);
    $deleted->delete();

    runTaxExempt();

    expect(DB::table('products')->where('id', $deleted->id)->value('tax_type_id'))->toBe($this->standard->id);
});

it('fails cleanly for an unknown brand slug', function () {
    $product = makeProduct($this);

    expect(Artisan::call('catalog:tax-exempt-brand', ['--brand' => 'does-not-exist']))->toBe(1)
        ->and($product->fresh()->tax_type_id)->toBe($this->standard->id);
});

it('fails cleanly when the brand has no active zero-rate tax type', function () {
    $this->exempt->update(['is_active' => false]);
    $product = makeProduct($this);

    expect(runTaxExempt())->toBe(1)
        ->and($product->fresh()->tax_type_id)->toBe($this->standard->id)
        ->and($this->standard->fresh()->is_default)->toBeTrue();
});

it('rejects an asymmetric rate as the exempt candidate', function () {
    // Only 0/0 counts. A 0% dine-in / 8% takeaway type is NOT tax-exempt.
    $this->exempt->update(['rate' => 8]);
    $product = makeProduct($this);

    expect(runTaxExempt())->toBe(1)
        ->and($product->fresh()->tax_type_id)->toBe($this->standard->id);
});
