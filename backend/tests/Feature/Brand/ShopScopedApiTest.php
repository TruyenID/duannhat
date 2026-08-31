<?php

use App\Models\Branch;
use App\Models\MaterialBatch;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->shop = Branch::create([
        'console_branch_id' => fake()->uuid(),
        'console_organization_id' => $this->orgId,
        'slug' => 'main-shop',
        'name' => 'Main Shop',
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
    ]);
});

// =========================================================================
//  Shop slug resolution
// =========================================================================

it('resolves shop from slug and lists stock transactions', function () {
    StockTransaction::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/shops/main-shop/stock-transactions');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'total'],
        ]);
});

it('returns 404 for non-existent shop slug', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/shops/non-existent-shop/stock-transactions');

    $response->assertNotFound();
});

it('returns 403 for shop from another organization', function () {
    Branch::create([
        'console_branch_id' => fake()->uuid(),
        'console_organization_id' => 'org-other',
        'slug' => 'other-org-shop',
        'name' => 'Other Org Shop',
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/shops/other-org-shop/stock-transactions');

    $response->assertForbidden();
});

it('returns 404 for inactive shop', function () {
    Branch::create([
        'console_branch_id' => fake()->uuid(),
        'console_organization_id' => $this->orgId,
        'slug' => 'inactive-shop',
        'name' => 'Inactive Shop',
        'is_active' => false,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/shops/inactive-shop/stock-transactions');

    $response->assertNotFound();
});

// =========================================================================
//  Authentication
// =========================================================================

it('requires authentication for shop-scoped routes', function () {
    $response = $this->getJson('/api/v1/shops/main-shop/stock-transactions');

    $response->assertUnauthorized();
});

// =========================================================================
//  Stock Levels via shop-scoped route
// =========================================================================

it('can list stock levels via shop-scoped route', function () {
    StockLevel::factory()->count(2)->create([
        'warehouse_id' => $this->warehouse->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/shops/main-shop/stock-levels');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'total'],
        ]);
});

// =========================================================================
//  Warehouses via shop-scoped route
// =========================================================================

it('can list warehouses via shop-scoped route', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/shops/main-shop/warehouses');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'total'],
        ]);
});

// =========================================================================
//  Material Batches via shop-scoped route
// =========================================================================

it('can list material batches via shop-scoped route', function () {
    MaterialBatch::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/shops/main-shop/material-batches');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'total'],
        ]);
});
