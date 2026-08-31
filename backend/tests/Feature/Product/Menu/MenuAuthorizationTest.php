<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    (new IamSeeder)->run();

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'slug' => 'auth-test-shop',
        'is_active' => true,
    ]);

    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 100.00,
        'is_active' => true,
    ]);

    // Roles
    $this->adminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );
    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->staffRole = Role::firstOrCreate(
        ['slug' => 'staff'],
        ['name' => 'Staff', 'level' => 10],
    );
    $this->shopManagerRole = Role::firstOrCreate(
        ['slug' => 'shop-manager'],
        ['name' => 'Shop Manager', 'level' => 40],
    );
    $this->shopStaffRole = Role::firstOrCreate(
        ['slug' => 'shop-staff'],
        ['name' => 'Shop Staff', 'level' => 5],
    );

    // Users
    $this->admin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->admin->assignRole($this->adminRole, $this->orgId);

    $this->manager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->manager->assignRole($this->managerRole, $this->orgId);

    $this->staff = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->staff->assignRole($this->staffRole, $this->orgId);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
    $this->shopBase = "/api/v1/shops/{$this->shop->slug}";

    // Master menu for HQ tests
    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->admin->id,
        'master_menu_id' => null,
    ]);

    $this->masterMp = MenuProduct::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    // Branch menu for shop tests
    $this->branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $this->masterMenu->id,
    ]);

    $this->branchMp = MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
        'master_menu_product_id' => $this->masterMp->id,
    ]);

    $this->branchMpSku = MenuProductSku::factory()->create([
        'menu_product_id' => $this->branchMp->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 100.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);
});

// =============================================================================
// HQ — org-admin
// =============================================================================

it('allows org-admin to perform HQ menu operations in their own brand', function () {
    // Create
    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/menus", [
            'name' => 'Admin Menu',
            'status' => 'Draft',
            'product_ids' => [$this->product->id],
        ]);

    $response->assertCreated();

    $menuId = $response->json('data.id');

    // Update
    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/menus/{$menuId}", [
            'name' => 'Updated Admin Menu',
        ])
        ->assertSuccessful();

    // Show
    $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/menus/{$menuId}")
        ->assertSuccessful();

    // Delete (draft status)
    $this->actingAs($this->admin)
        ->deleteJson("{$this->baseUrl}/menus/{$menuId}")
        ->assertNoContent();
});

// =============================================================================
// HQ — org-manager
// =============================================================================

it('allows org-manager to create and edit menus in their brand', function () {
    // Create
    $response = $this->actingAs($this->manager)
        ->postJson("{$this->baseUrl}/menus", [
            'name' => 'Manager Menu',
            'status' => 'Draft',
            'product_ids' => [$this->product->id],
        ]);

    $response->assertCreated();

    $menuId = $response->json('data.id');

    // Update
    $this->actingAs($this->manager)
        ->putJson("{$this->baseUrl}/menus/{$menuId}", [
            'name' => 'Updated Manager Menu',
        ])
        ->assertSuccessful();
});

// =============================================================================
// HQ — staff forbidden
// =============================================================================

it('forbids staff from creating a menu', function () {
    $response = $this->actingAs($this->staff)
        ->postJson("{$this->baseUrl}/menus", [
            'name' => 'Staff Menu',
            'status' => 'Draft',
        ]);

    $response->assertForbidden();
});

// =============================================================================
// HQ — cross-organization
// =============================================================================

it('forbids org-admin from org-A accessing a menu in org-B', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);

    $otherUser = User::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);
    $otherAdminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );
    $otherUser->assignRole($otherAdminRole, $otherOrgId);

    // Other brand for the URL (must exist for route resolution)
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);

    // Try to view our menu via the other brand's URL
    $this->actingAs($otherUser)
        ->getJson("/api/v1/hq/{$this->brand->slug}/menus/{$this->masterMenu->id}")
        ->assertForbidden();
});

// =============================================================================
// Unauthenticated
// =============================================================================

it('returns 401 for unauthenticated requests', function () {
    $this->getJson("{$this->baseUrl}/menus")->assertUnauthorized();
    $this->postJson("{$this->baseUrl}/menus", ['name' => 'Test'])->assertUnauthorized();
    $this->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}")->assertUnauthorized();
});

// =============================================================================
// Shop — staff toggle availability
// =============================================================================

it('allows shop staff to toggle product and SKU availability', function () {
    $shopStaff = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $shopStaff->assignRole($this->shopStaffRole, $this->orgId, $this->shop->id);

    // Toggle product
    $this->actingAs($shopStaff)
        ->postJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/toggle")
        ->assertSuccessful();

    // Toggle SKU
    $this->actingAs($shopStaff)
        ->postJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/toggle")
        ->assertSuccessful();
});

// =============================================================================
// Shop — staff forbidden from price override
// =============================================================================

it('forbids shop staff from overriding SKU price', function () {
    $shopStaff = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $shopStaff->assignRole($this->shopStaffRole, $this->orgId, $this->shop->id);

    $this->actingAs($shopStaff)
        ->postJson(
            "{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/price",
            ['selling_price' => 200.00],
        )
        ->assertForbidden();
});

// =============================================================================
// Shop — manager price override
// =============================================================================

it('allows shop manager to override SKU price', function () {
    $shopManager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $shopManager->assignRole($this->shopManagerRole, $this->orgId, $this->shop->id);

    $this->actingAs($shopManager)
        ->postJson(
            "{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/price",
            ['selling_price' => 200.00],
        )
        ->assertSuccessful();
});

it('allows a Platform tempo-admin with full permissions to manage a shop menu', function () {
    $tempoAdminRole = Role::firstOrCreate(
        [
            'slug' => 'tempo-admin',
            'console_organization_id' => $this->orgId,
        ],
        [
            'name' => 'Tempo Admin',
            'level' => 100,
        ],
    );
    $iamPermission = Permission::firstOrCreate(
        ['slug' => 'iam.permissions'],
        ['name' => 'Manage IAM permissions'],
    );
    $tempoAdminRole->permissions()->sync([$iamPermission->id]);

    $tempoAdmin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $tempoAdmin->assignRole($tempoAdminRole, $this->orgId);

    $this->actingAs($tempoAdmin)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}?compact=1")
        ->assertSuccessful();

    $this->actingAs($tempoAdmin)
        ->postJson(
            "{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/price",
            ['selling_price' => 200.00],
        )
        ->assertSuccessful();
});

it('allows a Platform tempo-admin to manage HQ menus by permission and honors revocation', function () {
    $tempoAdminRole = Role::create([
        'slug' => 'tempo-admin',
        'name' => 'Tempo Admin',
        'level' => 100,
        'console_organization_id' => $this->orgId,
    ]);
    $tempoAdminRole->permissions()->sync(
        Permission::query()
            ->whereIn('slug', ['menu.view', 'menu.manage', 'menu.publish'])
            ->pluck('id'),
    );
    $tempoAdmin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $tempoAdmin->assignRole($tempoAdminRole, $this->orgId);

    $this->actingAs($tempoAdmin)
        ->postJson("{$this->baseUrl}/menus", [
            'name' => 'Platform Admin Menu',
            'status' => 'Draft',
        ])
        ->assertCreated();

    $tempoAdminRole->permissions()->detach(
        Permission::query()->where('slug', 'menu.manage')->value('id'),
    );

    $this->actingAs($tempoAdmin)
        ->postJson("{$this->baseUrl}/menus", [
            'name' => 'Revoked Menu',
            'status' => 'Draft',
        ])
        ->assertForbidden();
});

// =============================================================================
// Shop — manager price reset
// =============================================================================

it('allows shop manager to reset SKU price', function () {
    $shopManager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $shopManager->assignRole($this->shopManagerRole, $this->orgId, $this->shop->id);

    $this->branchMpSku->update([
        'selling_price' => 200.00,
        'is_price_overridden' => true,
    ]);

    $this->actingAs($shopManager)
        ->postJson(
            "{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/reset-price",
        )
        ->assertSuccessful();
});

// =============================================================================
// Shop — organization id-space mismatch (regression)
// =============================================================================

/**
 * Regression test for the production pairing where `organizations.id` (local
 * ULID) is distinct from `organizations.console_organization_id` (shadow UUID
 * from the SSO console). The policy must compare org identity through the
 * model's `organization` relation, not by comparing `user->console_org_id`
 * directly to `model->organization_id`.
 */
it('allows shop manager to view branch menu when local org.id differs from console_organization_id', function () {
    $consoleOrgId = (string) Str::uuid();
    $localOrgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $localOrgId,
        'console_organization_id' => $consoleOrgId,
    ]);

    $brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrgId,
    ]);

    $shop = Branch::factory()->create([
        'console_organization_id' => $consoleOrgId,
        'console_brand_id' => $brand->id,
        'slug' => 'mismatched-org-shop',
        'is_active' => true,
    ]);

    $masterMenu = Menu::factory()->create([
        'organization_id' => $localOrgId,
        'brand_id' => $brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);

    $branchMenu = Menu::factory()->create([
        'organization_id' => $localOrgId,
        'brand_id' => $brand->id,
        'branch_id' => $shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $masterMenu->id,
    ]);

    $shopManager = User::factory()->create([
        'console_organization_id' => $consoleOrgId,
    ]);
    // Use local org ID (not console ID) — role_user_pivots.organization_id is a local FK.
    $shopManager->assignRole($this->shopManagerRole, $localOrgId, $shop->id);

    $this->actingAs($shopManager)
        ->getJson("/api/v1/shops/{$shop->slug}/menus/{$branchMenu->id}")
        ->assertSuccessful();
});

// =============================================================================
// Shop — wrong branch returns 404
// =============================================================================

it('returns 404 when accessing shop menu from wrong branch', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);

    $shopManager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $shopManager->assignRole($this->shopManagerRole, $this->orgId, $otherShop->id);

    // branchMenu belongs to $this->shop, but we access via $otherShop slug
    // resolveMenu scopes by branch_id = shop.id, so it should 404
    $this->actingAs($shopManager)
        ->getJson("/api/v1/shops/{$otherShop->slug}/menus/{$this->branchMenu->id}")
        ->assertNotFound();
});
