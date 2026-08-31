<?php

// Test-gap coverage (plan-001):
//  1. sync-new-SKU gap — was pinned here as a documented known-limitation ("a
//     product gains a new SKU after branch clone → sync does NOT auto-add it").
//     #2537 ruled that limitation a bug: 本郷店 sold a product with one of its
//     two variants invisible. The SKU now joins every existing menu_product the
//     moment HQ creates it (ProductSkuObserver::created), so what this asserts
//     flipped — sync still never touches an existing row's overridden price.
//  2. multi-tenant clone isolation — a clone stays entirely inside its own org.
//  3. concurrency safety — the DB unique index on menu_product_skus is the last
//     line of defence against a concurrent double-sync creating duplicate SKUs.

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-0000000000a1';
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
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
        'selling_price' => 30000.00,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
// sync-new-SKU gap (#2537) — a new SKU joins the branch menu at creation time
// =============================================================================

it('adds a brand-new SKU to an already-synced product and leaves the existing SKU untouched', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
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
        'status' => 'Active',
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

    // The branch has already cloned sku1, then the branch customised its price.
    $branchSku = MenuProductSku::factory()->create([
        'menu_product_id' => $branchMp->id,
        'product_sku_id' => $this->sku1->id,
        'selling_price' => 27000.00,
        'is_price_overridden' => true,
        'is_active' => true,
    ]);

    // Brand adds a brand-new active SKU to the SAME product after the clone.
    $sku2 = ProductSku::factory()->withSequencedOption()->create([
        'product_id' => $this->product->id,
        'selling_price' => 45000.00,
        'is_active' => true,
    ]);

    // Creating the SKU is what attaches it — no sync call needed, which is the
    // whole point: a shop-owned menu has no master to sync from.
    expect(
        MenuProductSku::where('menu_product_id', $branchMp->id)
            ->where('product_sku_id', $sku2->id)
            ->exists()
    )->toBeTrue();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    // Both SKUs, and sync did not duplicate the row it now finds already there.
    $branchSkus = MenuProductSku::where('menu_product_id', $branchMp->id)->get();
    expect($branchSkus)->toHaveCount(2);

    // The existing branch SKU keeps its custom price and override flag.
    $fresh = $branchSku->fresh();
    expect((string) $fresh->selling_price)->toBe('27000.00');
    expect($fresh->is_price_overridden)->toBeTrue();
});

// =============================================================================
// Multi-tenant clone isolation
// =============================================================================

it('keeps a cloned branch menu and all its rows scoped to the cloning org only', function () {
    // A second, unrelated org that must never see org-A's cloned data.
    $otherOrgId = '00000000-0000-0000-0000-0000000000b2';
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);

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

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $cloneId = $response->json('data.id');

    // The clone and every row it produced belong to org-A.
    $clone = Menu::find($cloneId);
    expect($clone->organization_id)->toBe($this->orgId);

    // Nothing landed under the other org's brand or branch.
    expect(Menu::where('organization_id', $otherOrgId)->count())->toBe(0);
    expect(Menu::where('branch_id', $otherBranch->id)->count())->toBe(0);
    expect(
        MenuProduct::whereIn(
            'menu_id',
            Menu::where('organization_id', $otherOrgId)->pluck('id')
        )->count()
    )->toBe(0);
});

it('rejects cloning a master menu into a branch owned by another org (422)', function () {
    $otherOrgId = '00000000-0000-0000-0000-0000000000c3';
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);

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

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$masterMenu->id}/clone-to-branch", [
            'branch_id' => $otherBranch->id,
        ])
        ->assertStatus(422);

    // No cross-org clone leaked through.
    expect(Menu::where('branch_id', $otherBranch->id)->count())->toBe(0);
});

// =============================================================================
// Concurrency safety — the DB unique index blocks duplicate SKU rows
// =============================================================================

it('enforces the (menu_product_id, product_sku_id) unique index so a concurrent double-sync cannot duplicate a SKU', function () {
    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);
    $branchMp = MenuProduct::factory()->create([
        'menu_id' => $branchMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $branchMp->id,
        'product_sku_id' => $this->sku1->id,
        'selling_price' => 30000.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    // A racing insert of the same (menu_product_id, product_sku_id) pair must
    // be rejected at the storage layer, not silently duplicated.
    expect(fn () => MenuProductSku::factory()->create([
        'menu_product_id' => $branchMp->id,
        'product_sku_id' => $this->sku1->id,
        'selling_price' => 30000.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]))->toThrow(QueryException::class);

    expect(MenuProductSku::where('menu_product_id', $branchMp->id)->count())->toBe(1);
});
