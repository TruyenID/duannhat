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

// =============================================================================
// ProductOption CRUD
// =============================================================================

describe('ProductOption index', function () {
    it('lists options for a product ordered by position', function () {
        ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 2,
        ]);
        ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/options");

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', 'color')
            ->assertJsonPath('data.1.key', 'size');
    });
});

describe('ProductOption create', function () {
    it('creates an option with position', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/options", [
                'key' => 'color',
                'name' => 'Color',
                'position' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.key', 'color')
            ->assertJsonPath('data.position', 1);
    });

    it('rejects duplicate position within same product', function () {
        ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/options", [
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
            ]);

        $response->assertStatus(422);
    });

    it('rejects position outside 1-3 range', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/options", [
                'key' => 'weight',
                'name' => 'Weight',
                'position' => 4,
            ]);

        $response->assertStatus(422);
    });
});

describe('ProductOption update', function () {
    it('updates option name (translatable label)', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}", [
                'name' => 'Colour',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.key', 'color');
    });

    it('allows position change when SKUs exist (sorted signature keeps SKU identity stable)', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $value = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        $sku = ProductSku::factory()->withOptionValues($value)->create([
            'product_id' => $this->product->id,
        ]);
        $signatureBefore = $sku->option_signature;

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}", [
                'position' => 2,
            ]);

        $response->assertOk();

        // Position changed; SKU count and signature unchanged
        expect(ProductSku::where('product_id', $this->product->id)->count())->toBe(1);
        $sku->refresh();
        expect($sku->option_signature)->toBe($signatureBefore);
    });

    it('blocks key change when SKUs exist', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $value = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        ProductSku::factory()->withOptionValues($value)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}", [
                'key' => 'colour',
            ]);

        $response->assertStatus(422);
    });
});

describe('ProductOption delete', function () {
    it('deletes option with no SKU references', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}");

        $response->assertNoContent();
    });

    it('blocks delete when SKUs reference option values', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $value = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        ProductSku::factory()->withOptionValues($value)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}");

        $response->assertStatus(409);
    });
});

// =============================================================================
// ProductOptionValue CRUD
// =============================================================================

describe('ProductOptionValue index', function () {
    it('lists values for an option', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 1,
        ]);

        ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 's', 'position' => 1]);
        ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'm', 'position' => 2]);
        ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'l', 'position' => 3]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}/values");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data');
    });
});

describe('ProductOptionValue create', function () {
    it('creates a value under an option', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}/values", [
                'value' => 'red',
                'label' => 'Red',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.value', 'red');
    });

    it('rejects duplicate slug within the same option with 422', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
            'label' => 'Red',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option->id}/values", [
                'value' => 'red',
                'label' => 'Rouge',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.value.0', 'This slug already exists for the selected option.');
    });

    it('allows same slug under different options', function () {
        $option1 = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $option2 = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 2,
        ]);

        ProductOptionValue::factory()->create([
            'option_id' => $option1->id,
            'value' => 'large',
            'label' => 'Large',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/product-options/{$option2->id}/values", [
                'value' => 'large',
                'label' => 'Large',
            ]);

        $response->assertCreated();
    });
});

describe('ProductOptionValue delete', function () {
    it('blocks delete when SKU references the value', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $value = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        ProductSku::factory()->withOptionValues($value)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/product-option-values/{$value->id}");

        $response->assertStatus(409);
    });

    it('deletes value with no SKU references', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $value = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/product-option-values/{$value->id}");

        $response->assertNoContent();
    });
});

// =============================================================================
// SKU with option values — signature uniqueness
// =============================================================================

describe('SKU option_signature uniqueness', function () {
    it('creates SKU with option values and computes signature', function () {
        $colorOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $sizeOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 2,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $colorOption->id,
            'value' => 'red',
            'label' => 'Red',
        ]);

        $large = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id,
            'value' => 'l',
            'label' => 'Large',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus", [
                'option_value1_id' => $red->id,
                'option_value2_id' => $large->id,
            ]);

        $response->assertCreated();

        $sku = ProductSku::find($response->json('data.id'));
        // Signature is now alphabetically sorted (order-independent)
        $ids = [$red->id, $large->id];
        sort($ids);
        expect($sku->option_signature)->toBe(implode('|', $ids));
    });

    it('rejects duplicate option combination for same product', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        ProductSku::factory()->withOptionValues($red)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus", [
                'option_value1_id' => $red->id,
            ]);

        $response->assertStatus(422);
    });

    it('allows same option values on different products', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $option1 = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $option2 = ProductOption::factory()->create([
            'product_id' => $otherProduct->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $red1 = ProductOptionValue::factory()->create([
            'option_id' => $option1->id,
            'value' => 'red',
        ]);

        $red2 = ProductOptionValue::factory()->create([
            'option_id' => $option2->id,
            'value' => 'red',
        ]);

        ProductSku::factory()->withOptionValues($red1)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$otherProduct->id}/skus", [
                'option_value1_id' => $red2->id,
            ]);

        $response->assertCreated();
    });
});

// =============================================================================
// Preserve SKUs when adding new option
// =============================================================================

describe('preserve existing SKUs when adding new option', function () {
    it('existing SKUs keep NULL for new option position', function () {
        $colorOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $colorOption->id,
            'value' => 'red',
        ]);

        $existingSku = ProductSku::factory()->withOptionValues($red)->create([
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/options", [
                'key' => 'material',
                'name' => 'Material',
                'position' => 2,
            ]);

        $existingSku->refresh();
        expect($existingSku->option_value2_id)->toBeNull()
            ->and($existingSku->option_value1_id)->toBe($red->id);
    });
});
