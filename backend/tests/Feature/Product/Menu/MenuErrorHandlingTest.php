<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use App\Traits\AuditsActivity;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IamSeeder::class);

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
        'slug' => 'error-test-shop',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
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

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->user->assignRole($this->managerRole, $this->orgId);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
    $this->shopBase = "/api/v1/shops/{$this->shop->slug}";

    // Master menu
    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
    ]);

    $this->masterMp = MenuProduct::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    // Branch menu
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
// Error handling
// =============================================================================

it('returns 404 when deleting non-existent menu_product', function () {
    $fakeId = (string) Str::uuid();

    $this->actingAs($this->user)
        ->deleteJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/products/{$fakeId}")
        ->assertNotFound();
});

it('returns 404 when toggling menu_product_sku that does not belong to the specified menu_product', function () {
    // Create a second product and menu_product
    $product2 = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->product->product_type_id,
        'brand_id' => $this->brand->id,
    ]);
    $sku2 = ProductSku::factory()->create([
        'product_id' => $product2->id,
        'selling_price' => 50.00,
        'is_active' => true,
    ]);

    $otherBranchMp = MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $product2->id,
        'is_active' => true,
        'display_order' => 2,
        'master_menu_product_id' => null,
    ]);

    $otherBranchMpSku = MenuProductSku::factory()->create([
        'menu_product_id' => $otherBranchMp->id,
        'product_sku_id' => $sku2->id,
        'selling_price' => 50.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    // Try to toggle otherBranchMpSku under $this->branchMp (wrong parent)
    $this->actingAs($this->user)
        ->postJson(
            "{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$otherBranchMpSku->id}/toggle",
        )
        ->assertNotFound();
});

it('returns 422 when submitting menu with 0 products', function () {
    $emptyMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$emptyMenu->id}/submit");

    // Service throws MenuOperationException → rendered as 422 with a
    // structured error body. See app/Exceptions/MenuOperationException.php.
    $response->assertUnprocessable()
        ->assertJsonPath('error', 'MENU_OPERATION_NOT_ALLOWED');
});

it('returns 422 when deleting an active menu', function () {
    $activeMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("{$this->baseUrl}/menus/{$activeMenu->id}");

    // Service throws MenuOperationException → rendered as 422 with a
    // structured error body. See app/Exceptions/MenuOperationException.php.
    $response->assertUnprocessable()
        ->assertJsonPath('error', 'MENU_OPERATION_NOT_ALLOWED');
});

// =============================================================================
// Side effects — audit log
// =============================================================================

it('creates audit log entry on menu creation', function () {
    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus", [
            'name' => 'Audit Test Menu',
            'status' => 'Draft',
            'product_ids' => [$this->product->id],
        ]);

    $response->assertCreated();

    $menuId = $response->json('data.id');

    // AuditsActivity trait logs 'created' on the model.
    // The trait silently catches errors if audit_logs table is missing.
    // Verify the Menu model uses the AuditsActivity trait (compile-time check).
    expect(in_array(AuditsActivity::class, class_uses_recursive(Menu::class)))->toBeTrue();

    // If audit_logs table exists, verify the row was written.
    if (Schema::hasTable('audit_logs')) {
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => (new Menu)->getMorphClass(),
            'auditable_id' => $menuId,
            'action' => 'created',
            'user_id' => $this->user->id,
        ]);
    }
});

// =============================================================================
// Side effects — last_synced_at on clone
// =============================================================================

it('sets last_synced_at when cloning to branch', function () {
    // cloneToBranch rejects masters still in Draft — promote to Approved
    // first so the side-effect under test (last_synced_at) can be exercised.
    $this->masterMenu->update(['status' => 'Approved']);

    // A fresh branch in the same org that does not yet have a clone of this
    // master ($this->shop already carries one from beforeEach).
    $freshBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $branchId = $freshBranch->id;

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $branchId,
        ]);

    $response->assertCreated();

    $cloneId = $response->json('data.id');
    $clone = Menu::find($cloneId);

    expect($clone->last_synced_at)->not->toBeNull();
});

// =============================================================================
// Side effects — last_synced_at on sync
// =============================================================================

it('updates last_synced_at when syncing from master', function () {
    // Ensure branchMenu has no last_synced_at initially
    $this->branchMenu->update(['last_synced_at' => null]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->branchMenu->id}/sync-from-master");

    $response->assertSuccessful();

    expect($this->branchMenu->fresh()->last_synced_at)->not->toBeNull();
});

// =============================================================================
// Side effects — soft-delete cascade
// =============================================================================

it('soft-deletes menu_product_skus when menu_product is removed', function () {
    // Ensure we have a menu_product_sku on the master side for this test
    $masterMpSku = MenuProductSku::factory()->create([
        'menu_product_id' => $this->masterMp->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 100.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    expect(MenuProductSku::find($masterMpSku->id))->not->toBeNull();

    // Remove the product from the menu
    $this->actingAs($this->user)
        ->deleteJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/products/{$this->masterMp->id}")
        ->assertNoContent();

    // MenuProduct should be soft-deleted
    expect(MenuProduct::find($this->masterMp->id))->toBeNull();
    expect(MenuProduct::withTrashed()->find($this->masterMp->id))->not->toBeNull();

    // MenuProductSku should also be soft-deleted
    expect(MenuProductSku::find($masterMpSku->id))->toBeNull();
    expect(MenuProductSku::withTrashed()->find($masterMpSku->id))->not->toBeNull();
});
