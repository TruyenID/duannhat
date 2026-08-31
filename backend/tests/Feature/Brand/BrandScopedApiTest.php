<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IamSeeder;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    // Create a local Organization record (IAM source of truth).
    $this->organization = Organization::factory()->create();
    $this->orgId = $this->organization->id;

    $this->user = User::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    // Grant the user access to this org via IAM.
    $role = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100]
    );
    $this->user->assignRole($role, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'slug' => 'phuc-long',
        'name' => 'Phuc Long',
    ]);

    $this->otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'slug' => 'highlands',
        'name' => 'Highlands',
    ]);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
});

// =========================================================================
//  Brand slug resolution
// =========================================================================

it('resolves brand from slug and lists products', function () {
    Product::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/hq/phuc-long/products');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'status']],
            'meta' => ['current_page', 'total'],
        ]);
});

it('returns 404 for non-existent brand slug', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/hq/non-existent/products');

    $response->assertNotFound();
});

it('returns 403 for brand from another organization', function () {
    $otherOrg = Organization::factory()->create();

    Brand::factory()->create([
        'console_organization_id' => $otherOrg->console_organization_id,
        'slug' => 'other-brand',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/hq/other-brand/products');

    $response->assertForbidden();
});

it('creates a product via brand-scoped route', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/hq/phuc-long/products', [
            'product_type_id' => $this->productType->id,
            'name' => 'Black Coffee',
            'brand_id' => $this->brand->id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Black Coffee')
        ->assertJsonPath('data.brand_id', $this->brand->id);
});

it('lists categories via brand-scoped route', function () {
    Category::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/hq/phuc-long/categories');

    $response->assertOk();
});

// =========================================================================
//  Brand middleware — authentication & authorization
// =========================================================================

it('requires authentication for brand-scoped routes', function () {
    $response = $this->getJson('/api/v1/hq/phuc-long/products');

    $response->assertUnauthorized();
});

it('returns 404 for inactive brand', function () {
    Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'inactive-brand',
        'is_active' => false,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/hq/inactive-brand/products');

    $response->assertNotFound();
});

// =========================================================================
//  Brand model
// =========================================================================

it('creates a brand via factory', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Test Brand',
        'slug' => 'test-brand',
    ]);

    expect($brand->name)->toBe('Test Brand')
        ->and($brand->slug)->toBe('test-brand')
        ->and($brand->is_active)->toBeTrue()
        ->and($brand->console_organization_id)->toBe($this->orgId);
});

it('has products relationship', function () {
    Product::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);

    expect($this->brand->products)->toHaveCount(3);
});

it('has categories relationship', function () {
    Category::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    expect($this->brand->categories)->toHaveCount(2);
});

it('soft deletes brand', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $brand->delete();

    $this->assertSoftDeleted('brands', ['id' => $brand->id]);
});
