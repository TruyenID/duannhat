<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Database\Seeders\IamSeeder;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->orgId = $this->organization->id;

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
// D1 — org-admin can approve a pending product
// =============================================================================

it('D1: org-admin can approve a pending product', function () {
    $admin = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $admin->assignRole('org-admin', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);

    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($admin)
        ->postJson("{$this->baseUrl}/products/{$product->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');
});

// =============================================================================
// D2 — staff cannot approve (403)
// =============================================================================

it('D2: staff cannot approve a pending product', function () {
    $staff = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $staff->assignRole('staff', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);

    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($staff)
        ->postJson("{$this->baseUrl}/products/{$product->id}/approve")
        ->assertForbidden();
});

// =============================================================================
// D3 — org-admin can reject a pending product
// =============================================================================

it('D3: org-admin can reject a pending product', function () {
    $admin = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $admin->assignRole('org-admin', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);

    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($admin)
        ->postJson("{$this->baseUrl}/products/{$product->id}/reject", [
            'rejection_reason' => 'Missing required information.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'rejected');
});

// =============================================================================
// D4 — staff cannot reject (403)
// =============================================================================

it('D4: staff cannot reject a pending product', function () {
    $staff = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $staff->assignRole('staff', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);

    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($staff)
        ->postJson("{$this->baseUrl}/products/{$product->id}/reject", [
            'rejection_reason' => 'Missing required information.',
        ])
        ->assertForbidden();
});

// =============================================================================
// D2 (TESTS.md) — org-manager can approve product (has catalog.approve in matrix)
// =============================================================================

it('D2-matrix: org-manager can approve a pending product', function () {
    $manager = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $manager->assignRole('org-manager', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);

    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($manager)
        ->postJson("{$this->baseUrl}/products/{$product->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');
});

// =============================================================================
// D4 (TESTS.md) — user with no org role cannot approve
// =============================================================================

it('D4-matrix: user with no role in this org cannot approve', function () {
    $stranger = User::factory()->create();
    // No role assigned in this org — the brand middleware would normally block them,
    // but we test the policy directly by assigning the role in a *different* org.
    $otherOrg = Organization::factory()->create();
    $stranger->assignRole('org-admin', $otherOrg->id);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);

    ProductSku::factory()->create(['product_id' => $product->id]);

    // The brand middleware rejects strangers at 403 before the policy runs.
    $this->actingAs($stranger)
        ->postJson("{$this->baseUrl}/products/{$product->id}/approve")
        ->assertForbidden();
});

// =============================================================================
// D5 — roles WITHOUT catalog.approve cannot approve
// =============================================================================

it('D5: shop-manager cannot approve (no catalog.approve in matrix)', function () {
    $shopManager = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $shopManager->assignRole('shop-manager', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);
    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($shopManager)
        ->postJson("{$this->baseUrl}/products/{$product->id}/approve")
        ->assertForbidden();
});

it('D5b: shop-staff cannot approve (no catalog.approve)', function () {
    $shopStaff = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);
    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($shopStaff)
        ->postJson("{$this->baseUrl}/products/{$product->id}/approve")
        ->assertForbidden();
});

it('D5c: shop-manager cannot reject either (no catalog.approve)', function () {
    $shopManager = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $shopManager->assignRole('shop-manager', $this->orgId);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'status' => 'pending',
        'brand_id' => $this->brand->id,
    ]);
    ProductSku::factory()->create(['product_id' => $product->id]);

    $this->actingAs($shopManager)
        ->postJson("{$this->baseUrl}/products/{$product->id}/reject", [
            'rejection_reason' => 'Nope.',
        ])
        ->assertForbidden();
});
