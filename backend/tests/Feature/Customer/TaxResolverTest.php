<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\TaxResolver;

/**
 * plan-043 T1.7 — TaxResolver resolution chain (§7), rate pick (decision #5),
 * and 酒類 escalation (Decision 10 / A1).
 *
 * Placed in Feature (not Unit) because the resolver queries branch/brand
 * defaults — Unit/* has no RefreshDatabase per tests/Pest.php.
 */
beforeEach(function () {
    $orgId = '00000000-0000-0000-0000-000000000001'; // seeded by Pest beforeEach

    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $pt = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);

    $this->std = TaxType::factory()->standard()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);
    $this->red = TaxType::factory()->reduced()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);
    $this->exe = TaxType::factory()->exempt()->create(['organization_id' => $orgId, 'brand_id' => $this->brand->id]);

    $this->makeProduct = fn (?string $taxTypeId = null) => Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
        'tax_type_id' => $taxTypeId,
    ]);

    $this->resolver = new TaxResolver;
});

it('resolves the product tax type to its ONE rate — REDUCED is 8% no matter how the order is consumed (#1099)', function () {
    $product = ($this->makeProduct)($this->red->id)->load('taxType');

    $r = $this->resolver->resolveForLine($product, null, $this->branch->id, $this->brand->id);

    expect($r->taxTypeId)->toBe($this->red->id)
        ->and($r->rate)->toBe(8.0);
});

it('never looks at an order type: the resolver signature has no consumption context (#1099)', function () {
    // Context (店内/持ち帰り/delivery) is a MENU concern: the takeaway menu
    // carries REDUCED overrides on its items. The resolver only walks tiers.
    $product = ($this->makeProduct)($this->std->id)->load('taxType');

    $r = $this->resolver->resolveForLine($product, null, $this->branch->id, $this->brand->id);

    expect($r->rate)->toBe(10.0);
});

it('lets a menu override win over the product tax type', function () {
    $product = ($this->makeProduct)($this->red->id)->load('taxType');

    $r = $this->resolver->resolveForLine($product, $this->std, $this->branch->id, $this->brand->id);

    expect($r->taxTypeId)->toBe($this->std->id)->and($r->rate)->toBe(10.0);
});

it('falls back to the branch default when the product has no type', function () {
    ShopOrderSetting::factory()->create(['branch_id' => $this->branch->id, 'default_tax_type_id' => $this->red->id]);
    $product = ($this->makeProduct)(null)->load('taxType');

    $r = $this->resolver->resolveForLine($product, null, $this->branch->id, $this->brand->id);

    expect($r->taxTypeId)->toBe($this->red->id)->and($r->rate)->toBe(8.0);
});

it('falls back to the brand default when neither product nor branch has a type', function () {
    $this->red->update(['is_default' => true]);
    $product = ($this->makeProduct)(null)->load('taxType');

    $r = $this->resolver->resolveForLine($product, null, $this->branch->id, $this->brand->id);

    expect($r->taxTypeId)->toBe($this->red->id)->and($r->rate)->toBe(8.0);
});

it('resolves to 0% with no type when nothing is configured (T6.2 dropped the legacy fallback)', function () {
    // No menu/product override, no branch default, no brand is_default type.
    // plan-043 T6.2 dropped the legacy ShopOrderSetting.tax_rate fallback, so
    // the resolver returns 0% and no type to snapshot.
    ShopOrderSetting::factory()->create(['branch_id' => $this->branch->id, 'default_tax_type_id' => null]);
    $product = ($this->makeProduct)(null)->load('taxType');

    $r = $this->resolver->resolveForLine($product, null, $this->branch->id, $this->brand->id);

    expect($r->taxTypeId)->toBeNull()->and($r->rate)->toBe(0.0);
});

it('taxes an alcohol line at 0% like any line when the brand has ZERO tax types (config gap, logged)', function () {
    $beer = ($this->makeProduct)(null)->load('taxType');

    $r = $this->resolver->resolveForLine($beer, null, $this->branch->id, $this->brand->id);

    expect($r->taxTypeId)->toBeNull()
        ->and($r->rate)->toBe(0.0);
});

it('inherit tiers govern alcohol like any product: branch default, brand default, menu override', function () {
    // tier-3 branch default (exempt)
    ShopOrderSetting::factory()->create(['branch_id' => $this->branch->id, 'default_tax_type_id' => $this->exe->id]);
    $beer = ($this->makeProduct)(null)->load('taxType');
    $r = $this->resolver->resolveForLine($beer, null, $this->branch->id, $this->brand->id);
    expect($r->taxTypeId)->toBe($this->exe->id)->and($r->rate)->toBe(0.0);

    // tier-1 menu override (exempt over a standard product)
    $beer2 = ($this->makeProduct)($this->std->id)->load('taxType');
    $r2 = $this->resolver->resolveForLine($beer2, $this->exe, $this->branch->id, $this->brand->id);
    expect($r2->taxTypeId)->toBe($this->exe->id)->and($r2->rate)->toBe(0.0);
});

it('resolves branch + brand defaults with a single query each across many lines (N+1 guard)', function () {
    ShopOrderSetting::factory()->create(['branch_id' => $this->branch->id, 'default_tax_type_id' => $this->red->id]);
    $products = collect(range(1, 5))->map(fn () => ($this->makeProduct)(null)->load('taxType'));

    DB::enableQueryLog();
    foreach ($products as $p) {
        $this->resolver->resolveForLine($p, null, $this->branch->id, $this->brand->id);
    }
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'shop_order_settings') || str_contains($q, 'tax_types'));
    DB::disableQueryLog();

    // Memoised: one shop_order_settings lookup for the branch default, no repeat per line.
    expect($queries->count())->toBeLessThanOrEqual(2);
});
