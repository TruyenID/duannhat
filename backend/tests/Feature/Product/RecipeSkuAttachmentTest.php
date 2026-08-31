<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;

/**
 * Cover the Product-SKU side of the recipe output picker (branch
 * `feature/cover-product-recipe`). Recipes can attach themselves to one or
 * more ProductSkus by passing `sku_ids` in the create/update payload — the
 * service writes `recipe_id` on each SKU and clears stale links.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    $this->actingAs($this->user);
});

/**
 * Helper — mint a fresh Product + SKU pair. Each test that needs multiple
 * SKUs uses a fresh product because the `product_id + option_signature`
 * unique index rejects two no-option SKUs under the same product.
 */
function mintSku(Brand $brand, string $orgId, ProductType $productType, array $skuAttrs = []): ProductSku
{
    $product = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'product_type_id' => $productType->id,
    ]);

    return ProductSku::factory()->create(array_merge([
        'product_id' => $product->id,
    ], $skuAttrs));
}

describe('create with sku_ids', function () {
    it('attaches every provided SKU to the new recipe', function () {
        $skuA = mintSku($this->brand, $this->orgId, $this->productType);
        $skuB = mintSku($this->brand, $this->orgId, $this->productType);

        $response = $this->postJson("{$this->baseUrl}/recipes", [
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Pancake batter',
            'output_quantity' => 1,
            'is_active' => true,
            'sku_ids' => [$skuA->id, $skuB->id],
        ])->assertCreated();

        $recipeId = $response->json('data.id');

        expect($skuA->refresh()->recipe_id)->toBe($recipeId);
        expect($skuB->refresh()->recipe_id)->toBe($recipeId);

        // The resource emits the attached SKUs back so the FE can paint chips
        // on save without a second round-trip.
        $response->assertJsonPath('data.skus.0.id', $skuA->id);
    });

    it('ignores sku_ids = [] on create (nothing to attach)', function () {
        $this->postJson("{$this->baseUrl}/recipes", [
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Plain recipe',
            'output_quantity' => 1,
            'is_active' => true,
            'sku_ids' => [],
        ])->assertCreated()
            ->assertJsonPath('data.skus', []);
    });

    it('rejects sku_ids whose product belongs to a different brand', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $otherProductType = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
        ]);
        $otherProduct = Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'product_type_id' => $otherProductType->id,
        ]);
        $foreignSku = ProductSku::factory()->create(['product_id' => $otherProduct->id]);

        $this->postJson("{$this->baseUrl}/recipes", [
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Cross-brand recipe',
            'output_quantity' => 1,
            'is_active' => true,
            'sku_ids' => [$foreignSku->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['sku_ids']);

        expect($foreignSku->refresh()->recipe_id)->toBeNull();
    });
});

describe('update with sku_ids', function () {
    it('attaches new SKUs and detaches removed ones', function () {
        $recipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'material_id' => null,
        ]);

        $skuA = mintSku($this->brand, $this->orgId, $this->productType, ['recipe_id' => $recipe->id]);
        $skuB = mintSku($this->brand, $this->orgId, $this->productType, ['recipe_id' => $recipe->id]);
        $skuC = mintSku($this->brand, $this->orgId, $this->productType);

        // Keep A, drop B, add C.
        $this->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
            'sku_ids' => [$skuA->id, $skuC->id],
        ])->assertOk();

        expect($skuA->refresh()->recipe_id)->toBe($recipe->id);
        expect($skuB->refresh()->recipe_id)->toBeNull();
        expect($skuC->refresh()->recipe_id)->toBe($recipe->id);
    });

    it('clears all SKU links when sku_ids is an empty array', function () {
        $recipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'material_id' => null,
        ]);

        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'recipe_id' => $recipe->id,
        ]);

        $this->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
            'sku_ids' => [],
        ])->assertOk();

        expect($sku->refresh()->recipe_id)->toBeNull();
    });

    it('leaves existing SKU links untouched when sku_ids is omitted from the payload', function () {
        $recipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'material_id' => null,
        ]);

        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'recipe_id' => $recipe->id,
        ]);

        // Only touch the name — the omitted sku_ids must NOT clear the link
        // (distinguishes "leave alone" from "sku_ids: []" = "detach all").
        $this->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
            'name' => 'Renamed only',
        ])->assertOk();

        expect($sku->refresh()->recipe_id)->toBe($recipe->id);
    });
});
