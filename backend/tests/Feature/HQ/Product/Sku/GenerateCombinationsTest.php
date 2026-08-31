<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\User;
use App\Services\Product\Contracts\ProductCatalogProjectionPort;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.4 GenerateCombinationsTest
 *
 * Covers TESTS.md scenarios:
 *   - Happy path #8    — 2 options × (3 × 2) values → 6 generated
 *   - Happy path #9    — same product with 2 pre-existing SKUs → 4 generated, 2 skipped
 *   - Edge case #28    — no_options → 422
 *   - Edge case #29    — option with no active values → 422
 *   - Edge case #30    — >500 combinations → 422
 *   - Edge case #31    — soft-deleted SKU with matching signature → restored + reactivated
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-gen',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

/**
 * Create a product with two options (3 values × 2 values).
 */
function makeTwoOptionProduct(Brand $brand): Product
{
    $product = Product::factory()->forBrand($brand)->create();

    $opt1 = ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => 1,
        'key' => 'size',
        'name' => 'Size',
    ]);
    foreach (['s', 'm', 'l'] as $i => $slug) {
        ProductOptionValue::factory()->create([
            'option_id' => $opt1->id,
            'value' => $slug,
            'label' => strtoupper($slug),
            'position' => $i + 1,
            'is_active' => true,
        ]);
    }

    $opt2 = ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => 2,
        'key' => 'color',
        'name' => 'Color',
    ]);
    foreach (['red', 'blue'] as $i => $slug) {
        ProductOptionValue::factory()->create([
            'option_id' => $opt2->id,
            'value' => $slug,
            'label' => ucfirst($slug),
            'position' => $i + 1,
            'is_active' => true,
        ]);
    }

    return $product->refresh();
}

it('generates the Cartesian product of all option values (6 combinations)', function () {
    $product = makeTwoOptionProduct($this->brand);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations");

    $response->assertStatus(201)
        ->assertJsonPath('created_count', 6);

    // 6 generated + 0 pre-existing before this call → 6 total rows for the product
    expect(ProductSku::where('product_id', $product->id)->count())->toBe(6);

    // Every generated SKU has a unique option_signature.
    $signatures = ProductSku::where('product_id', $product->id)->pluck('option_signature');
    expect($signatures->unique()->count())->toBe(6);
});

it('skips combinations that already exist and only generates the missing ones', function () {
    $product = makeTwoOptionProduct($this->brand);

    $opt1 = $product->options()->where('position', 1)->first();
    $opt2 = $product->options()->where('position', 2)->first();
    $s = $opt1->values()->where('value', 's')->first();
    $m = $opt1->values()->where('value', 'm')->first();
    $red = $opt2->values()->where('value', 'red')->first();
    $blue = $opt2->values()->where('value', 'blue')->first();

    // Pre-create 2 SKUs: (S, Red) and (M, Blue)
    ProductSku::factory()->withOptionValues($s, $red)->create([
        'product_id' => $product->id,
    ]);
    ProductSku::factory()->withOptionValues($m, $blue)->create([
        'product_id' => $product->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations");

    $response->assertStatus(201)
        ->assertJsonPath('created_count', 4);

    expect(ProductSku::where('product_id', $product->id)->count())->toBe(6);
});

it('returns 422 when the product has no options', function () {
    $product = Product::factory()->forBrand($this->brand)->create();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['options']);
});

it('returns 422 when an option has no values', function () {
    $product = Product::factory()->forBrand($this->brand)->create();
    ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => 1,
        'key' => 'empty_opt',
        'name' => 'Empty',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['options']);
});

it('returns 422 when the combination count exceeds the MAX_GENERATED_COMBINATIONS limit', function () {
    $product = Product::factory()->forBrand($this->brand)->create();

    // 10 × 10 × 6 = 600 > 500
    foreach ([1, 2, 3] as $pos) {
        $opt = ProductOption::factory()->create([
            'product_id' => $product->id,
            'position' => $pos,
            'key' => 'opt'.$pos,
            'name' => 'Option '.$pos,
        ]);

        $count = $pos === 3 ? 6 : 10;
        for ($v = 0; $v < $count; $v++) {
            ProductOptionValue::factory()->create([
                'option_id' => $opt->id,
                'value' => "o{$pos}v{$v}",
                'label' => "V{$pos}.{$v}",
                'position' => $v + 1,
                'is_active' => true,
            ]);
        }
    }

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['combinations']);
});

it('restores and reactivates a soft-deleted SKU whose signature matches, preserving its pricing', function () {
    $product = Product::factory()->forBrand($this->brand)->create();

    $opt = ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => 1,
        'key' => 'size',
        'name' => 'Size',
    ]);
    $value = ProductOptionValue::factory()->create([
        'option_id' => $opt->id,
        'value' => 's',
        'label' => 'S',
        'position' => 1,
        'is_active' => true,
    ]);

    // Pre-create the combo with an explicit selling_price + inactive flag,
    // then soft-delete it. Re-running generateCombinations should restore the
    // row, flip is_active back to true, and keep the original selling_price.
    $sku = ProductSku::factory()->withOptionValues($value)->create([
        'product_id' => $product->id,
        'selling_price' => 12345.67,
        'is_active' => false,
    ]);
    $sku->delete();

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations");

    $response->assertStatus(201)
        ->assertJsonPath('created_count', 1);

    // No duplicate row was created — the trashed row was reused.
    expect(ProductSku::withTrashed()->where('product_id', $product->id)->count())->toBe(1);

    $sku->refresh();
    expect($sku->deleted_at)->toBeNull();
    expect((bool) $sku->is_active)->toBeTrue();
    expect((float) $sku->selling_price)->toBe(12345.67);
});

it('creates a fresh SKU with price=0 when no signature matches', function () {
    $product = Product::factory()->forBrand($this->brand)->create();

    $opt = ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => 1,
        'key' => 'size',
        'name' => 'Size',
    ]);
    $value = ProductOptionValue::factory()->create([
        'option_id' => $opt->id,
        'value' => 's',
        'label' => 'S',
        'position' => 1,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations");

    $response->assertStatus(201)
        ->assertJsonPath('created_count', 1);

    $sku = ProductSku::where('product_id', $product->id)
        ->where('option_value1_id', $value->id)
        ->firstOrFail();

    expect((float) $sku->selling_price)->toBe(0.0);
    expect((float) $sku->cost_price)->toBe(0.0);
    expect((bool) $sku->is_active)->toBeTrue();
});

it('projects generated SKUs exactly once', function () {
    $product = makeTwoOptionProduct($this->brand);
    $projection = new class implements ProductCatalogProjectionPort
    {
        public int $calls = 0;

        public function syncNewSkusToMenuBranches(string $productId, array $newSkus): void
        {
            $this->calls++;
        }
    };
    app()->instance(ProductCatalogProjectionPort::class, $projection);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations")
        ->assertCreated()
        ->assertJsonPath('created_count', 6);

    expect($projection->calls)->toBe(1);
});

it('rolls back generated SKUs when catalog projection fails', function () {
    $product = makeTwoOptionProduct($this->brand);
    app()->instance(ProductCatalogProjectionPort::class, new class implements ProductCatalogProjectionPort
    {
        public function syncNewSkusToMenuBranches(string $productId, array $newSkus): void
        {
            throw new RuntimeException('catalog projection failed');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations"))
        ->toThrow(RuntimeException::class, 'catalog projection failed');

    expect(ProductSku::where('product_id', $product->id)->count())->toBe(0);
});
