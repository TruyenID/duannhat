<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\File;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuPromotion;
use App\Models\MenuSchedule;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
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
        'slug' => 'test-shop',
        'is_active' => true,
    ]);

    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->productSku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 100.00,
        'is_active' => true,
    ]);

    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);

    $this->masterMp = MenuProduct::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

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
        'product_sku_id' => $this->productSku->id,
        'selling_price' => 100.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->manager->assignRole($this->managerRole, $this->orgId);

    $this->shopBase = "/api/v1/shops/{$this->shop->slug}";
});

// =============================================================================
// Show
// =============================================================================

it('shows branch menu with products and SKU prices from menu_product_skus', function () {
    $response = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}");

    $response->assertSuccessful()
        ->assertJsonPath('data.id', $this->branchMenu->id)
        ->assertJsonMissingPath('data.menuProducts');

    // The response should include menu_products with nested menu_product_skus
    $menuProducts = $response->json('data.menu_products');
    expect($menuProducts)->toHaveCount(1);
});

it('returns a compact menu payload without duplicated generated relationships or full galleries', function () {
    File::factory()->permanent()->create([
        'organization_id' => $this->orgId,
        'fileable_type' => $this->product->getMorphClass(),
        'fileable_id' => $this->product->id,
        'collection' => 'gallery',
        'disk' => 'public',
        'path' => "products/{$this->product->id}/image.jpg",
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}?compact=1");

    $response->assertSuccessful()
        ->assertJsonMissingPath('data.menu_products.0.menuProductSkus')
        ->assertJsonMissingPath('data.menu_products.0.menuSection')
        ->assertJsonMissingPath('data.menu_products.0.product.gallery')
        ->assertJsonPath(
            'data.menu_products.0.product.image_url',
            Storage::disk('public')->url("products/{$this->product->id}/image.jpg"),
        );

    expect($response->json('data.menu_products.0.skus'))->toHaveCount(1);
});

it('counts DISTINCT products (not placements) so list + detail agree on multi-section products', function () {
    // Feature the same product in a second section — a legit multi-section
    // placement → 2 menu_product ROWS but still 1 distinct product. "Số sản
    // phẩm" is the number of DISHES, so both the list and the detail must
    // count this as 1, never 2.
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $this->product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 2,
    ]);

    // Detail endpoint.
    $detailCount = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}")
        ->assertSuccessful()
        ->json('data.menu_products_count');

    // List endpoint — same menu, same authoritative count.
    $listCount = collect(
        $this->actingAs($this->manager)
            ->getJson("{$this->shopBase}/menus")
            ->assertSuccessful()
            ->json('data')
    )->firstWhere('id', $this->branchMenu->id)['menu_products_count'];

    // 2 placements of 1 product → 1 distinct product, in BOTH views.
    expect($detailCount)->toBe(1)
        ->and($listCount)->toBe(1);
});

it('returns shop menu products ordered by display_order, not insertion order', function () {
    // Two extra products inserted so that DB insertion order (which the plain
    // eager-load falls back to) is the REVERSE of the intended display_order.
    // The seeded $this->branchMp has display_order = 1.
    $second = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->product->product_type_id,
        'brand_id' => $this->brand->id,
    ]);
    $third = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->product->product_type_id,
        'brand_id' => $this->brand->id,
    ]);

    // Insert $third FIRST (rowid order) but give it the LAST display_order,
    // and $second SECOND but the middle display_order — so raw insertion order
    // (branchMp, third, second) differs from display_order (branchMp, second, third).
    $thirdMp = MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $third->id,
        'is_active' => true,
        'display_order' => 3,
    ]);
    $secondMp = MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $second->id,
        'is_active' => true,
        'display_order' => 2,
    ]);

    $productIds = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}")
        ->assertSuccessful()
        ->json('data.menu_products.*.product_id');

    expect($productIds)->toBe([
        $this->product->id,  // display_order 1
        $second->id,         // display_order 2
        $third->id,          // display_order 3
    ]);
});

it('paginates the product list over a TOTAL order — every row once, none twice', function () {
    // `display_order` is not unique, and on real data it is barely populated at
    // all: 104 of one branch menu's 127 rows sit on 0. Ordering by it alone is
    // a PARTIAL order, and LIMIT/OFFSET over a partial order may hand the same
    // row back on two pages while never handing back another — the client that
    // walks every page still ends up with holes. The tie-break on the unique id
    // is what makes the walk sound.
    //
    // Ids are pinned in DESCENDING order relative to insertion so "whatever the
    // plan happens to yield" and "the contract" cannot look alike by accident.
    $ids = ['e', 'd', 'c', 'b', 'a'];
    foreach ($ids as $letter) {
        $product = Product::factory()->active()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->product->product_type_id,
            'brand_id' => $this->brand->id,
        ]);
        MenuProduct::factory()->create([
            'id' => "0199{$letter}000-0000-7000-8000-000000000000",
            'menu_id' => $this->branchMenu->id,
            'product_id' => $product->id,
            'is_active' => true,
            'display_order' => 0,
        ]);
    }

    $seen = [];
    for ($page = 1; $page <= 3; $page++) {
        $rows = $this->actingAs($this->manager)
            ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products?per_page=2&page={$page}")
            ->assertSuccessful()
            ->json('data.*.id');

        $seen = array_merge($seen, $rows);
    }

    // Six rows total: the five above plus the seeded $this->branchMp.
    expect($seen)->toHaveCount(6)
        ->and(array_unique($seen))->toHaveCount(6)
        ->and(array_slice($seen, 0, 5))->toBe([
            '0199a000-0000-7000-8000-000000000000',
            '0199b000-0000-7000-8000-000000000000',
            '0199c000-0000-7000-8000-000000000000',
            '0199d000-0000-7000-8000-000000000000',
            '0199e000-0000-7000-8000-000000000000',
        ]);
});

// =============================================================================
// Toggle product
// =============================================================================

it('toggles product is_active at shop level', function () {
    expect($this->branchMp->is_active)->toBeTrue();

    $response = $this->actingAs($this->manager)
        ->postJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/toggle");

    $response->assertSuccessful();

    expect($this->branchMp->fresh()->is_active)->toBeFalse();
});

// =============================================================================
// Toggle SKU
// =============================================================================

it('toggles SKU is_active at shop level', function () {
    expect($this->branchMpSku->is_active)->toBeTrue();

    $response = $this->actingAs($this->manager)
        ->postJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/toggle");

    $response->assertSuccessful();

    expect($this->branchMpSku->fresh()->is_active)->toBeFalse();
});

// =============================================================================
// Price override
// =============================================================================

it('overrides SKU selling_price and sets is_price_overridden=true', function () {
    $response = $this->actingAs($this->manager)
        ->postJson(
            "{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/price",
            ['selling_price' => 150.00],
        );

    $response->assertSuccessful();

    $fresh = $this->branchMpSku->fresh();
    expect((float) $fresh->selling_price)->toBe(150.00);
    expect($fresh->is_price_overridden)->toBeTrue();
});

// =============================================================================
// Price reset
// =============================================================================

it('resets SKU price to product_skus.selling_price and sets is_price_overridden=false', function () {
    // First override the price
    $this->branchMpSku->update([
        'selling_price' => 200.00,
        'is_price_overridden' => true,
    ]);

    $response = $this->actingAs($this->manager)
        ->postJson(
            "{$this->shopBase}/menus/{$this->branchMenu->id}/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/reset-price",
        );

    $response->assertSuccessful();

    $fresh = $this->branchMpSku->fresh();
    expect((float) $fresh->selling_price)->toBe(100.00);
    expect($fresh->is_price_overridden)->toBeFalse();
});

// =============================================================================
// Combined is_active
// =============================================================================

it('shows combined is_active: product off means all SKUs unavailable', function () {
    // Deactivate the product
    $this->branchMp->update(['is_active' => false]);

    $response = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products");

    $response->assertSuccessful();

    $products = $response->json('data');
    expect($products)->toHaveCount(1);
    expect($products[0]['is_active'])->toBeFalse();
});

// =============================================================================
// active_promotion overlay — category-scoped (plan-019 regression)
// =============================================================================

it('overlays active_promotion on the card for a category-scoped Happy Hour', function () {
    // The product belongs to a category, and an all-day category-scoped
    // promotion covers that category. Pre-fix, listBranchMenuProducts did not
    // eager-load product.categories, so the controller passed category_ids=[]
    // to the resolver → matchesScope() found no category overlap → the card
    // showed no promotion even though the cart applied it. Regression guard.
    $category = Category::factory()->create(['brand_id' => $this->brand->id]);
    $this->product->categories()->attach($category->id);

    $promo = MenuPromotion::factory()->create([
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'name' => 'Category Happy Hour 15%',
        'discount_percent' => 15,
        'applies_to' => 'categories',
        'daily_time_from' => null,
        'daily_time_to' => null,
        'weekdays' => null,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addYear(),
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
    ]);
    $promo->categories()->attach($category->id);

    $products = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products")
        ->assertSuccessful()
        ->json('data');

    expect($products)->toHaveCount(1);
    expect($products[0]['active_promotion'])->not->toBeNull();
    expect((float) $products[0]['active_promotion']['discount_percent'])->toBe(15.0);
});

// =============================================================================
// Search — matches Product.name OR ProductSku.name OR ProductSku.sku
// =============================================================================

it('filters menu products by Product.name, ProductSku.name, and ProductSku.sku', function () {
    // Seed the shop's existing Product with specific searchable values, then
    // add a second product so filters can actually narrow the result set.
    $this->product->update(['name' => 'Americano']);
    $this->productSku->update(['name' => 'Size S', 'sku' => 'AM-S']);

    $productType = $this->product->product_type_id;
    $otherProduct = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType,
        'name' => 'Mocha',
    ]);
    $otherSku = ProductSku::factory()->create([
        'product_id' => $otherProduct->id,
        'name' => 'Large cup',
        'sku' => 'MO-L',
        'is_active' => true,
    ]);
    $otherMp = MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $otherProduct->id,
        'is_active' => true,
        'display_order' => 2,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $otherMp->id,
        'product_sku_id' => $otherSku->id,
        'selling_price' => 150.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    $base = "{$this->shopBase}/menus/{$this->branchMenu->id}/products";

    // 1. Product.name match — "Ameri" finds only Americano.
    $resp = $this->actingAs($this->manager)->getJson("{$base}?search=Ameri");
    $resp->assertSuccessful();
    $ids = collect($resp->json('data'))->pluck('product_id')->all();
    expect($ids)->toContain($this->product->id)->not->toContain($otherProduct->id);

    // 2. ProductSku.name match — "Large" finds only Mocha (via its SKU).
    $resp = $this->actingAs($this->manager)->getJson("{$base}?search=Large");
    $resp->assertSuccessful();
    $ids = collect($resp->json('data'))->pluck('product_id')->all();
    expect($ids)->toContain($otherProduct->id)->not->toContain($this->product->id);

    // 3. ProductSku.sku match — "MO-L" finds only Mocha (via SKU code).
    $resp = $this->actingAs($this->manager)->getJson("{$base}?search=MO-L");
    $resp->assertSuccessful();
    $ids = collect($resp->json('data'))->pluck('product_id')->all();
    expect($ids)->toContain($otherProduct->id)->not->toContain($this->product->id);

    // 4. Non-matching term returns zero rows (not every row).
    $resp = $this->actingAs($this->manager)->getJson("{$base}?search=zzzz");
    $resp->assertSuccessful();
    expect($resp->json('data'))->toHaveCount(0);
});

// =============================================================================
// Standalone branch menu visibility (#878 — created directly on the branch)
// =============================================================================

it('lists a standalone branch menu (no master) in the shop menus list', function () {
    // A menu created directly on the branch — is_master=false, master_menu_id=null.
    // Pre-fix, listBranchMenusForShop() gated on whereNotNull('master_menu_id')
    // so this menu never appeared at the shop even when Active. Regression guard.
    $standalone = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);

    $ids = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus?status=Active")
        ->assertSuccessful()
        ->json('data.*.id');

    expect($ids)->toContain($standalone->id)          // standalone shows
        ->toContain($this->branchMenu->id);           // cloned still shows
});

it('shows a standalone branch menu detail (no master) at the shop', function () {
    // Same class of bug as the list: MenuPolicy::shopView() gated on
    // master_menu_id !== null, so GET /shops/{shop}/menus/{menu} 403'd for a
    // menu created directly on the branch even though it belongs to the shop.
    $standalone = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);

    $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$standalone->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $standalone->id);
});

it('overrides a SKU price on a standalone branch menu (no master)', function () {
    // shopUpdatePrice carried the same master_menu_id !== null gate → 403.
    $standalone = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);
    $mp = MenuProduct::factory()->create([
        'menu_id' => $standalone->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $mpSku = MenuProductSku::factory()->create([
        'menu_product_id' => $mp->id,
        'product_sku_id' => $this->productSku->id,
        'selling_price' => 100.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->postJson(
            "{$this->shopBase}/menus/{$standalone->id}/products/{$mp->id}/skus/{$mpSku->id}/price",
            ['selling_price' => 175.00],
        )
        ->assertSuccessful();

    $fresh = $mpSku->fresh();
    expect((float) $fresh->selling_price)->toBe(175.00);
    expect($fresh->is_price_overridden)->toBeTrue();
});

// =============================================================================
// Service-type gate (#481 — POS menu split, mirrors customer-web #463)
// =============================================================================

it('filters branch menus by service_type — DineIn gate hides Takeaway, shows DineIn + Both', function () {
    // Base branch menu → Both, so it must show under every service type.
    $this->branchMenu->update(['service_type' => 'Both']);

    $dineIn = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $this->masterMenu->id,
        'service_type' => 'DineIn',
    ]);
    $takeaway = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $this->masterMenu->id,
        'service_type' => 'Takeaway',
    ]);

    $ids = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus?service_type=DineIn&status=Active")
        ->assertSuccessful()
        ->json('data.*.id');

    expect($ids)->toContain($dineIn->id)            // DineIn shows
        ->toContain($this->branchMenu->id)          // Both shows
        ->not->toContain($takeaway->id);            // Takeaway hidden

    // Back-compat: no service_type param → gate off, every menu returned.
    $allIds = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus?status=Active")
        ->assertSuccessful()
        ->json('data.*.id');
    expect($allIds)->toContain($takeaway->id);
});

it('branch menu with NULL service_type inherits its master menu type', function () {
    // Master pinned to Takeaway; branch menu leaves service_type NULL → inherits.
    $takeawayMaster = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
        'service_type' => 'Takeaway',
    ]);
    $inheriting = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $takeawayMaster->id,
        'service_type' => null,
    ]);

    // Under the Takeaway gate the inheriting menu shows …
    $takeawayIds = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus?service_type=Takeaway&status=Active")
        ->assertSuccessful()
        ->json('data.*.id');
    expect($takeawayIds)->toContain($inheriting->id);

    // … but under DineIn it is hidden (master is Takeaway).
    $dineInIds = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus?service_type=DineIn&status=Active")
        ->assertSuccessful()
        ->json('data.*.id');
    expect($dineInIds)->not->toContain($inheriting->id);
});

// =============================================================================
// Effective service type on the listing (#1756 — POS has to SHOW the split)
// =============================================================================
//
// The gate above only ever filtered. Two cases leave the cashier looking at an
// unlabelled list anyway: with no active order there is nothing to gate on, so
// DineIn and Takeaway menus appear side by side (and pos-web's pickActiveMenu
// auto-selects one by time window); and a `Both` menu shows under every order
// type by definition. Since TaxResolver takes no order_type, 8% vs 10% rides
// whichever menu line the item was added from and snapshots immutably — so
// "which menu am I on" is a money question, not a cosmetic one.

it('stamps effective_service_type on the branch menu listing, resolving inheritance', function () {
    $takeawayMaster = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
        'service_type' => 'Takeaway',
    ]);

    // Inherits (own NULL) → must report the MASTER's Takeaway, not null.
    $inheriting = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $takeawayMaster->id,
        'service_type' => null,
    ]);

    // Own value overrides the master.
    $overriding = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $takeawayMaster->id,
        'service_type' => 'DineIn',
    ]);

    // Standalone branch menu, no master and no own value → Both.
    $standalone = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => null,
        'service_type' => null,
    ]);

    $byId = collect(
        $this->actingAs($this->manager)
            ->getJson("{$this->shopBase}/menus?status=Active")
            ->assertSuccessful()
            ->json('data')
    )->keyBy('id');

    expect($byId[$inheriting->id]['effective_service_type'])->toBe('Takeaway')
        ->and($byId[$overriding->id]['effective_service_type'])->toBe('DineIn')
        ->and($byId[$standalone->id]['effective_service_type'])->toBe('Both');

    // The raw column is why this field has to exist: on the inheriting menu it
    // is NULL, which means "ask the master" — not a service type any screen
    // could render.
    expect($byId[$inheriting->id]['service_type'])->toBeNull();
});

it('stamps effective_service_type on the by-day listing the POS dropdown reads', function () {
    $dineInMaster = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
        'service_type' => 'DineIn',
    ]);
    $inheriting = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $dineInMaster->id,
        'service_type' => null,
    ]);

    // by-day is strictly schedule-driven — an always-on menu is excluded, so
    // the menu needs a schedule row covering the day we ask for.
    $dow = 3;
    MenuSchedule::factory()->create([
        'menu_id' => $inheriting->id,
        'days_of_week' => 1 << $dow,
        'is_active' => true,
        'priority' => 1,
        'start_time' => '10:00:00',
        'end_time' => '14:00:00',
    ]);

    $row = collect(
        $this->actingAs($this->manager)
            ->getJson("{$this->shopBase}/menus/by-day/{$dow}")
            ->assertSuccessful()
            ->json('data')
    )->firstWhere('id', $inheriting->id);

    expect($row)->not->toBeNull()
        ->and($row['effective_service_type'])->toBe('DineIn')
        // The by-day extras must survive the added subquery select.
        ->and($row['start_time'])->toBe('10:00:00')
        ->and($row['end_time'])->toBe('14:00:00');
});

// =========================================================================
//  #3163 — tải thực đơn THEO SECTION
//
//  #3159 chữa "mất section" bằng cách cho POS đi hết các trang, nhưng chi phí
//  vẫn tuyến tính theo số món: menu 89 dòng = 638 KB một vòng, và query có
//  `refetchInterval` 60 giây. Menu ~1000 món ⇒ ~7 MB mỗi phút mỗi tablet.
//
//  Hai đường dưới đây làm chi phí thôi phụ thuộc kích thước menu: thanh pill
//  lấy từ `sections` (rẻ, luôn đủ), món lấy theo từng section.
// =========================================================================

it('#3163 sections: trả đủ section KÈM số món, và nhóm chưa xếp không bị giấu', function () {
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Đồ uống',
    ]);

    foreach ([1, 2] as $_) {
        $product = Product::factory()->active()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->product->product_type_id,
            'brand_id' => $this->brand->id,
        ]);
        MenuProduct::factory()->create([
            'menu_id' => $this->branchMenu->id,
            'product_id' => $product->id,
            'menu_section_id' => $section->id,
            'is_active' => true,
        ]);
    }

    $rows = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/sections")
        ->assertSuccessful()
        ->json('data');

    $bySection = collect($rows)->keyBy(fn ($r) => $r['id'] ?? 'none');

    // Section này KHÔNG được gắn vào pivot `menu_menu_section` — chỉ có
    // `menu_products.menu_section_id` trỏ tới nó. Lấy section chỉ từ pivot sẽ
    // bỏ sót đúng nó, tức món biến mất khỏi thanh pill: #3159 tái diễn ở dạng
    // khó thấy hơn.
    expect($bySection->has((string) $section->id))->toBeTrue()
        ->and($bySection[(string) $section->id]['products_count'])->toBe(2)
        ->and($bySection[(string) $section->id]['name'])->toBe('Đồ uống');

    // `$this->branchMp` không thuộc section nào. Món chưa xếp vẫn phải bán
    // được, nên nhóm này phải xuất hiện — với `id = null`, hợp đồng để client
    // gọi `?section_id=none`.
    expect($bySection->has('none'))->toBeTrue()
        ->and($bySection['none']['products_count'])->toBe(1)
        ->and($bySection['none']['id'])->toBeNull();
});

it('#3163 products?section_id= trả đúng MỘT section, và `none` là nhóm chưa xếp', function () {
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->product->product_type_id,
        'brand_id' => $this->brand->id,
    ]);
    $inSection = MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
    ]);

    $only = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products?section_id={$section->id}")
        ->assertSuccessful()
        ->json('data.*.id');

    expect($only)->toBe([(string) $inSection->id]);

    // `none` phải là giá trị TƯỜNG MINH: bỏ trống tham số đã mang nghĩa "mọi
    // section", nên không có cách nào khác để hỏi nhóm chưa xếp.
    $unassigned = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products?section_id=none")
        ->assertSuccessful()
        ->json('data.*.id');

    expect($unassigned)->toBe([(string) $this->branchMp->id]);

    // Và không truyền gì thì vẫn là CẢ MENU — đường cũ không được đổi nghĩa.
    $all = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products")
        ->assertSuccessful()
        ->json('data.*.id');

    expect($all)->toHaveCount(2);
});

it('#3163 products?sku_id= tra được MỘT món — luồng sửa món thôi cần cả thực đơn', function () {
    // Hôm nay việc sửa một món đã đặt dựa vào chỗ cả thực đơn đã nằm sẵn trong
    // bộ nhớ POS. Khi POS thôi tải hết, thiếu đường này thì sửa món sẽ hỏng
    // đúng lúc khách đang đứng trước quầy.
    $rows = $this->actingAs($this->manager)
        ->getJson("{$this->shopBase}/menus/{$this->branchMenu->id}/products?sku_id={$this->branchMpSku->product_sku_id}")
        ->assertSuccessful()
        ->json('data.*.id');

    expect($rows)->toBe([(string) $this->branchMp->id]);
});
