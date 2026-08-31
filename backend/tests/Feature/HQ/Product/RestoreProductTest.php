<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.3d RestoreProductTest
 *
 * Covers TESTS.md scenarios:
 *   - Happy path #7 — POST /restore restores product + cascaded child rows
 *                     that share the original deleted_at timestamp.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-restore',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

it('restores a soft-deleted product and cascades the restore to its SKUs', function () {
    $product = Product::factory()->forBrand($this->brand)->withOptions(1, 2)->create();
    $option = $product->options()->with('values')->first();

    foreach ($option->values as $value) {
        ProductSku::factory()->withOptionValues($value)->create([
            'product_id' => $product->id,
        ]);
    }

    // Cascade-delete via the API (uses the same cascade logic).
    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$product->id}")
        ->assertNoContent();

    // Sanity: everything is soft-deleted.
    expect(Product::find($product->id))->toBeNull();
    expect(ProductSku::where('product_id', $product->id)->count())->toBe(0);

    // Restore.
    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.deleted_at', null);

    expect(Product::find($product->id))->not->toBeNull();
    expect(ProductSku::where('product_id', $product->id)->count())->toBe(2);
});
