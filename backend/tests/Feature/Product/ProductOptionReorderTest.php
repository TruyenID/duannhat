<?php

use App\Models\Brand;
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

function optionUrl(Brand $brand, ProductOption $option): string
{
    return "/api/v1/hq/{$brand->slug}/product-options/{$option->id}";
}

// =============================================================================
// Position reorder — swap without SKU recreation
// =============================================================================

describe('ProductOption position reorder', function () {
    it('swaps two option positions atomically without creating new SKUs', function () {
        // Color at position 1, Size at position 2 — 4 SKUs (Red/S, Red/L, Blue/S, Blue/L)
        $color = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'name' => 'Color',
            'position' => 1,
        ]);
        $size = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'name' => 'Size',
            'position' => 2,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $color->id, 'value' => 'red', 'label' => 'Red', 'position' => 1,
        ]);
        $blue = ProductOptionValue::factory()->create([
            'option_id' => $color->id, 'value' => 'blue', 'label' => 'Blue', 'position' => 2,
        ]);
        $small = ProductOptionValue::factory()->create([
            'option_id' => $size->id, 'value' => 's', 'label' => 'S', 'position' => 1,
        ]);
        $large = ProductOptionValue::factory()->create([
            'option_id' => $size->id, 'value' => 'l', 'label' => 'L', 'position' => 2,
        ]);

        // Red/S, Red/L, Blue/S, Blue/L
        ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => $red->id,
            'option_value2_id' => $small->id,
        ]);
        ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => $red->id,
            'option_value2_id' => $large->id,
        ]);
        ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => $blue->id,
            'option_value2_id' => $small->id,
        ]);
        ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => $blue->id,
            'option_value2_id' => $large->id,
        ]);

        $initialCount = ProductSku::where('product_id', $this->product->id)->count();
        $initialSignatures = ProductSku::where('product_id', $this->product->id)
            ->pluck('option_signature')
            ->sort()
            ->values()
            ->all();

        // Move Color from position 1 to position 2 — should swap with Size
        $response = $this->actingAs($this->user)
            ->putJson(optionUrl($this->brand, $color), ['position' => 2]);

        $response->assertOk();

        // SKU count unchanged
        $afterCount = ProductSku::where('product_id', $this->product->id)->count();
        expect($afterCount)->toBe($initialCount);

        // Signatures are order-independent, so they must be identical
        $afterSignatures = ProductSku::where('product_id', $this->product->id)
            ->pluck('option_signature')
            ->sort()
            ->values()
            ->all();
        expect($afterSignatures)->toBe($initialSignatures);

        // Positions swapped on both options
        $color->refresh();
        $size->refresh();
        expect($color->position)->toBe(2)
            ->and($size->position)->toBe(1);
    });

    it('swaps SKU slots so position N still reads option_valueN_id after reorder', function () {
        $color = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);
        $size = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'size', 'position' => 2,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $color->id, 'value' => 'red', 'label' => 'Red', 'position' => 1,
        ]);
        $small = ProductOptionValue::factory()->create([
            'option_id' => $size->id, 'value' => 's', 'label' => 'S', 'position' => 1,
        ]);

        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => $red->id,   // Color at slot 1
            'option_value2_id' => $small->id, // Size at slot 2
        ]);

        // Move Color from position 1 → 2 (Size goes 2 → 1)
        $this->actingAs($this->user)
            ->putJson(optionUrl($this->brand, $color), ['position' => 2])
            ->assertOk();

        $sku->refresh();

        // After swap: Size is at position 1 → slot 1, Color is at position 2 → slot 2
        expect($sku->option_value1_id)->toBe($small->id)
            ->and($sku->option_value2_id)->toBe($red->id);
    });

    it('allows moving to a vacant position without touching SKU slots for that position', function () {
        // Only one option at position 1; move it to position 3 (vacant)
        $color = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $color->id, 'value' => 'red', 'label' => 'Red', 'position' => 1,
        ]);

        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => $red->id,
            'option_value2_id' => null,
            'option_value3_id' => null,
        ]);

        $this->actingAs($this->user)
            ->putJson(optionUrl($this->brand, $color), ['position' => 3])
            ->assertOk();

        $color->refresh();
        $sku->refresh();

        expect($color->position)->toBe(3);
        // Slot 1 is now null, slot 3 holds red (swapped)
        expect($sku->option_value1_id)->toBeNull()
            ->and($sku->option_value3_id)->toBe($red->id);
    });

    it('rejects position outside 1-3', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);

        $this->actingAs($this->user)
            ->putJson(optionUrl($this->brand, $option), ['position' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('position');
    });

    it('renaming a value label does not change SKU signature', function () {
        $color = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $color->id, 'value' => 'red', 'label' => 'Red', 'position' => 1,
        ]);

        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
            'option_value1_id' => $red->id,
        ]);

        $signatureBefore = $sku->option_signature;

        // Only rename the display label — slug (value) stays "red" because
        // changing the slug is blocked when SKUs reference the value.
        $this->actingAs($this->user)
            ->putJson("/api/v1/hq/{$this->brand->slug}/product-option-values/{$red->id}", [
                'label' => 'Crimson',
            ])
            ->assertOk();

        $sku->refresh();
        expect($sku->option_signature)->toBe($signatureBefore);
    });
});
