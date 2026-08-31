<?php

/**
 * #130 P2 — UserContextController coverage
 *
 * Endpoints under test:
 *   GET /api/v1/me/context  — user profile + accessible brand/shop counts
 *   GET /api/v1/me/brands   — paginated brand list scoped to user org
 *   GET /api/v1/me/shops    — paginated shop list scoped to user org
 *
 * The controller is the entry point for admin-web's brand/shop selector
 * after login — broken auth guard or wrong scope here = user sees the
 * wrong tenant's data. Worth heavy coverage.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use App\Support\Iam\RoleTemplateMatrix;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

// =============================================================================
// /me/context
// =============================================================================

it('returns user profile + brand/shop counts on /me/context', function () {
    Brand::factory()->count(2)->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Branch::factory()->count(3)->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/context')
        ->assertOk()
        ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'brand_count', 'shop_count'])
        ->assertJsonPath('brand_count', 2)
        ->assertJsonPath('shop_count', 3);
});

it('excludes inactive brands and shops from counts', function () {
    Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => false]);
    Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => false]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/context')
        ->assertOk()
        ->assertJsonPath('brand_count', 1)
        ->assertJsonPath('shop_count', 1);
});

it('returns zero counts for a user with no accessible org', function () {
    // Fresh user without grantOrgAccess — no IAM linkage.
    $loner = User::factory()->create(['console_organization_id' => $this->orgId]);

    $this->actingAs($loner)
        ->getJson('/api/v1/me/context')
        ->assertOk()
        ->assertJsonPath('brand_count', 0)
        ->assertJsonPath('shop_count', 0);
});

it('returns 401 on /me/context without auth', function () {
    $this->getJson('/api/v1/me/context')->assertUnauthorized();
});

// =============================================================================
// /me/brands
// =============================================================================

it('paginates brands scoped to the user org', function () {
    Brand::factory()->count(30)->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/brands?per_page=10')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta'])
        ->assertJsonCount(10, 'data');
});

it('caps per_page at 100', function () {
    Brand::factory()->count(5)->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/v1/me/brands?per_page=500');
    $response->assertOk();
    expect((int) $response->json('meta.per_page'))->toBe(100);
});

it('does not include brands from another org', function () {
    Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Mine']);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    Brand::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true, 'name' => 'Theirs']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/me/brands');
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Mine')->not->toContain('Theirs');
});

it('filters brands by search on name', function () {
    Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Acme Coffee']);
    Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Beta Tea']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/me/brands?search=Acme');
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Acme Coffee')->not->toContain('Beta Tea');
});

it('returns empty data for user with no org access', function () {
    $loner = User::factory()->create(['console_organization_id' => $this->orgId]);

    $this->actingAs($loner)
        ->getJson('/api/v1/me/brands')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('returns 401 on /me/brands without auth', function () {
    $this->getJson('/api/v1/me/brands')->assertUnauthorized();
});

// =============================================================================
// /me/shops
// =============================================================================

it('paginates shops scoped to the user org', function () {
    Branch::factory()->count(15)->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/shops?per_page=5')
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

it('does not include shops from another org', function () {
    Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true, 'name' => 'My Shop']);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true, 'name' => 'Their Shop']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/me/shops');
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('My Shop')->not->toContain('Their Shop');
});

it('limits a branch-scoped user to the assigned shop without exposing an HQ brand workspace', function () {
    $assignedBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'Assigned Brand',
    ]);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'Other Brand',
    ]);
    $assignedShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $assignedBrand->console_brand_id,
        'is_active' => true,
        'name' => 'Assigned Shop',
    ]);
    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'is_active' => true,
        'name' => 'Other Shop',
    ]);
    $branchUser = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $role = Role::query()->firstOrCreate(
        ['slug' => 'branch-test-role'],
        ['name' => 'Branch Test Role', 'level' => 10],
    );
    $branchUser->assignRole($role, $this->orgId, $assignedShop->id);

    $this->actingAs($branchUser)
        ->getJson('/api/v1/me/context')
        ->assertOk()
        ->assertJsonPath('brand_count', 0)
        ->assertJsonPath('shop_count', 1);

    $brands = $this->actingAs($branchUser)->getJson('/api/v1/me/brands')->assertOk();
    expect($brands->json('data'))->toBe([]);

    $shops = $this->actingAs($branchUser)->getJson('/api/v1/me/shops')->assertOk();
    expect(collect($shops->json('data'))->pluck('name')->all())->toBe(['Assigned Shop']);
});

it('does not expose HQ brands or allow HQ mutations to an org-wide production tempo-staff role', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
    $role = Role::create([
        'slug' => 'tempo-staff',
        'name' => 'Tempo Staff',
        'level' => 10,
        'console_organization_id' => $this->orgId,
    ]);
    $role->permissions()->sync(
        Permission::query()
            ->whereIn('slug', RoleTemplateMatrix::for('shop-staff'))
            ->pluck('id'),
    );
    $staff = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $staff->assignRole($role, $this->orgId);

    $this->actingAs($staff)
        ->getJson('/api/v1/me/context')
        ->assertOk()
        ->assertJsonPath('brand_count', 0)
        ->assertJsonPath('shop_count', 1);

    $this->actingAs($staff)
        ->getJson('/api/v1/me/brands')
        ->assertOk()
        ->assertJsonPath('data', []);

    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $brand->id,
    ]);
    $this->actingAs($staff)
        ->postJson("/api/v1/hq/{$brand->slug}/products", [
            'product_type_id' => $productType->id,
            'ja' => ['name' => '禁止商品'],
        ])
        ->assertForbidden();
});

it('returns 401 on /me/shops without auth', function () {
    $this->getJson('/api/v1/me/shops')->assertUnauthorized();
});
