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

function valueDeleteUrl(Brand $brand, ProductOptionValue $value): string
{
    return "/api/v1/hq/{$brand->slug}/product-option-values/{$value->id}";
}

// =============================================================================
// DELETE /product-option-values/{id}
// =============================================================================

describe('ProductOptionValue delete', function () {
    it('returns 409 with blocking_skus when value is referenced by active SKUs', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);
        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
            'label' => 'Red',
            'position' => 1,
        ]);
        $skuRed = ProductSku::factory()->withOptionValues($red)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $red));

        $response->assertStatus(409)
            ->assertJsonPath('error', 'OPTION_VALUE_IN_USE')
            ->assertJsonCount(1, 'blocking_skus');

        // Value and SKU still exist
        expect(ProductOptionValue::find($red->id))->not->toBeNull();
        expect(ProductSku::find($skuRed->id))->not->toBeNull();
    });

    it('deletes value normally when no SKU references it', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);
        $blue = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'blue',
            'label' => 'Blue',
            'position' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $blue));

        $response->assertNoContent();
        expect(ProductOptionValue::find($blue->id))->toBeNull();
    });

    // -------------------------------------------------------------------------
    // Force-delete flow
    // -------------------------------------------------------------------------

    it('force-deletes value and soft-deletes all referencing active SKUs', function () {
        // Setup: color (pos 1) × size (pos 2) — two SKUs share color=Red but differ in size
        $colorOption = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);
        $sizeOption = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'size', 'position' => 2,
        ]);
        $red = ProductOptionValue::factory()->create([
            'option_id' => $colorOption->id, 'value' => 'red', 'position' => 1,
        ]);
        $s = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 's', 'position' => 1,
        ]);
        $m = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id, 'value' => 'm', 'position' => 2,
        ]);
        $skuRedS = ProductSku::factory()->withOptionValues($red, $s)->create(['product_id' => $this->product->id]);
        $skuRedM = ProductSku::factory()->withOptionValues($red, $m)->create(['product_id' => $this->product->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $red).'?force=true');

        $response->assertNoContent();

        // Value soft-deleted
        expect(ProductOptionValue::withTrashed()->find($red->id)?->deleted_at)->not->toBeNull();

        // Both SKUs soft-deleted
        expect(ProductSku::withTrashed()->find($skuRedS->id)?->deleted_at)->not->toBeNull();
        expect(ProductSku::withTrashed()->find($skuRedM->id)?->deleted_at)->not->toBeNull();
    });

    it('force-delete returns 409 SKU_IN_MENU when linked SKU is still assigned to a menu', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);
        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id, 'value' => 'red', 'position' => 1,
        ]);
        $skuRed = ProductSku::factory()->withOptionValues($red)->create(['product_id' => $this->product->id]);

        $branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
        $branchMenu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $branch->id,
            'is_master' => false,
        ]);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $branchMenu->id,
            'product_id' => $this->product->id,
        ]);
        $mps = MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $skuRed->id,
            'selling_price' => 75000,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $red).'?force=true')
            ->assertStatus(409)
            ->assertJsonPath('error', 'SKU_IN_MENU')
            ->assertJsonCount(1, 'blocking_menus')
            ->assertJsonPath('blocking_menus.0.name', $branchMenu->name);

        // Value and SKU must still exist
        expect(ProductOptionValue::find($red->id))->not->toBeNull();
        expect(ProductSku::find($skuRed->id))->not->toBeNull();
        expect(MenuProductSku::find($mps->id))->not->toBeNull();
    });

    it('force-delete succeeds after SKU is removed from the menu', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);
        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id, 'value' => 'red', 'position' => 1,
        ]);
        $skuRed = ProductSku::factory()->withOptionValues($red)->create(['product_id' => $this->product->id]);

        $branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
        $branchMenu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $branch->id,
            'is_master' => false,
        ]);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $branchMenu->id,
            'product_id' => $this->product->id,
        ]);
        $mps = MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $skuRed->id,
            'selling_price' => 75000,
            'is_active' => true,
        ]);

        // Remove from menu first
        $mps->delete();

        // Now force-delete should succeed
        $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $red).'?force=true')
            ->assertNoContent();

        expect(ProductOptionValue::withTrashed()->find($red->id)?->deleted_at)->not->toBeNull();
        expect(ProductSku::withTrashed()->find($skuRed->id)?->deleted_at)->not->toBeNull();
    });

    it('force-delete is a no-op on SKUs (no error) when value has no referencing SKUs', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);
        $blue = ProductOptionValue::factory()->create([
            'option_id' => $option->id, 'value' => 'blue', 'position' => 1,
        ]);

        $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $blue).'?force=true')
            ->assertNoContent();

        expect(ProductOptionValue::withTrashed()->find($blue->id)?->deleted_at)->not->toBeNull();
    });

    // -------------------------------------------------------------------------
    // Re-create after force-delete (unique constraint + soft-delete conflict)
    // -------------------------------------------------------------------------

    it('restores soft-deleted value when creating with the same slug instead of hitting unique constraint', function () {
        // unique(option_id, value) does not include deleted_at — a soft-deleted
        // row still holds the slot, so a naive INSERT would violate the constraint.
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);
        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id, 'value' => 'red', 'label' => 'Red', 'position' => 1,
        ]);
        ProductSku::factory()->withOptionValues($red)->create(['product_id' => $this->product->id]);

        // Force-delete → value soft-deleted
        $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $red).'?force=true')
            ->assertNoContent();

        expect(ProductOptionValue::withTrashed()->find($red->id)?->deleted_at)->not->toBeNull();

        // Re-create with same slug — must restore, not 500
        $storeUrl = "/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}/values";
        $response = $this->actingAs($this->user)
            ->postJson($storeUrl, [
                'value' => 'red',
                'label' => 'Rouge',
            ]);

        $response->assertStatus(201);

        // Same row restored, label updated
        $red->refresh();
        expect($red->deleted_at)->toBeNull();
        expect($red->label)->toBe('Rouge');

        // Only one row with slug=red exists (no duplicate)
        $count = ProductOptionValue::withTrashed()
            ->where('option_id', $option->id)
            ->where('value', 'red')
            ->count();
        expect($count)->toBe(1);
    });

    it('restores MenuProductSku after remove-from-menu + force-delete + re-create + generate combinations', function () {
        // Product in branch menu: color=Red → 1 SKU, MenuProductSku with price override 80k
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);
        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id, 'value' => 'red', 'label' => 'Red', 'position' => 1,
        ]);
        $sku = ProductSku::factory()->withOptionValues($red)->create(['product_id' => $this->product->id]);

        $branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
        $branchMenu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $branch->id,
            'is_master' => false,
        ]);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $branchMenu->id,
            'product_id' => $this->product->id,
        ]);
        $mps = MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'selling_price' => 80000,
            'is_price_overridden' => true,
            'is_active' => true,
        ]);

        // Step 1: Remove SKU from menu first (required before force-delete)
        $mps->delete();
        expect($mps->fresh()->deleted_at)->not->toBeNull();

        // Step 2: Force-delete Red → value + SKU soft-deleted (no longer blocked by menu)
        $this->actingAs($this->user)
            ->deleteJson(valueDeleteUrl($this->brand, $red).'?force=true')
            ->assertNoContent();

        // Step 3: Re-create Red (restore value)
        $storeUrl = "/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}/values";
        $this->actingAs($this->user)
            ->postJson($storeUrl, ['value' => 'red', 'label' => 'Red'])
            ->assertStatus(201);

        // Step 4: Generate combinations → restore SKU + sync MenuProductSku
        $genUrl = "/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus/generate-combinations";
        $this->actingAs($this->user)
            ->postJson($genUrl)
            ->assertStatus(201);

        // SKU restored
        $sku->refresh();
        expect($sku->deleted_at)->toBeNull();

        // MenuProductSku restored with price override intact
        $mps->refresh();
        expect($mps->deleted_at)->toBeNull();
        expect((float) $mps->selling_price)->toBe(80000.0);
        expect($mps->is_price_overridden)->toBeTrue();
    });
});
