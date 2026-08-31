<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Models\VariantUnit;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

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

    $this->variant = ProductSku::factory()->create([
        'product_id' => $this->product->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
// Authentication
// =============================================================================

describe('authentication', function () {
    it('returns 401 for unauthenticated requests', function () {
        $this->getJson("{$this->baseUrl}/skus/{$this->variant->id}/units")
            ->assertUnauthorized();
    });
});

// =============================================================================
// Index
// =============================================================================

describe('index', function () {
    it('lists units for a variant', function () {
        VariantUnit::factory()->count(3)->create([
            'product_sku_id' => $this->variant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/skus/{$this->variant->id}/units");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data');
    });

    it('returns 403 for a variant from another organization', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $otherVariant = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/skus/{$otherVariant->id}/units");

        $response->assertForbidden();
    });
});

// =============================================================================
// Store
// =============================================================================

describe('store', function () {
    it('auto-promotes the first unit to base', function () {
        $response = $this->actingAs($this->user)->postJson("{$this->baseUrl}/skus/{$this->variant->id}/units", [
            'unit' => 'piece', 'ratio' => 1, 'sku' => 'FIRST-UNIT', 'is_base' => false,
        ])->assertCreated()->assertJsonPath('data.is_base', true);

        expect(VariantUnit::findOrFail($response->json('data.id'))->is_base)->toBeTrue();
    });

    it('keeps at most one base unit when a new base is created', function () {
        $oldBase = VariantUnit::factory()->create(['product_sku_id' => $this->variant->id, 'is_base' => true]);

        $this->actingAs($this->user)->postJson("{$this->baseUrl}/skus/{$this->variant->id}/units", [
            'unit' => 'box', 'ratio' => 2, 'sku' => 'BOX-BASE', 'is_base' => true,
        ])->assertCreated();

        expect($oldBase->fresh()->is_base)->toBeFalse()
            ->and(VariantUnit::where('product_sku_id', $this->variant->id)->where('is_base', true)->count())->toBe(1);
    });

    it('creates a unit for a variant', function () {
        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/skus/{$this->variant->id}/units", [
                'unit' => 'kg',
                'ratio' => 1.0,
                'sku' => 'UNIT-KG-001',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.unit', 'kg')
            ->assertJsonPath('data.sku', 'UNIT-KG-001');
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/skus/{$this->variant->id}/units", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['unit', 'sku', 'ratio']);
    });
});

// =============================================================================
// Show
// =============================================================================

describe('show', function () {
    it('shows a unit', function () {
        $unit = VariantUnit::factory()->create([
            'product_sku_id' => $this->variant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/sku-units/{$unit->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $unit->id);
    });

    it('returns 404 for a non-existent unit', function () {
        $fakeId = (string) Str::uuid();

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/sku-units/{$fakeId}");

        $response->assertNotFound();
    });

    it('returns 403 for a unit from another organization', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $otherVariant = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);
        $otherUnit = VariantUnit::factory()->create([
            'product_sku_id' => $otherVariant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/sku-units/{$otherUnit->id}");

        $response->assertForbidden();
    });
});

// =============================================================================
// Update
// =============================================================================

describe('update', function () {
    it('refuses to unset the current base directly', function () {
        $unit = VariantUnit::factory()->create(['product_sku_id' => $this->variant->id, 'is_base' => true]);

        $this->actingAs($this->user)->putJson("{$this->baseUrl}/sku-units/{$unit->id}", ['is_base' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_base');

        expect($unit->fresh()->is_base)->toBeTrue();
    });

    it('updates a unit', function () {
        $unit = VariantUnit::factory()->create([
            'product_sku_id' => $this->variant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("{$this->baseUrl}/sku-units/{$unit->id}", [
                'unit' => 'lbs',
                'ratio' => 2.2,
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.unit', 'lbs');
    });

    it('returns 403 when updating a unit from another organization', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $otherVariant = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);
        $otherUnit = VariantUnit::factory()->create([
            'product_sku_id' => $otherVariant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("{$this->baseUrl}/sku-units/{$otherUnit->id}", [
                'unit' => 'hacked',
            ]);

        $response->assertForbidden();
    });
});

// =============================================================================
// Destroy
// =============================================================================

describe('destroy', function () {
    it('refuses to delete the current base unit', function () {
        $unit = VariantUnit::factory()->create(['product_sku_id' => $this->variant->id, 'is_base' => true]);

        $this->actingAs($this->user)->deleteJson("{$this->baseUrl}/sku-units/{$unit->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('unit');

        expect(VariantUnit::find($unit->id))->not->toBeNull();
    });

    it('deletes a unit', function () {
        VariantUnit::factory()->create([
            'product_sku_id' => $this->variant->id,
            'is_base' => true,
        ]);
        $unit = VariantUnit::factory()->create([
            'product_sku_id' => $this->variant->id,
            'is_base' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->baseUrl}/sku-units/{$unit->id}");

        $response->assertNoContent();

        expect(VariantUnit::find($unit->id))->toBeNull();
    });

    it('returns 403 when deleting a unit from another organization', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $otherVariant = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);
        $otherUnit = VariantUnit::factory()->create([
            'product_sku_id' => $otherVariant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->baseUrl}/sku-units/{$otherUnit->id}");

        $response->assertForbidden();
    });
});

// =============================================================================
// Set Base
// =============================================================================

describe('setBase', function () {
    it('sets a unit as base and unsets the previous base', function () {
        $oldBase = VariantUnit::factory()->create([
            'product_sku_id' => $this->variant->id,
            'is_base' => true,
        ]);
        $newBase = VariantUnit::factory()->create([
            'product_sku_id' => $this->variant->id,
            'is_base' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/sku-units/{$newBase->id}/set-base");

        $response->assertSuccessful()
            ->assertJsonPath('data.is_base', true);

        expect($oldBase->fresh()->is_base)->toBeFalse()
            ->and($newBase->fresh()->is_base)->toBeTrue();
    });

    it('returns 403 for a unit from another organization', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $otherVariant = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);
        $otherUnit = VariantUnit::factory()->create([
            'product_sku_id' => $otherVariant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/sku-units/{$otherUnit->id}/set-base");

        $response->assertForbidden();
    });
});
