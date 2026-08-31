<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Database\Seeders\IamSeeder;

beforeEach(function () {
    $this->seed(IamSeeder::class);

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

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
// Submit for Approval
// =============================================================================

describe('submit for approval', function () {
    it('transitions a draft product to pending when it has variants', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/submit-for-approval");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'pending');
    });

    it('fails when product has no variants', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        // Ensure no variants exist
        $product->skus()->delete();

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/submit-for-approval");

        $response->assertInternalServerError();
    });

    it('fails for a non-draft product', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'active',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/submit-for-approval");

        $response->assertUnprocessable();
    });
});

// =============================================================================
// Approve
// =============================================================================

describe('approve', function () {
    it('transitions a pending product to approved by a different user', function () {
        $creator = $this->user;

        $approver = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        grantOrgAccess($approver, $this->orgId);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'pending',
            'created_by_id' => $creator->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($approver)
            ->postJson("{$this->baseUrl}/products/{$product->id}/approve");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'approved');
    });

    it('allows the creator to self-approve a product', function () {
        // HQ workflow does NOT enforce maker/checker separation — the user
        // who created a product can also approve it. Maker/checker lives at
        // the tenant / policy layer, not inside the service.
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'pending',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/approve");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by_id', $this->user->id);
    });

    it('fails for a non-pending product', function () {
        $approver = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        grantOrgAccess($approver, $this->orgId);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($approver)
            ->postJson("{$this->baseUrl}/products/{$product->id}/approve");

        $response->assertUnprocessable();
    });
});

// =============================================================================
// Reject
// =============================================================================

describe('reject', function () {
    it('transitions a pending product to rejected with a reason', function () {
        $rejector = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        grantOrgAccess($rejector, $this->orgId);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'pending',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($rejector)
            ->postJson("{$this->baseUrl}/products/{$product->id}/reject", [
                'rejection_reason' => 'Missing product images.',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'rejected');
    });

    it('fails without a rejection reason', function () {
        $rejector = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        grantOrgAccess($rejector, $this->orgId);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'pending',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($rejector)
            ->postJson("{$this->baseUrl}/products/{$product->id}/reject", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason']);
    });
});

// =============================================================================
// Activate
// =============================================================================

describe('activate', function () {
    it('transitions an approved product to active', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'approved',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/activate");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'active');
    });

    it('transitions an inactive product to active', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'inactive',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/activate");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'active');
    });
});

// =============================================================================
// Deactivate
// =============================================================================

describe('deactivate', function () {
    it('transitions an active product to inactive', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'active',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/deactivate");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'inactive');
    });

    it('fails for a non-active product', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/deactivate");

        $response->assertUnprocessable();
    });
});

// =============================================================================
// Authentication
// =============================================================================

it('returns 401 when not authenticated for submit-for-approval', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
        'created_by_id' => $this->user->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->postJson("{$this->baseUrl}/products/{$product->id}/submit-for-approval")
        ->assertUnauthorized();
});

// =============================================================================
// 404 — Non-existent & Soft-deleted
// =============================================================================

it('returns 404 for non-existent product workflow action', function () {
    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/products/".fake()->uuid().'/submit-for-approval')
        ->assertNotFound();
});

it('returns 404 when acting on a soft-deleted product', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
        'created_by_id' => $this->user->id,
        'brand_id' => $this->brand->id,
    ]);
    $product->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/products/{$product->id}/submit-for-approval")
        ->assertNotFound();
});
