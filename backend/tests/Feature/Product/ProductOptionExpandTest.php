<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Services\Product\Contracts\ProductCatalogProjectionPort;
use App\Services\Product\MenuService;
use App\Services\Product\ProductSkuService;
use App\Services\Product\ValueObjects\CatalogSkuProjection;
use Illuminate\Support\Str;

beforeEach(function () {
    $sharedOrgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create([
        'id' => $sharedOrgId,
        'console_organization_id' => $sharedOrgId,
    ]);
    $this->orgId = $sharedOrgId;

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
});

function expandUrl(Brand $brand, Product $product): string
{
    return "/api/v1/hq/{$brand->slug}/products/{$product->id}/options/expand";
}

function menuForBrand(string $orgId, Brand $brand, bool $isMaster = false): Menu
{
    $branch = Branch::factory()->create(['console_organization_id' => $orgId]);

    return Menu::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'is_master' => $isMaster,
    ]);
}

// =============================================================================
// Expand option — adds option + updates existing SKUs + generates combinations
// =============================================================================

describe('ProductOption expand', function () {
    it('expands a product with existing SKUs by adding a new option', function () {
        // Product has 1 option "Size" with values [S, M]
        $sizeOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'name' => 'Size',
            'position' => 1,
        ]);

        $s = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id,
            'value' => 's',
            'label' => 'S',
            'position' => 1,
        ]);

        $m = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id,
            'value' => 'm',
            'label' => 'M',
            'position' => 2,
        ]);

        // 2 existing SKUs: Size=S, Size=M
        $skuS = ProductSku::factory()->withOptionValues($s)->create([
            'product_id' => $this->product->id,
        ]);
        $skuM = ProductSku::factory()->withOptionValues($m)->create([
            'product_id' => $this->product->id,
        ]);

        // Expand: add "Color" option with values [Red, Blue], default=Red (index 0)
        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'color',
                'name' => 'Color',
                'position' => 2,
                'values' => [
                    ['value' => 'red', 'label' => 'Red'],
                    ['value' => 'blue', 'label' => 'Blue'],
                ],
                'default_value_index' => 0,
            ]);

        $response->assertCreated();

        // Existing SKUs should now have option_value2_id = Red
        $skuS->refresh();
        $skuM->refresh();
        $optionId = $response->json('data.option.id');
        $redValue = ProductOptionValue::where('option_id', $optionId)
            ->where('value', 'red')->first();

        expect($skuS->option_value2_id)->toBe($redValue->id)
            ->and($skuM->option_value2_id)->toBe($redValue->id);

        // New combinations should be generated: S/Blue, M/Blue
        // Total SKUs: S/Red (existing), M/Red (existing), S/Blue (new), M/Blue (new)
        $totalSkus = ProductSku::where('product_id', $this->product->id)->count();
        expect($totalSkus)->toBe(4);

        expect($response->json('data.updated_skus'))->toBe(2);
        expect($response->json('data.created_skus'))->toHaveCount(2);
    });

    it('expands a product with only a default SKU (no options yet)', function () {
        // Product has 1 default SKU with no option values
        $defaultSku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
            'option_value2_id' => null,
            'option_value3_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
                'default_value_index' => 0,
            ]);

        $response->assertCreated();

        // Default SKU should now have option_value1_id = S
        $defaultSku->refresh();
        $optionId = $response->json('data.option.id');
        $sValue = ProductOptionValue::where('option_id', $optionId)
            ->where('value', 's')->first();
        expect($defaultSku->option_value1_id)->toBe($sValue->id);

        // 1 new SKU created for M
        $totalSkus = ProductSku::where('product_id', $this->product->id)->count();
        expect($totalSkus)->toBe(2);
    });

    it('rejects expand when position is already taken', function () {
        ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'color',
                'name' => 'Color',
                'position' => 1,
                'values' => [
                    ['value' => 'red', 'label' => 'Red'],
                ],
                'default_value_index' => 0,
            ]);

        $response->assertStatus(422);
    });

    it('rejects expand when product already has 3 options', function () {
        ProductOption::factory()->create(['product_id' => $this->product->id, 'key' => 'size', 'position' => 1]);
        ProductOption::factory()->create(['product_id' => $this->product->id, 'key' => 'color', 'position' => 2]);
        ProductOption::factory()->create(['product_id' => $this->product->id, 'key' => 'material', 'position' => 3]);

        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'weight',
                'name' => 'Weight',
                'position' => 1,
                'values' => [
                    ['value' => 'light', 'label' => 'Light'],
                ],
                'default_value_index' => 0,
            ]);

        $response->assertStatus(422);
    });

    it('rejects invalid default_value_index', function () {
        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                ],
                'default_value_index' => 5,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('default_value_index');
    });

    it('skips combination generation when generate_combinations is false', function () {
        $defaultSku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
                'default_value_index' => 0,
                'generate_combinations' => false,
            ]);

        $response->assertCreated();

        // Only the original SKU should exist (updated with default value)
        $totalSkus = ProductSku::where('product_id', $this->product->id)->count();
        expect($totalSkus)->toBe(1);
        expect($response->json('data.created_skus'))->toHaveCount(0);
    });

    it('preserves existing SKU UUIDs after expand', function () {
        $sizeOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 1,
        ]);

        $s = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id,
            'value' => 's',
            'label' => 'S',
            'position' => 1,
        ]);

        $existingSku = ProductSku::factory()->withOptionValues($s)->create([
            'product_id' => $this->product->id,
        ]);
        $originalId = $existingSku->id;

        $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'color',
                'name' => 'Color',
                'position' => 2,
                'values' => [
                    ['value' => 'red', 'label' => 'Red'],
                ],
                'default_value_index' => 0,
            ]);

        // SKU UUID must be the same — no replace
        $existingSku->refresh();
        expect($existingSku->id)->toBe($originalId);
    });

    it('handles multiple existing SKUs without unique constraint violation', function () {
        // Product with 1 option "Size" [S, M, L] → 3 SKUs, each with a
        // different option_value1_id but option_value2_id = NULL.
        // When we expand with a new option at position 2, all 3 get the same
        // default value → their signatures must be updated atomically.
        $sizeOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 1,
        ]);

        $s = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 's', 'label' => 'S', 'position' => 1,
        ]);
        $m = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 'm', 'label' => 'M', 'position' => 2,
        ]);
        $l = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 'l', 'label' => 'L', 'position' => 3,
        ]);

        ProductSku::factory()->withOptionValues($s)->create(['product_id' => $this->product->id]);
        ProductSku::factory()->withOptionValues($m)->create(['product_id' => $this->product->id]);
        ProductSku::factory()->withOptionValues($l)->create(['product_id' => $this->product->id]);

        // Expand: add "Color" with [Red, Blue], default=Red
        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'color',
                'name' => 'Color',
                'position' => 2,
                'values' => [
                    ['value' => 'red', 'label' => 'Red'],
                    ['value' => 'blue', 'label' => 'Blue'],
                ],
                'default_value_index' => 0,
            ]);

        $response->assertCreated();
        expect($response->json('data.updated_skus'))->toBe(3);

        // S/Red, M/Red, L/Red (updated) + S/Blue, M/Blue, L/Blue (generated) = 6
        $totalSkus = ProductSku::where('product_id', $this->product->id)->count();
        expect($totalSkus)->toBe(6);
    });

    // -------------------------------------------------------------------------
    // Menu sync cases (Fix 1)
    // -------------------------------------------------------------------------

    it('rolls back option and SKU mutations when catalog projection fails', function () {
        $defaultSku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
            'option_value2_id' => null,
            'option_value3_id' => null,
        ]);

        app()->instance(ProductCatalogProjectionPort::class, new class implements ProductCatalogProjectionPort
        {
            public function syncNewSkusToMenuBranches(string $productId, array $newSkus): void
            {
                throw new RuntimeException('catalog projection failed');
            }
        });

        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'color',
                'name' => 'Color',
                'position' => 1,
                'values' => [
                    ['value' => 'red', 'label' => 'Red'],
                    ['value' => 'blue', 'label' => 'Blue'],
                ],
                'default_value_index' => 0,
            ]))->toThrow(RuntimeException::class, 'catalog projection failed');

        expect(ProductOption::where('product_id', $this->product->id)->exists())->toBeFalse()
            ->and(ProductOptionValue::whereHas('option', fn ($query) => $query
                ->where('product_id', $this->product->id))->exists())->toBeFalse()
            ->and(ProductSku::where('product_id', $this->product->id)->count())->toBe(1)
            ->and($defaultSku->fresh()->option_value1_id)->toBeNull();
    });

    it('syncs new SKUs to branch menus after expand', function () {
        $sizeOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'name' => 'Size',
            'position' => 1,
        ]);
        $s = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 's', 'label' => 'S', 'position' => 1,
        ]);
        $m = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 'm', 'label' => 'M', 'position' => 2,
        ]);
        $skuS = ProductSku::factory()->withOptionValues($s)->create(['product_id' => $this->product->id]);
        $skuM = ProductSku::factory()->withOptionValues($m)->create(['product_id' => $this->product->id]);

        $branchMenu = menuForBrand($this->orgId, $this->brand);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $branchMenu->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $skuS->id,
            'selling_price' => 100000,
            'is_price_overridden' => true,
            'is_active' => true,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $skuM->id,
            'selling_price' => 120000,
            'is_price_overridden' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'color',
                'name' => 'Color',
                'position' => 2,
                'values' => [
                    ['value' => 'red', 'label' => 'Red'],
                    ['value' => 'blue', 'label' => 'Blue'],
                ],
                'default_value_index' => 0,
            ])->assertCreated();

        expect($menuProduct->menuProductSkus()->count())->toBe(4);

        // Existing SKUs keep their branch prices
        $allMps = $menuProduct->menuProductSkus()->get()->keyBy('product_sku_id');
        expect((float) $allMps[$skuS->id]->selling_price)->toBe(100000.0);
        expect((float) $allMps[$skuM->id]->selling_price)->toBe(120000.0);

        // New SKUs start with price=0, is_active=false
        $newSkuIds = ProductSku::where('product_id', $this->product->id)
            ->whereNotIn('id', [$skuS->id, $skuM->id])
            ->pluck('id');

        foreach ($newSkuIds as $newSkuId) {
            $mps = $allMps[$newSkuId] ?? null;
            expect($mps)->not->toBeNull();
            expect((float) $mps->selling_price)->toBe(0.0);
            expect($mps->is_active)->toBeFalse();
        }
    });

    it('does not create MenuProductSku for master menus after expand', function () {
        $defaultSku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
        ]);

        $masterMenu = menuForBrand($this->orgId, $this->brand, isMaster: true);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $masterMenu->id,
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
                'default_value_index' => 0,
            ])->assertCreated();

        expect($menuProduct->menuProductSkus()->count())->toBe(0);
    });

    it('does not fail when product is not in any menu', function () {
        ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
        ]);

        $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
                'default_value_index' => 0,
            ])->assertCreated()
            ->assertJsonCount(1, 'data.created_skus'); // M is new; S was updated in-place (Bước 4)
    });

    it('does not duplicate MenuProductSku when synced twice', function () {
        ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
        ]);

        $branchMenu = menuForBrand($this->orgId, $this->brand);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $branchMenu->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
                'default_value_index' => 0,
            ])->assertCreated();

        // Expand a second time into position 2
        $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'color',
                'name' => 'Color',
                'position' => 2,
                'values' => [
                    ['value' => 'red', 'label' => 'Red'],
                    ['value' => 'blue', 'label' => 'Blue'],
                ],
                'default_value_index' => 0,
            ])->assertCreated();

        // syncNewSkusToMenuBranches only syncs generated combos, not in-place updated SKUs.
        // The no-duplicate invariant: each product_sku_id appears at most once.
        $totalMps = $menuProduct->menuProductSkus()->count();
        $distinctMps = $menuProduct->menuProductSkus()->select('product_sku_id')->distinct()->count();
        expect($distinctMps)->toBe($totalMps);
    });

    // -------------------------------------------------------------------------
    // generateMissingCombinations soft-delete handling (Fix 2)
    // -------------------------------------------------------------------------

    it('restores soft-deleted SKU instead of skipping it during combination generation', function () {
        $sizeOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 1,
        ]);
        $s = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 's', 'position' => 1,
        ]);
        $m = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 'm', 'position' => 2,
        ]);
        $skuS = ProductSku::factory()->withOptionValues($s)->create(['product_id' => $this->product->id]);
        $skuM = ProductSku::factory()->withOptionValues($m)->create(['product_id' => $this->product->id]);

        $skuM->delete(); // soft-delete M combo

        $created = app(ProductSkuService::class)->generateMissingCombinations($this->product);

        $skuM->refresh();
        expect($skuM->deleted_at)->toBeNull();
        expect($created->contains('id', $skuM->id))->toBeTrue();
    });

    it('restores soft-deleted MenuProductSku when its SKU is synced to a branch menu', function () {
        $sku = ProductSku::factory()->create(['product_id' => $this->product->id]);

        $branchMenu = menuForBrand($this->orgId, $this->brand);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $branchMenu->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        // Branch had a price override that was soft-deleted
        $mps = MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'selling_price' => 88000,
            'is_price_overridden' => true,
            'is_active' => true,
        ]);
        $mps->delete();

        app(MenuService::class)->syncNewSkusToMenuBranches($this->product->id, [
            new CatalogSkuProjection($sku->id, (string) $sku->selling_price),
        ]);

        $mps->refresh();
        expect($mps->deleted_at)->toBeNull();
        expect((float) $mps->selling_price)->toBe(88000.0);
        expect($menuProduct->menuProductSkus()->count())->toBe(1); // restored, not duplicated
    });

    it('deactivates duplicate SKUs when multiple share the same remaining options', function () {
        // Simulate legacy data: 2 default SKUs, both with all option values NULL
        // but different option_signature (corrupted data from before observer).
        $sku1 = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
            'option_value2_id' => null,
            'option_value3_id' => null,
        ]);
        // Force-insert a second SKU with a different signature to bypass unique check
        $sku2 = ProductSku::factory()->make([
            'product_id' => $this->product->id,
            'option_value1_id' => null,
            'option_value2_id' => null,
            'option_value3_id' => null,
        ]);
        $sku2->option_signature = 'legacy-dup';
        $sku2->saveQuietly();

        $response = $this->actingAs($this->user)
            ->postJson(expandUrl($this->brand, $this->product), [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
                'default_value_index' => 0,
                'generate_combinations' => false,
            ]);

        $response->assertCreated();

        // First SKU gets updated normally, second is deactivated as duplicate
        $sku1->refresh();
        $sku2->refresh();
        expect($sku1->is_active)->toBeTrue();
        expect($sku2->is_active)->toBeFalse();
    });
});
