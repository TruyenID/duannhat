<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan 003 — T5.8 ProductSkuObserverTest
 *
 * Covers TESTS.md scenarios:
 *   - Edge case (unit)  — saving observer computes NULL-safe option_signature
 *                          in several shapes: "abc||xyz", "abc|def|", "||"
 *   - Edge case (unit)  — second insert with the same trio of option_value FKs
 *                          raises a unique-violation on (product_id, option_signature)
 */
uses(TestCase::class, RefreshDatabase::class);

function makeProductWithOptions(): Product
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
        'is_active' => true,
    ]);

    return Product::factory()->forBrand($brand)->create();
}

function makeOptionValue(Product $product, int $position, string $slug): ProductOptionValue
{
    $option = ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => $position,
        'key' => "opt{$position}",
        'name' => "Option {$position}",
    ]);

    return ProductOptionValue::factory()->create([
        'option_id' => $option->id,
        'value' => $slug,
        'label' => strtoupper($slug),
        'position' => 1,
    ]);
}

it('computes an alphabetically sorted signature ignoring NULL slots', function () {
    $product = makeProductWithOptions();
    $v1 = makeOptionValue($product, 1, 'a');
    $v3 = makeOptionValue($product, 3, 'c');

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'option_value1_id' => $v1->id,
        'option_value2_id' => null,
        'option_value3_id' => $v3->id,
    ]);

    $ids = [$v1->id, $v3->id];
    sort($ids);
    expect($sku->option_signature)->toBe(implode('|', $ids));
});

it('computes the sorted signature when the third slot is NULL', function () {
    $product = makeProductWithOptions();
    $v1 = makeOptionValue($product, 1, 'a');
    $v2 = makeOptionValue($product, 2, 'b');

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'option_value1_id' => $v1->id,
        'option_value2_id' => $v2->id,
        'option_value3_id' => null,
    ]);

    $ids = [$v1->id, $v2->id];
    sort($ids);
    expect($sku->option_signature)->toBe(implode('|', $ids));
});

it('computes an empty signature when all option value slots are NULL', function () {
    $product = makeProductWithOptions();

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'option_value1_id' => null,
        'option_value2_id' => null,
        'option_value3_id' => null,
    ]);

    expect($sku->option_signature)->toBe('');
});

it('produces the same signature regardless of which slot holds each value ID', function () {
    // Pure unit test — no DB needed, just verify the static method directly.
    $idA = (string) Str::uuid();
    $idB = (string) Str::uuid();

    $sorted = [$idA, $idB];
    sort($sorted);
    $expected = implode('|', $sorted);

    // Slot 1 = A, slot 2 = B  (normal order)
    expect(ProductSku::computeOptionSignature($idA, $idB, null))->toBe($expected);

    // Slot 1 = B, slot 2 = A  (swapped — simulates a position reorder)
    expect(ProductSku::computeOptionSignature($idB, $idA, null))->toBe($expected);

    // Slots 1 and 3, slot 2 empty
    expect(ProductSku::computeOptionSignature($idA, null, $idB))
        ->toBe(ProductSku::computeOptionSignature(null, $idB, $idA));
});

it('raises a unique-violation when two SKUs share the same (product_id, option_signature)', function () {
    $product = makeProductWithOptions();
    $v1 = makeOptionValue($product, 1, 'a');
    $v2 = makeOptionValue($product, 2, 'b');

    ProductSku::factory()->create([
        'product_id' => $product->id,
        'option_value1_id' => $v1->id,
        'option_value2_id' => $v2->id,
        'option_value3_id' => null,
    ]);

    expect(fn () => ProductSku::factory()->create([
        'product_id' => $product->id,
        'option_value1_id' => $v1->id,
        'option_value2_id' => $v2->id,
        'option_value3_id' => null,
    ]))->toThrow(QueryException::class);
});
