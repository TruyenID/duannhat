<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->sku1 = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 50.00,
        'is_active' => true,
    ]);

    $this->sku2 = ProductSku::factory()->withSequencedOption()->create([
        'product_id' => $this->product->id,
        'selling_price' => 75.00,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
// Clone
// =============================================================================

it('clones a master menu to a branch with menu_products and menu_product_skus', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchId = $this->branch->id;

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $branchId,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.branch_id', $branchId)
        ->assertJsonPath('data.is_master', false)
        ->assertJsonPath('data.master_menu_id', $masterMenu->id)
        // Branches now land at Active so the shop can serve them without an
        // additional approve+activate round-trip after clone.
        ->assertJsonPath('data.status', 'Active');

    $cloneId = $response->json('data.id');

    // Branch menu should have 1 menu_product
    expect(MenuProduct::where('menu_id', $cloneId)->count())->toBe(1);

    // Branch menu_product should have menu_product_skus for each active SKU
    $branchMp = MenuProduct::where('menu_id', $cloneId)->first();
    expect(MenuProductSku::where('menu_product_id', $branchMp->id)->count())->toBe(2);
});

it('clones a master menu that is already Active (post-go-live spawn)', function () {
    // Real-world case: an approved master got activated, then later a new shop
    // opens and we need to clone the same master to its branch. Active masters
    // must still be cloneable without forcing a deactivate-and-re-approve dance.
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    // Real Branch row — menus.branch_id has an FK constraint to branches.id,
    // so a bare Str::uuid() (the pattern used by other tests in this file)
    // would fail with a foreign-key violation.
    $branch = Branch::factory()->create([
        'console_brand_id' => $this->brand->console_brand_id,
        'console_organization_id' => $this->brand->console_organization_id,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $branch->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'Active');
});

it('sets selling_price from product_skus.selling_price during clone with is_price_overridden=false', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchId = $this->branch->id;

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $branchId,
        ]);

    $response->assertCreated();

    $cloneId = $response->json('data.id');
    $branchMp = MenuProduct::where('menu_id', $cloneId)->first();
    $skus = MenuProductSku::where('menu_product_id', $branchMp->id)
        ->orderBy('selling_price')
        ->get();

    expect((float) $skus[0]->selling_price)->toBe(50.00);
    expect($skus[0]->is_price_overridden)->toBeFalse();
    expect((float) $skus[1]->selling_price)->toBe(75.00);
    expect($skus[1]->is_price_overridden)->toBeFalse();
});

it('rejects cloning master menu that is not approved', function () {
    foreach (['Draft', 'Pending', 'Rejected', 'Inactive'] as $status) {
        $masterMenu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => null,
            'master_menu_id' => null,
            'is_master' => true,
            'status' => $status,
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
                'branch_id' => $this->branch->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'INVALID_STATUS_TRANSITION');
    }
});

it('rejects cloning non-master menu', function () {
    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => (string) Str::uuid(),
        'is_master' => false,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'MENU_OPERATION_NOT_ALLOWED');
});

it('rejects cloning to a branch that already has a clone', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchId = $this->branch->id;

    // First clone succeeds
    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $branchId,
        ])
        ->assertCreated();

    // Second clone to same branch fails
    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $branchId,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'MENU_OPERATION_NOT_ALLOWED');
});

// =============================================================================
// Tenant isolation (plan-001 audit CRITICAL) — clone-to-branch must not accept
// a branch that belongs to another organization, nor a non-existent branch.
// =============================================================================

it('rejects cloning a master menu into another organizations branch (422)', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    // A branch that lives in a DIFFERENT organization.
    $foreignBrand = Brand::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
    ]);
    $foreignBranch = Branch::factory()->create([
        'console_organization_id' => $foreignBrand->console_organization_id,
        'console_brand_id' => $foreignBrand->console_brand_id,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $foreignBranch->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch_id');

    // No cross-tenant clone leaked through.
    expect(Menu::where('master_menu_id', $masterMenu->id)->count())->toBe(0);
    expect(Menu::where('branch_id', $foreignBranch->id)->count())->toBe(0);
});

it('rejects cloning a master menu into a non-existent branch (422)', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch_id');

    expect(Menu::where('master_menu_id', $masterMenu->id)->count())->toBe(0);
});

// =============================================================================
// Sync
// =============================================================================

it('detects new products available for sync', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    $masterMp = MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => (string) Str::uuid(),
        'is_master' => false,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $masterMenu->id,
        'brand_id' => $this->brand->id,
    ]);

    // Branch already has product synced
    MenuProduct::factory()->create([
        'menu_id' => $branchMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
        'master_menu_product_id' => $masterMp->id,
    ]);

    // Add a new product to master
    $product2 = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $product2->id,
        'is_active' => true,
        'display_order' => 2,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/menus/{$branchMenu->id}/check-sync");

    $response->assertSuccessful()
        ->assertJsonPath('count', 1);
});

it('syncs new products from master including their SKUs', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    $masterMp = MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchId = $this->branch->id;
    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $branchId,
        'is_master' => false,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $masterMenu->id,
        'brand_id' => $this->brand->id,
    ]);

    // Branch already has product synced
    MenuProduct::factory()->create([
        'menu_id' => $branchMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
        'master_menu_product_id' => $masterMp->id,
    ]);

    // Add new product to master with an active SKU
    $product2 = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
    $sku3 = ProductSku::factory()->create([
        'product_id' => $product2->id,
        'selling_price' => 99.00,
        'is_active' => true,
    ]);
    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $product2->id,
        'is_active' => true,
        'display_order' => 2,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master");

    $response->assertSuccessful();

    // Branch menu should now have 2 menu_products
    expect(MenuProduct::where('menu_id', $branchMenu->id)->count())->toBe(2);

    // The new menu_product should have a menu_product_sku with selling_price from product_sku
    $newBranchMp = MenuProduct::where('menu_id', $branchMenu->id)
        ->where('product_id', $product2->id)
        ->first();
    $newSku = MenuProductSku::where('menu_product_id', $newBranchMp->id)->first();

    expect($newSku)->not->toBeNull();
    expect((float) $newSku->selling_price)->toBe(99.00);
    expect($newSku->is_price_overridden)->toBeFalse();
});

it('does not duplicate existing products during sync', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    $masterMp = MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => (string) Str::uuid(),
        'is_master' => false,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $masterMenu->id,
        'brand_id' => $this->brand->id,
    ]);

    // Branch already has this product
    MenuProduct::factory()->create([
        'menu_id' => $branchMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
        'master_menu_product_id' => $masterMp->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master");

    $response->assertSuccessful();

    // Still only 1 menu_product — no duplicates
    expect(MenuProduct::where('menu_id', $branchMenu->id)->count())->toBe(1);
});

it('creates menu_product_skus for product with 0 active SKUs correctly (empty)', function () {
    // Product with no active SKUs
    $product3 = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
    ProductSku::factory()->create([
        'product_id' => $product3->id,
        'selling_price' => 30.00,
        'is_active' => false,
    ]);

    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $product3->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchId = $this->branch->id;

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $branchId,
        ]);

    $response->assertCreated();

    $cloneId = $response->json('data.id');
    $branchMp = MenuProduct::where('menu_id', $cloneId)->first();

    // No active SKUs => no menu_product_skus created
    expect(MenuProductSku::where('menu_product_id', $branchMp->id)->count())->toBe(0);
});

// =============================================================================
// plan-001 audit — HQ price change must refresh non-overridden branch snapshots
// (menu_product_skus.selling_price is copied at clone time and read verbatim as
// the effective serving/ordering price; without a refresh path branches serve a
// stale snapshot forever).
// =============================================================================

it('propagates an HQ selling_price change to non-overridden menu_product_skus but not overridden ones', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $cloneId = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated()
        ->json('data.id');

    $branchMp = MenuProduct::where('menu_id', $cloneId)->first();

    // sku1 (50.00) stays a non-overridden mirror; sku2 (75.00) becomes a
    // shop-set override that HQ must never clobber.
    $mirror = MenuProductSku::where('menu_product_id', $branchMp->id)
        ->where('product_sku_id', $this->sku1->id)->first();
    $override = MenuProductSku::where('menu_product_id', $branchMp->id)
        ->where('product_sku_id', $this->sku2->id)->first();
    $override->update(['selling_price' => 999.00, 'is_price_overridden' => true]);

    expect((float) $mirror->fresh()->selling_price)->toBe(50.00);

    // HQ raises the canonical price.
    $this->sku1->update(['selling_price' => 65.00]);
    $this->sku2->update(['selling_price' => 88.00]);

    // Non-overridden branch snapshot follows HQ; overridden one is untouched.
    expect((float) $mirror->fresh()->selling_price)->toBe(65.00);
    expect($mirror->fresh()->is_price_overridden)->toBeFalse();
    expect((float) $override->fresh()->selling_price)->toBe(999.00);
    expect($override->fresh()->is_price_overridden)->toBeTrue();
});

// =============================================================================
// A product leaving the master menu is REMOVED from the branch on sync — a
// cloned menu is a faithful mirror of its master, so a dropped product must not
// linger at the shop (as "dư món"). Soft-delete keeps the row recoverable if HQ
// re-adds the product; the shop's price override for a truly-removed product is
// intentionally not preserved (product-owner decision).
// =============================================================================

it('removes orphaned branch products on sync instead of leaving them lingering', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    $masterMp = MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $masterMenu->id,
        'brand_id' => $this->brand->id,
    ]);

    $branchMp = MenuProduct::factory()->create([
        'menu_id' => $branchMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
        'master_menu_product_id' => $masterMp->id,
    ]);

    // The shop set a custom price on this branch row.
    $branchSku = MenuProductSku::factory()->create([
        'menu_product_id' => $branchMp->id,
        'product_sku_id' => $this->sku1->id,
        'selling_price' => 123.00,
        'is_price_overridden' => true,
        'is_active' => true,
    ]);

    // Product leaves the master menu entirely.
    $masterMp->menuProductSkus()->delete();
    $masterMp->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    // Branch row is soft-deleted — gone from every live read, so nothing extra
    // shows at the shop. It stays recoverable (relink restores it if HQ re-adds
    // the product), but is no longer part of the active menu.
    $branchMp = MenuProduct::withTrashed()->findOrFail($branchMp->id);
    expect($branchMp->trashed())->toBeTrue();
    expect(MenuProduct::where('menu_id', $branchMenu->id)->count())->toBe(0);

    // Its SKUs are soft-deleted too.
    $branchSku = MenuProductSku::withTrashed()->findOrFail($branchSku->id);
    expect($branchSku->trashed())->toBeTrue();
});

// =============================================================================
// #1234 — a schedule's DATE WINDOW must survive clone and sync
// =============================================================================

/**
 * `menu_schedules.start_date` / `end_date` are read at menu-resolution time
 * (CustomerMenuService filters `whereNull('start_date')->orWhere(...)`), where
 * NULL means "no bound". So dropping them does not merely lose metadata — it
 * turns a CAMPAIGN schedule into a PERMANENT one.
 *
 * A Tết menu scheduled 17:00–19:00 for Feb 1–15 only, cloned to a shop, ran
 * 17:00–19:00 every day of the year at promo prices.
 *
 * `duplicate()` carried both columns all along; clone and sync did not — the
 * same asymmetry as #1233, found by diffing the columns each copy path writes.
 */
function masterMenuWithDatedSchedule($test): Menu
{
    $master = Menu::factory()->create([
        'organization_id' => $test->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $test->user->id,
        'master_menu_id' => null,
        'brand_id' => $test->brand->id,
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $master->id,
        'product_id' => $test->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $test->actingAs($test->user)
        ->postJson("{$test->baseUrl}/menus/{$master->id}/schedules", [
            'start_time' => '17:00',
            'end_time' => '19:00',
            'days_of_week' => 127,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-15',
        ])->assertCreated();

    return $master;
}

it('carries the schedule date window onto a branch clone', function () {
    $master = masterMenuWithDatedSchedule($this);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $branchSchedule = Menu::where('master_menu_id', $master->id)
        ->firstOrFail()->schedules()->firstOrFail();

    expect($branchSchedule->start_date?->toDateString())->toBe('2026-02-01')
        ->and($branchSchedule->end_date?->toDateString())->toBe('2026-02-15');
});

it('carries the schedule date window onto a schedule that arrives by sync', function () {
    // Clone with no schedule, then add the dated one at HQ so it reaches the
    // branch through the sync create path rather than the clone path.
    $master = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);
    MenuProduct::factory()->create([
        'menu_id' => $master->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$master->id}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
            'start_date' => '2026-02-01', 'end_date' => '2026-02-15',
        ])->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $master->id)->firstOrFail();
    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    $branchSchedule = $branchMenu->schedules()->firstOrFail();

    expect($branchSchedule->start_date?->toDateString())->toBe('2026-02-01')
        ->and($branchSchedule->end_date?->toDateString())->toBe('2026-02-15');
});

it('follows HQ when the campaign window moves, including back to open-ended', function () {
    // The update branch of sync. Without it a shortened campaign keeps selling
    // at the old end date, and a window HQ removed can never be cleared.
    $master = masterMenuWithDatedSchedule($this);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $master->id)->firstOrFail();
    $masterSchedule = $master->schedules()->firstOrFail();

    $masterSchedule->update(['end_date' => '2026-02-10']);
    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    expect($branchMenu->schedules()->firstOrFail()->end_date?->toDateString())->toBe('2026-02-10');

    $masterSchedule->update(['start_date' => null, 'end_date' => null]);
    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    $reSynced = $branchMenu->schedules()->firstOrFail();
    expect($reSynced->start_date)->toBeNull()->and($reSynced->end_date)->toBeNull();
});

/**
 * #1235 — a Takeaway-only master menu must not be served to dine-in guests the
 * moment it is cloned.
 *
 * `menus.service_type` is `nullable()->default('Both')`. cloneToBranch never
 * passed the column, so MySQL supplied 'Both' — and 'Both' matches every
 * service-type filter in CustomerMenuService. The NULL-means-inherit branch of
 * that query is therefore dead code for clones: they never land NULL.
 *
 * syncFromMaster already mirrors this column (its comment even names "its
 * clone-time default" as the thing being repaired), but a clone lands Active
 * and is served immediately, so the window before the shop's first sync is a
 * real one. This makes clone agree with sync.
 *
 * The tax design compounds it: the takeaway menu is where REDUCED (8%)
 * overrides live, so a dine-in guest served it is billed 軽減税率 on a 店内
 * order — the exact error tax types exist to prevent.
 */
it('does not widen a Takeaway-only master into Both when cloning', function () {
    $master = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
        'service_type' => 'Takeaway',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $clone = Menu::where('master_menu_id', $master->id)->firstOrFail();

    // getRawOriginal, not the accessor: the customer-facing query reads the
    // raw column, so that is the only value that decides what guests see.
    expect($clone->getRawOriginal('service_type'))->toBe('Takeaway');
});

it('keeps a DineIn-only master narrow on the clone too', function () {
    $master = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
        'service_type' => 'DineIn',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    expect(Menu::where('master_menu_id', $master->id)->firstOrFail()->getRawOriginal('service_type'))
        ->toBe('DineIn');
});
