<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    // The `Brand::created` hook auto-provisions a "combo" ProductType.
    // Tests in this file assert exact counts, so remove it for a clean slate.
    ProductType::query()->forceDelete();

    $this->actingAs($this->user);
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists product types for the user organization', function () {
        ProductType::factory()->count(3)->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->getJson("{$this->baseUrl}/product-types")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters product types by search', function () {
        ProductType::factory()->create(['organization_id' => $this->orgId, 'name' => 'Beverage', 'brand_id' => $this->brand->id]);
        ProductType::factory()->create(['organization_id' => $this->orgId, 'name' => 'Electronics', 'brand_id' => $this->brand->id]);

        $this->getJson("{$this->baseUrl}/product-types?search=Bever")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beverage');
    });

    it('filters product types by is_active', function () {
        ProductType::factory()->create(['organization_id' => $this->orgId, 'is_active' => true, 'brand_id' => $this->brand->id]);
        ProductType::factory()->create(['organization_id' => $this->orgId, 'is_active' => false, 'brand_id' => $this->brand->id]);

        $this->getJson("{$this->baseUrl}/product-types?is_active=1")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('does not show product types from other organizations', function () {
        $otherOrgId = fake()->uuid();
        ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        ProductType::factory()->create(['organization_id' => $otherOrgId]);

        $this->getJson("{$this->baseUrl}/product-types")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a product type with all fields', function () {
        $payload = [
            'en' => ['name' => 'Drinks'],
            'code' => 'DRK-001',
            'description' => 'All drink items',
            'product_form' => 'physical',
            'has_recipe' => true,
            'is_inventory_tracked' => true,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ];

        $this->postJson("{$this->baseUrl}/product-types", $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Drinks')
            ->assertJsonPath('data.code', 'DRK-001');

        $this->assertDatabaseHas('product_types', [
            'code' => 'DRK-001',
            'organization_id' => $this->orgId,
        ]);
    });

    it('auto-generates code when not provided', function () {
        $this->postJson("{$this->baseUrl}/product-types", [
            'en' => ['name' => 'Auto Code Type'],
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Auto Code Type');

        $productType = ProductType::whereTranslation('name', 'Auto Code Type')->first();

        expect($productType->code)->not->toBeEmpty();
    });

    it('validates that name is required', function () {
        $this->postJson("{$this->baseUrl}/product-types", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ja.name']);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a product type with products_count', function () {
        $productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->getJson("{$this->baseUrl}/product-types/{$productType->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $productType->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'code', 'products_count']]);
    });

    it('returns 403 for a product type from another organization', function () {
        $otherOrgId = fake()->uuid();
        $productType = ProductType::factory()->create(['organization_id' => $otherOrgId]);

        $this->getJson("{$this->baseUrl}/product-types/{$productType->id}")
            ->assertForbidden();
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('removes an explicitly cleared locale without leaving stale translation data', function () {
        $productType = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'en' => ['name' => 'English'],
            'ja' => ['name' => '日本語'],
        ]);

        $this->putJson("{$this->baseUrl}/product-types/{$productType->id}", [
            'en' => null,
            'ja' => ['name' => '日本語'],
        ])->assertOk();

        expect($productType->fresh()->translate('en', false))->toBeNull();
    });

    it('updates name and description', function () {
        $productType = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Old Name',
            'brand_id' => $this->brand->id,
        ]);

        $this->putJson("{$this->baseUrl}/product-types/{$productType->id}", [
            'en' => ['name' => 'New Name', 'description' => 'Updated description'],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    });

    it('cannot change the code via update', function () {
        $productType = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'code' => 'ORIGINAL',
            'brand_id' => $this->brand->id,
        ]);

        $this->putJson("{$this->baseUrl}/product-types/{$productType->id}", [
            'en' => ['name' => 'Same Name'],
            'code' => 'CHANGED',
        ])->assertUnprocessable();

        expect($productType->fresh()->code)->toBe('ORIGINAL');
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('soft deletes a product type and returns 204', function () {
        $productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->deleteJson("{$this->baseUrl}/product-types/{$productType->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('product_types', ['id' => $productType->id]);
    });

    it('cannot delete a product type that has products', function () {
        $productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $this->deleteJson("{$this->baseUrl}/product-types/{$productType->id}")
            ->assertStatus(409);
    });
});

// =========================================================================
//  Restore
// =========================================================================

describe('restore', function () {
    it('restores a soft-deleted product type', function () {
        $productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $productType->delete();

        $this->postJson("{$this->baseUrl}/product-types/{$productType->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $productType->id);

        expect($productType->fresh()->deleted_at)->toBeNull();
    });
});

// =========================================================================
//  Toggle Status
// =========================================================================

describe('toggle status', function () {
    it('toggles the is_active flag', function () {
        $productType = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ]);

        $this->postJson("{$this->baseUrl}/product-types/{$productType->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect($productType->fresh()->is_active)->toBeFalse();
    });
});

// =========================================================================
//  Lookup
// =========================================================================

describe('lookup', function () {
    it('returns only active product types with id, code, and name', function () {
        ProductType::factory()->create(['organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Active', 'brand_id' => $this->brand->id]);
        ProductType::factory()->create(['organization_id' => $this->orgId, 'is_active' => false, 'name' => 'Inactive', 'brand_id' => $this->brand->id]);

        $response = $this->getJson("{$this->baseUrl}/product-types/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0'))->toHaveKeys(['id', 'code', 'name']);
    });
});

// =========================================================================
//  Bulk Delete
// =========================================================================

describe('bulk delete', function () {
    it('deletes multiple product types', function () {
        $types = ProductType::factory()->count(3)->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->postJson("{$this->baseUrl}/product-types/bulk-delete", [
            'ids' => $types->pluck('id')->toArray(),
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 3);

        foreach ($types as $type) {
            $this->assertSoftDeleted('product_types', ['id' => $type->id]);
        }
    });
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/product-types")
        ->assertUnauthorized();
});

// =========================================================================
//  Org Isolation
// =========================================================================

it('returns 403 when updating another org product type', function () {
    $productType = ProductType::factory()->create(['organization_id' => fake()->uuid()]);

    $this->putJson("{$this->baseUrl}/product-types/{$productType->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org product type', function () {
    $productType = ProductType::factory()->create(['organization_id' => fake()->uuid()]);

    $this->deleteJson("{$this->baseUrl}/product-types/{$productType->id}")
        ->assertForbidden();
});

it('does not expose or mutate a sibling-brand product type through this brand route', function () {
    $siblingBrand = Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $siblingBrand->id,
        'name' => 'Sibling brand type',
        'is_active' => true,
    ]);

    $this->getJson("{$this->baseUrl}/product-types/{$productType->id}")->assertNotFound();
    $this->putJson("{$this->baseUrl}/product-types/{$productType->id}", ['en' => ['name' => 'Hacked']])->assertNotFound();
    $this->postJson("{$this->baseUrl}/product-types/{$productType->id}/toggle-status")->assertNotFound();
    $this->deleteJson("{$this->baseUrl}/product-types/{$productType->id}")->assertNotFound();

    $productType->refresh();
    expect($productType->name)->toBe('Sibling brand type')
        ->and($productType->is_active)->toBeTrue()
        ->and($productType->deleted_at)->toBeNull();
});

// =========================================================================
//  404 — Non-existent & Soft-deleted
// =========================================================================

it('returns 404 for non-existent product type', function () {
    $this->getJson("{$this->baseUrl}/product-types/".fake()->uuid())
        ->assertNotFound();
});

it('returns 404 when showing a soft-deleted product type', function () {
    $productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $productType->delete();

    $this->getJson("{$this->baseUrl}/product-types/{$productType->id}")
        ->assertNotFound();
});
